<?php

namespace App\Console\Commands\ProductVault;

use App\Models\ProductIdentificationCandidate;
use App\Services\Documents\AssistedReview\AssistedReviewMetadataBuilder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use InvalidArgumentException;

#[Signature('product-vault:refresh-assisted-review
    {--document= : Limita il refresh a un document_id}
    {--candidate= : Limita il refresh a un singolo candidate_id}
    {--after-id=0 : Processa soltanto candidati con id maggiore del valore indicato}
    {--limit=500 : Numero massimo di candidati da processare}
    {--dry-run : Mostra cosa verrebbe aggiornato senza scrivere nulla}')]
#[Description('Refresh controllato dei metadata assisted_review sui candidati prodotto esistenti')]
class RefreshAssistedReviewCommand extends Command
{
    /**
     * Crea il comando usando il builder deterministico Assisted Review.
     */
    public function __construct(
        private readonly AssistedReviewMetadataBuilder
            $assistedReviewMetadataBuilder
    ) {
        parent::__construct();
    }

    /**
     * Aggiorna esclusivamente il namespace metadata assisted_review.
     *
     * Il comando:
     * - non modifica brand_id, category_id o model;
     * - non cambia review_status;
     * - non crea Product;
     * - non elimina candidati;
     * - preserva decisioni utente ed estensioni metadata;
     * - salva solamente quando il payload è realmente cambiato.
     */
    public function handle(): int
    {
        try {
            $limit = $this->validatedLimitOption();

            $documentId = $this->positiveIntegerOption(
                'document'
            );

            $candidateId = $this->positiveIntegerOption(
                'candidate'
            );

            $afterId = $this->nonNegativeIntegerOption(
                'after-id'
            );
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        $query = ProductIdentificationCandidate::query()
            ->with(['brand', 'category'])
            ->orderBy('id')
            ->limit($limit);

        if ($documentId !== null) {
            $query->where('document_id', $documentId);
        }

        if ($candidateId !== null) {
            $query->where('id', $candidateId);
        }

        if ($afterId > 0) {
            $query->where('id', '>', $afterId);
        }

        $candidates = $query->get();

        if ($candidates->isEmpty()) {
            $this->warn(
                'Nessun candidato trovato per i filtri indicati.'
            );

            return self::SUCCESS;
        }

        $rows = [];
        $changed = 0;
        $unchanged = 0;
        $completionRequired = 0;

        foreach ($candidates as $candidate) {
            $metadataBefore = is_array($candidate->metadata)
                ? $candidate->metadata
                : [];

            $metadataAfter = $this->assistedReviewMetadataBuilder
                ->mergeIntoMetadata($candidate);

            $hasChanges = $this->metadataHasChanged(
                before: $metadataBefore,
                after: $metadataAfter,
            );

            if ($hasChanges) {
                $changed++;

                if (! $dryRun) {
                    /*
                     * Il comando aggiorna soltanto metadata e non deve
                     * attivare eventuali observer legati alla revisione.
                     */
                    $candidate->metadata = $metadataAfter;
                    $candidate->saveQuietly();
                }
            } else {
                $unchanged++;
            }

            $needsCompletion = (bool) data_get(
                $metadataAfter,
                'assisted_review.needs_user_completion',
                false
            );

            if ($needsCompletion) {
                $completionRequired++;
            }

            $rows[] = [
                $candidate->document_id,
                $candidate->id,
                Str::limit((string) $candidate->name, 38),
                $this->statusLabel(
                    hasChanges: $hasChanges,
                    dryRun: $dryRun
                ),
                $this->fieldSummary(
                    metadata: $metadataAfter,
                    fieldName: 'brand'
                ),
                $this->fieldSummary(
                    metadata: $metadataAfter,
                    fieldName: 'category'
                ),
                $this->fieldSummary(
                    metadata: $metadataAfter,
                    fieldName: 'model'
                ),
                $this->completionSummary($metadataAfter),
            ];
        }

        $this->table([
            'Doc',
            'Cand',
            'Nome',
            'Status',
            'Brand',
            'Categoria',
            'Modello',
            'Completion',
        ], $rows);

        $processed = $candidates->count();

        if ($dryRun) {
            $this->info(
                'Dry-run completato. '
                . "Candidati analizzati: {$processed}. "
                . "Da aggiornare: {$changed}. "
                . "Invariati: {$unchanged}. "
                . "Con completamento richiesto: {$completionRequired}. "
                . 'Nessun dato è stato modificato.'
            );
        } else {
            $this->info(
                'Refresh completato. '
                . "Candidati analizzati: {$processed}. "
                . "Aggiornati: {$changed}. "
                . "Invariati: {$unchanged}. "
                . "Con completamento richiesto: {$completionRequired}."
            );
        }

        /*
         * after-id consente di proseguire in modo deterministico quando il
         * numero complessivo di candidati supera il limite della singola run.
         */
        if (
            $processed === $limit
            && $candidateId === null
        ) {
            $lastCandidateId = (int) $candidates->last()->id;

            $this->line(
                'Per continuare dal candidato successivo usa: '
                . "--after-id={$lastCandidateId}"
            );
        }

        return self::SUCCESS;
    }

    /**
     * Verifica se due payload metadata sono semanticamente differenti.
     *
     * Le chiavi degli array associativi vengono ordinate ricorsivamente perché
     * il database può restituire gli oggetti JSON in un ordine diverso.
     * L'ordine degli array lista viene invece preservato perché significativo.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    private function metadataHasChanged(
        array $before,
        array $after
    ): bool {
        return $this->normalizeForComparison($before)
            !== $this->normalizeForComparison($after);
    }

    /**
     * Normalizza ricorsivamente un valore per il confronto semantico.
     */
    private function normalizeForComparison(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(
                fn (mixed $item): mixed => $this->normalizeForComparison(
                    $item
                ),
                $value
            );
        }

        ksort($value);

        foreach ($value as $key => $item) {
            $value[$key] = $this->normalizeForComparison($item);
        }

        return $value;
    }

