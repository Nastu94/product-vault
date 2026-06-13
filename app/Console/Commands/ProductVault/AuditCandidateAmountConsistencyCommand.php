<?php

namespace App\Console\Commands\ProductVault;

use App\Models\ProductIdentificationCandidate;
use App\Services\Documents\DocumentLines\DocumentLineAmountConsistencyChecker;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

#[Signature('product-vault:audit-candidate-amounts
    {--document= : Limita audit a un singolo document_id}
    {--candidate= : Limita audit a un singolo product_identification_candidate_id}
    {--review-status= : Filtra per review_status: pending, confirmed, ignored}
    {--limit=50 : Numero massimo di candidati da mostrare}
    {--only-mismatch : Mostra solo candidati derivati da righe con importi non coerenti}
    {--only-checked : Mostra solo candidati con diagnostica importi effettivamente controllata}
    {--live-check : Ricalcola in memoria la diagnostica quando manca il metadata salvato sul candidato}')]
#[Description('Audit read-only della coerenza importi copiata nei metadata dei candidati prodotto')]
class AuditCandidateAmountConsistencyCommand extends Command
{
    /**
     * Mostra la diagnostica importi salvata sui candidati prodotto.
     *
     * Il comando è solo diagnostico:
     * - non ricalcola importi;
     * - non modifica candidati;
     * - non modifica righe documento;
     * - non cambia score o review_status.
     */
    public function handle(DocumentLineAmountConsistencyChecker $checker): int
    {
        $limit = $this->normalizedLimit();

        $documentId = $this->normalizedPositiveIntegerOption('document');
        $candidateId = $this->normalizedPositiveIntegerOption('candidate');

        $reviewStatus = $this->normalizedReviewStatus();

        if ($reviewStatus === false) {
            return self::FAILURE;
        }

        $onlyMismatch = (bool) $this->option('only-mismatch');
        $onlyChecked = (bool) $this->option('only-checked');
        $liveCheck = (bool) $this->option('live-check');

        $query = ProductIdentificationCandidate::query()
            ->with([
                'document',
                'documentLine.documentLineType',
            ])
            ->orderByDesc('id');

        if ($documentId !== null) {
            $query->where('document_id', $documentId);
        }

        if ($candidateId !== null) {
            $query->whereKey($candidateId);
        }

        if (is_string($reviewStatus)) {
            $query->where('review_status', $reviewStatus);
        }

        /*
        |--------------------------------------------------------------------------
        | Fetch prudente
        |--------------------------------------------------------------------------
        |
        | I filtri --only-mismatch e --only-checked dipendono dai metadata JSON
        | salvati sul candidato. Li applichiamo in memoria per evitare query JSON
        | fragili tra database diversi.
        |
        */
        $requiresInMemoryFiltering = $onlyMismatch || $onlyChecked;
        $fetchLimit = $requiresInMemoryFiltering
            ? min($limit * 10, 1000)
            : $limit;

        $candidates = $query
            ->limit($fetchLimit)
            ->get();

        if ($onlyChecked) {
            $candidates = $candidates
                ->filter(fn (ProductIdentificationCandidate $candidate): bool => $this->candidateAmountChecked(
                    candidate: $candidate,
                    checker: $checker,
                    liveCheck: $liveCheck,
                ));
        }

        if ($onlyMismatch) {
            $candidates = $candidates
                ->filter(fn (ProductIdentificationCandidate $candidate): bool => $this->candidateAmountMismatch(
                    candidate: $candidate,
                    checker: $checker,
                    liveCheck: $liveCheck,
                ));
        }

        $candidates = $candidates
            ->take($limit)
            ->values();

        if ($candidates->isEmpty()) {
            $this->warn('Nessun candidato trovato per i filtri indicati.');

            return self::SUCCESS;
        }

        $this->table([
            'Doc',
            'Cand',
            'Line',
            'Review',
            'Nome',
            'Price',
            'Qty',
            'Unit',
            'Total',
            'Expected',
            'Delta',
            'Source',
            'Check',
            'Reason',
            'Signals',
        ], $candidates
            ->map(fn (ProductIdentificationCandidate $candidate): array => $this->candidateToTableRow(
                candidate: $candidate,
                checker: $checker,
                liveCheck: $liveCheck,
            ))
            ->all());

        $this->info('Audit importi candidati completato. Nessun dato è stato modificato.');

        return self::SUCCESS;
    }

