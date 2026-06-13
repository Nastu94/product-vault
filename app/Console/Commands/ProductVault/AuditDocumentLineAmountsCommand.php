<?php

namespace App\Console\Commands\ProductVault;

use App\Models\DocumentLine;
use App\Services\Documents\DocumentLines\DocumentLineAmountConsistencyChecker;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

#[Signature('product-vault:audit-document-line-amounts
    {--document= : Limita audit a un singolo document_id}
    {--line= : Limita audit a una singola document_line_id}
    {--limit=50 : Numero massimo di righe da mostrare}
    {--tolerance=0.02 : Tolleranza ammessa tra totale atteso e totale riga}
    {--only-mismatch : Mostra solo righe controllate e non coerenti}
    {--only-checked : Mostra solo righe con dati sufficienti per il controllo}
    {--include-non-product : Include anche righe non product}')]
#[Description('Audit read-only della coerenza quantity x unit_price = total_price sulle righe documento')]
class AuditDocumentLineAmountsCommand extends Command
{
    /**
     * Esegue un audit diagnostico sulle righe documento.
     *
     * Il comando è intenzionalmente read-only:
     * - non corregge quantità o importi;
     * - non modifica metadata;
     * - non modifica candidati prodotto;
     * - non cambia lo stato del documento.
     */
    public function handle(DocumentLineAmountConsistencyChecker $checker): int
    {
        $limit = $this->normalizedLimit();
        $tolerance = $this->normalizedTolerance();

        if ($tolerance === null) {
            return self::FAILURE;
        }

        $documentId = $this->normalizedPositiveIntegerOption('document');
        $lineId = $this->normalizedPositiveIntegerOption('line');

        $onlyMismatch = (bool) $this->option('only-mismatch');
        $onlyChecked = (bool) $this->option('only-checked');
        $includeNonProduct = (bool) $this->option('include-non-product');

        $query = DocumentLine::query()
            ->with([
                'document',
                'documentLineType',
            ])
            ->orderByDesc('id');

        if ($documentId !== null) {
            $query->where('document_id', $documentId);
        }

        if ($lineId !== null) {
            $query->whereKey($lineId);
        }

        if (! $includeNonProduct) {
            $query->whereHas(
                'documentLineType',
                fn ($query) => $query->where('code', 'product')
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Fetch prudente
        |--------------------------------------------------------------------------
        |
        | Alcuni filtri dipendono dal risultato del checker e quindi vengono
        | applicati in memoria. Recuperiamo qualche riga in più ma restiamo
        | entro un limite controllato per non rendere pesante l'audit.
        |
        */
        $requiresInMemoryFiltering = $onlyMismatch || $onlyChecked;
        $fetchLimit = $requiresInMemoryFiltering
            ? min($limit * 10, 1000)
            : $limit;

        $auditedRows = $query
            ->limit($fetchLimit)
            ->get()
            ->map(function (DocumentLine $line) use ($checker, $tolerance): array {
                return [
                    'line' => $line,
                    'result' => $checker->check(
                        quantity: $line->quantity,
                        unitPrice: $line->unit_price,
                        totalPrice: $line->total_price,
                        tolerance: $tolerance,
                    ),
                ];
            });

        if ($onlyChecked) {
            $auditedRows = $auditedRows
                ->filter(fn (array $row): bool => (bool) data_get($row, 'result.checked'));
        }

        if ($onlyMismatch) {
            $auditedRows = $auditedRows
                ->filter(fn (array $row): bool => (bool) data_get($row, 'result.checked')
                    && data_get($row, 'result.is_consistent') === false);
        }

        $auditedRows = $auditedRows
            ->take($limit)
            ->values();

        if ($auditedRows->isEmpty()) {
            $this->warn('Nessuna riga documento trovata per i filtri indicati.');

            return self::SUCCESS;
        }

        $this->table([
            'Doc',
            'Line',
            'Type',
            'Description',
            'Qty',
            'Unit',
            'Total',
            'Expected',
            'Delta',
            'Tol',
            'Status',
            'Reason',
            'Signals',
        ], $auditedRows
            ->map(fn (array $row): array => $this->toTableRow($row['line'], $row['result']))
            ->all());

        $this->info('Audit importi righe documento completato. Nessun dato è stato modificato.');

        return self::SUCCESS;
    }

    /**
     * Normalizza il limite massimo di righe mostrate.
     */
    private function normalizedLimit(): int
    {
        $limit = (int) $this->option('limit');

        return $limit > 0 ? min($limit, 500) : 50;
    }

    /**
     * Normalizza e valida la tolleranza.
     */
    private function normalizedTolerance(): ?float
    {
        $option = $this->option('tolerance');

        if (! is_numeric($option)) {
            $this->error('Il valore di --tolerance deve essere numerico, ad esempio 0.02.');

            return null;
        }

        $tolerance = (float) $option;

        if ($tolerance < 0 || $tolerance > 1) {
            $this->error('Il valore di --tolerance deve essere compreso tra 0 e 1.');

            return null;
        }

        return $tolerance;
    }

    /**
     * Restituisce una option intera positiva o null.
     */
    private function normalizedPositiveIntegerOption(string $name): ?int
    {
        $value = $this->option($name);

        if ($value === null || $value === '') {
            return null;
        }

        $value = (int) $value;

        return $value > 0 ? $value : null;
    }

    /**
     * Converte una riga documento e la diagnostica in una riga tabellare.
     *
     * @param  array<string, mixed>  $result
     * @return array<int, string|int|null>
     */
    private function toTableRow(DocumentLine $line, array $result): array
    {
        return [
            $line->document_id,
            $line->id,
            $line->documentLineType?->code ?? '-',
            Str::limit((string) ($line->description ?: $line->raw_text), 46),
            $this->formatDecimal($line->quantity, 3),
            $this->formatDecimal($line->unit_price),
            $this->formatDecimal($line->total_price),
            $this->formatDecimal($result['expected_total'] ?? null),
            $this->formatDecimal($result['delta'] ?? null),
            $this->formatDecimal($result['tolerance'] ?? null),
            $this->statusLabel($result),
            (string) ($result['reason'] ?? '-'),
            $this->signalsLabel((array) ($result['signals'] ?? [])),
        ];
    }

    /**
     * Format uniforme per quantità e importi.
     */
    private function formatDecimal(mixed $value, int $decimals = 2): string
    {
        if ($value === null || $value === '') {
            return '-';
        }

        if (! is_numeric($value)) {
            return '-';
        }

        return number_format((float) $value, $decimals, '.', '');
    }

    /**
     * Etichetta sintetica dello stato diagnostico.
     *
     * @param  array<string, mixed>  $result
     */
    private function statusLabel(array $result): string
    {
        if (! (bool) ($result['checked'] ?? false)) {
            return 'SKIP';
        }

        return ($result['is_consistent'] ?? null) === true
            ? 'OK'
            : 'MISMATCH';
    }

    /**
     * Mostra pochi segnali per mantenere leggibile la tabella.
     *
     * @param  array<int, string>  $signals
     */
    private function signalsLabel(array $signals): string
    {
        $signals = array_values(array_filter($signals));

        if ($signals === []) {
            return '-';
        }

        return collect($signals)
            ->take(3)
            ->implode(', ');
    }
}