    /**
     * Valida il numero massimo di candidati elaborabili.
     */
    private function validatedLimitOption(): int
    {
        $value = trim((string) $this->option('limit'));

        if (preg_match('/^[1-9]\d*$/', $value) !== 1) {
            throw new InvalidArgumentException(
                'L’opzione --limit deve essere un intero positivo.'
            );
        }

        return min((int) $value, 1000);
    }

    /**
     * Converte un’opzione facoltativa in un identificativo positivo.
     */
    private function positiveIntegerOption(string $name): ?int
    {
        $value = $this->option($name);

        if ($value === null || $value === '') {
            return null;
        }

        $normalized = trim((string) $value);

        if (preg_match('/^[1-9]\d*$/', $normalized) !== 1) {
            throw new InvalidArgumentException(
                "L’opzione --{$name} deve essere un intero positivo."
            );
        }

        return (int) $normalized;
    }

    /**
     * Valida un’opzione numerica che può assumere anche il valore zero.
     */
    private function nonNegativeIntegerOption(string $name): int
    {
        $value = trim((string) $this->option($name));

        if (preg_match('/^\d+$/', $value) !== 1) {
            throw new InvalidArgumentException(
                "L’opzione --{$name} deve essere un intero non negativo."
            );
        }

        return (int) $value;
    }

    /**
     * Restituisce lo stato operativo mostrato nella tabella.
     */
    private function statusLabel(
        bool $hasChanges,
        bool $dryRun
    ): string {
        if (! $hasChanges) {
            return 'UNCHANGED';
        }

        return $dryRun ? 'DRY' : 'UPDATED';
    }

    /**
     * Riassume stato e valore di un campo Assisted Review.
     *
     * @param  array<string, mixed>  $metadata
     */
    private function fieldSummary(
        array $metadata,
        string $fieldName
    ): string {
        $basePath = "assisted_review.fields.{$fieldName}";

        $state = $this->nullableString(
            data_get($metadata, "{$basePath}.state")
        );

        if ($state === null) {
            return '-';
        }

        $currentValue = $this->nullableString(
            data_get($metadata, "{$basePath}.current.value")
        );

        $suggestedValue = $this->nullableString(
            data_get($metadata, "{$basePath}.suggestion.value")
        );

        $value = $state === 'suggested'
            ? $suggestedValue
            : ($currentValue ?? $suggestedValue);

        if ($value === null) {
            return $state;
        }

        return Str::limit(
            "{$state}: {$value}",
            34
        );
    }

    /**
     * Restituisce i campi ancora da completare.
     *
     * @param  array<string, mixed>  $metadata
     */
    private function completionSummary(array $metadata): string
    {
        $fields = data_get(
            $metadata,
            'assisted_review.completion_fields',
            []
        );

        if (! is_array($fields) || $fields === []) {
            return '-';
        }

        return implode(', ', array_values(array_filter(
            $fields,
            fn (mixed $field): bool => is_string($field)
                && trim($field) !== ''
        )));
    }

    /**
     * Normalizza una stringa opzionale.
     */
    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }
}