    /**
     * Normalizza il limite massimo di candidati mostrati.
     */
    private function normalizedLimit(): int
    {
        $limit = (int) $this->option('limit');

        return $limit > 0 ? min($limit, 500) : 50;
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
     * Valida il filtro review_status.
     *
     * @return string|false|null
     */
    private function normalizedReviewStatus(): string|false|null
    {
        $value = $this->option('review-status');

        if ($value === null || $value === '') {
            return null;
        }

        $value = (string) $value;

        if (! in_array($value, ['pending', 'confirmed', 'ignored'], true)) {
            $this->error('Il valore di --review-status deve essere: pending, confirmed oppure ignored.');

            return false;
        }

        return $value;
    }

    /**
     * Verifica se il candidato ha diagnostica importi controllata.
     */
    private function candidateAmountChecked(
        ProductIdentificationCandidate $candidate,
        DocumentLineAmountConsistencyChecker $checker,
        bool $liveCheck
    ): bool {
        return (bool) data_get(
            $this->amountConsistencyForCandidate($candidate, $checker, $liveCheck),
            'checked',
            false
        );
    }

    /**
     * Verifica se il candidato deriva da una riga con mismatch importi.
     */
    private function candidateAmountMismatch(
        ProductIdentificationCandidate $candidate,
        DocumentLineAmountConsistencyChecker $checker,
        bool $liveCheck
    ): bool {
        $amountConsistency = $this->amountConsistencyForCandidate($candidate, $checker, $liveCheck);

        return (bool) data_get($amountConsistency, 'checked', false)
            && data_get($amountConsistency, 'is_consistent') === false;
    }

    /**
     * Converte un candidato in una riga tabellare leggibile.
     *
     * @return array<int, string|int|null>
     */
    private function candidateToTableRow(
        ProductIdentificationCandidate $candidate,
        DocumentLineAmountConsistencyChecker $checker,
        bool $liveCheck
    ): array
    {
        $amountConsistency = $this->amountConsistencyForCandidate($candidate, $checker, $liveCheck);

        return [
            $candidate->document_id,
            $candidate->id,
            $candidate->document_line_id,
            $candidate->review_status,
            Str::limit((string) $candidate->name, 42),
            $this->formatDecimal($candidate->price),
            $this->formatDecimal(data_get($candidate->metadata, 'quantity'), 3),
            $this->formatDecimal(data_get($candidate->metadata, 'unit_price')),
            $this->formatDecimal(data_get($candidate->metadata, 'total_price')),
            $this->formatDecimal(data_get($amountConsistency, 'expected_total')),
            $this->formatDecimal(data_get($amountConsistency, 'delta')),
            $this->sourceLabel($amountConsistency),
            $this->statusLabel($amountConsistency),
            (string) data_get($amountConsistency, 'reason', '-'),
            $this->signalsLabel((array) data_get($amountConsistency, 'signals', [])),
        ];
    }

    /**
     * Restituisce la diagnostica salvata oppure, se richiesto, la ricalcola in memoria.
     *
     * Il live-check è read-only: non salva metadata e non modifica il candidato.
     *
     * @return array<string, mixed>
     */
    private function amountConsistencyForCandidate(
        ProductIdentificationCandidate $candidate,
        DocumentLineAmountConsistencyChecker $checker,
        bool $liveCheck
    ): array {
        $stored = (array) data_get(
            $candidate->metadata,
            'document_line_amount_consistency',
            []
        );

        if ($stored !== []) {
            return [
                'source' => 'metadata',
                ...$stored,
            ];
        }

        if (! $liveCheck) {
            return [];
        }

        $quantity = data_get($candidate->metadata, 'quantity');
        $unitPrice = data_get($candidate->metadata, 'unit_price');
        $totalPrice = data_get($candidate->metadata, 'total_price');

        if (
            ($quantity === null || $quantity === '')
            && ($unitPrice === null || $unitPrice === '')
            && ($totalPrice === null || $totalPrice === '')
        ) {
            return [];
        }

        return [
            'version' => 'candidate_amount_live_check_v1',
            'source' => 'live_check_not_persisted',
            ...$checker->check(
                quantity: $quantity,
                unitPrice: $unitPrice,
                totalPrice: $totalPrice,
            ),
        ];
    }

    /**
     * Indica se la diagnostica arriva dal metadata salvato o dal live-check.
     *
     * @param  array<string, mixed>  $amountConsistency
     */
    private function sourceLabel(array $amountConsistency): string
    {
        return match ($amountConsistency['source'] ?? null) {
            'metadata' => 'metadata',
            'live_check_not_persisted' => 'live',
            default => '-',
        };
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
     * @param  array<string, mixed>  $amountConsistency
     */
    private function statusLabel(array $amountConsistency): string
    {
        if ($amountConsistency === []) {
            return 'NO_METADATA';
        }

        if (! (bool) data_get($amountConsistency, 'checked', false)) {
            return 'SKIP';
        }

        return data_get($amountConsistency, 'is_consistent') === true
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