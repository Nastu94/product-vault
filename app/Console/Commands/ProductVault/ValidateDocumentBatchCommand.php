<?php

namespace App\Console\Commands\ProductVault;

use App\Models\Document;
use App\Models\DocumentLine;
use App\Models\ProductIdentificationCandidate;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

#[Signature('product-vault:validate-document-batch
    {--expected=storage/app/testing/BATCH01_expected.json : Percorso JSON expected batch}
    {--filename= : Filtra per original_filename, anche parziale}
    {--document= : Limita validazione a un singolo document_id}
    {--strict : Valida anche campi documentali e dati di completion}
    {--show-candidates : Mostra dettaglio candidati effettivi per documento}
    {--fail-on-warnings : Restituisce failure solo per warning di recognition; i completion warning restano non bloccanti}')]
#[Description('Valida un batch documentale separando recognition quality e completion')]
class ValidateDocumentBatchCommand extends Command
{
    /**
     * Valida un batch di documenti rispetto a un file expected JSON.
     *
     * Il comando è read-only:
     * - non rilancia la pipeline;
     * - non modifica documenti;
     * - non modifica righe;
     * - non modifica candidati;
     * - non crea prodotti.
     */
    public function handle(): int
    {
        $expectedPath = $this->resolveExpectedPath((string) $this->option('expected'));

        if ($expectedPath === null) {
            $this->error('File expected non trovato.');

            $this->line('Percorso richiesto: ' . (string) $this->option('expected'));

            return self::FAILURE;
        }

        $expectedBatch = $this->loadExpectedBatch($expectedPath);

        if ($expectedBatch === null) {
            return self::FAILURE;
        }

        $filenameFilter = trim((string) ($this->option('filename') ?? ''));
        $documentId = $this->normalizedPositiveIntegerOption('document');
        $strict = (bool) $this->option('strict');
        $showCandidates = (bool) $this->option('show-candidates');
        $failOnWarnings = (bool) $this->option('fail-on-warnings');

        $expectedBatch = collect($expectedBatch)
            ->filter(fn (array $row): bool => $filenameFilter === ''
                || str_contains((string) ($row['filename'] ?? ''), $filenameFilter))
            ->values();

        if ($expectedBatch->isEmpty()) {
            $this->warn('Nessun expected trovato per i filtri indicati.');

            return self::SUCCESS;
        }

        $rows = [];
        $detailRows = [];
        $failed = false;
        $hasRecognitionWarnings = false;
        $hasCompletionWarnings = false;

        foreach ($expectedBatch as $expectedDocument) {
            $filename = (string) ($expectedDocument['filename'] ?? '');
            $expected = (array) ($expectedDocument['expected'] ?? []);

            $documentQuery = Document::query()
                ->with([
                    'documentType',
                    'merchant',
                    'lines.documentLineType',
                    'productIdentificationCandidates.brand',
                    'productIdentificationCandidates.category',
                    'productIdentificationCandidates.documentLine.documentLineType',
                ])
                ->where('original_filename', $filename)
                ->latest('id');

            if ($documentId !== null) {
                $documentQuery->whereKey($documentId);
            }

            $document = $documentQuery->first();

            if (! $document) {
                $failed = true;

                $rows[] = [
                    'file' => Str::limit($filename, 42),
                    'doc' => '-',
                    'type' => '-',
                    'lines' => '-',
                    'cand' => '-',
                    'matched' => '0/' . count((array) data_get($expected, 'expected_candidates', [])),
                    'excluded' => '-',
                    'recognition' => 'FAIL',
                    'recognition_issues' => 'document_not_found',
                    'completion' => '-',
                ];

                continue;
            }

            $result = $this->validateDocument(
                document: $document,
                expected: $expected,
                strict: $strict
            );

            if ($result['recognition_status'] === 'FAIL') {
                $failed = true;
            }

            if ($result['recognition_status'] === 'WARN') {
                $hasRecognitionWarnings = true;
            }

            if ($result['completion_warning_count'] > 0) {
                $hasCompletionWarnings = true;
            }

            $rows[] = [
                'file' => Str::limit($filename, 42),
                'doc' => $document->id,
                'type' => $result['type_label'],
                'lines' => $result['lines_label'],
                'cand' => $result['candidates_label'],
                'matched' => $result['matched_label'],
                'excluded' => $result['excluded_label'],
                'recognition' => $result['recognition_status'],
                'recognition_issues' => $result['recognition_messages_label'],
                'completion' => $result['completion_messages_label'],
            ];

            if ($showCandidates) {
                $detailRows = [
                    ...$detailRows,
                    ...$this->candidateDetailRows($document),
                ];
            }
        }

        $this->table([
            'File',
            'Doc',
            'Type',
            'Lines',
            'Cand',
            'Matched',
            'Excluded',
            'Recognition',
            'Recognition Issues',
            'Completion',
        ], $rows);

        $this->newLine();

        $this->line(
            '<comment>Recognition</comment>: errori e warning strutturali di classificazione, righe, candidati e importi.'
        );

        $this->line(
            '<comment>Completion</comment>: campi documentali o prodotto non bloccanti, mostrati solo in modalità strict.'
        );

        if ($showCandidates && $detailRows !== []) {
            $this->newLine();

            $this->table([
                'Doc',
                'Cand',
                'Review',
                'Name',
                'Brand',
                'Category',
                'Qty',
                'Unit',
                'Total',
                'Amount',
            ], $detailRows);
        }

        if ($failed) {
            $this->error('Batch recognition validation failed.');

            return self::FAILURE;
        }

        if ($hasRecognitionWarnings) {
            $message = 'Batch recognition validation completed with warnings.';

            if ($hasCompletionWarnings) {
                $message .= ' Sono presenti anche warning non bloccanti di completion.';
            }

            $message .= ' Nessun dato è stato modificato.';

            if ($failOnWarnings) {
                $this->error($message);

                return self::FAILURE;
            }

            $this->warn($message);

            return self::SUCCESS;
        }

        if ($hasCompletionWarnings) {
            $this->info(
                'Batch recognition validation passed. '
                . 'Sono presenti warning non bloccanti di completion. '
                . 'Nessun dato è stato modificato.'
            );

            return self::SUCCESS;
        }

        $this->info('Batch recognition validation passed. Nessun dato è stato modificato.');

        return self::SUCCESS;
    }

    /**
     * Valida un singolo documento rispetto agli expected.
     *
     * Gli errori e i warning di recognition possono modificare l'esito del
     * comando. I completion warning sono informativi e non bloccanti.
     *
     * @return array<string, mixed>
     */
    private function validateDocument(Document $document, array $expected, bool $strict): array
    {
        $recognitionErrors = [];
        $recognitionWarnings = [];
        $completionWarnings = [];

        $actualType = $document->documentType?->code;
        $expectedType = $expected['document_type'] ?? null;

        if ($expectedType !== null) {
            if ($expectedType === 'irrelevant') {
                if ($document->productIdentificationCandidates->count() > 0) {
                    $recognitionErrors[] = 'irrelevant_document_generated_candidates';
                }

                if ($this->productLines($document)->count() > 0) {
                    $recognitionErrors[] = 'irrelevant_document_generated_product_lines';
                }

                /*
                * Una classificazione diversa da irrelevant è una violazione
                * strutturale anche quando non ha ancora generato candidati.
                */
                if ($actualType !== 'irrelevant') {
                    $recognitionErrors[] = 'type expected irrelevant, got ' . ($actualType ?? '-');
                }
            } elseif ((string) $actualType !== (string) $expectedType) {
                $recognitionErrors[] = 'type expected ' . $expectedType . ', got ' . ($actualType ?? '-');
            }
        }

        if ($strict) {
            $this->validateStrictDocumentFields(
                document: $document,
                expected: $expected,
                recognitionWarnings: $recognitionWarnings,
                completionWarnings: $completionWarnings
            );
        }

        $productLines = $this->productLines($document);
        $actualCandidates = $document->productIdentificationCandidates->values();

        $expectedProductLinesCount = $expected['product_lines_count'] ?? null;

        if (
            $expectedProductLinesCount !== null
            && $productLines->count() !== (int) $expectedProductLinesCount
        ) {
            $recognitionErrors[] = 'product_lines_count expected '
                . $expectedProductLinesCount
                . ', got '
                . $productLines->count();
        }

        $expectedCandidatesCount = $expected['candidates_count'] ?? null;

        if (
            $expectedCandidatesCount !== null
            && $actualCandidates->count() !== (int) $expectedCandidatesCount
        ) {
            $recognitionErrors[] = 'candidates_count expected '
                . $expectedCandidatesCount
                . ', got '
                . $actualCandidates->count();
        }

        $candidateValidation = $this->validateExpectedCandidates(
            candidates: $actualCandidates,
            expectedCandidates: (array) ($expected['expected_candidates'] ?? []),
            strict: $strict
        );

        $recognitionErrors = [
            ...$recognitionErrors,
            ...$candidateValidation['recognition_errors'],
        ];

        $completionWarnings = [
            ...$completionWarnings,
            ...$candidateValidation['completion_warnings'],
        ];

        $excludedValidation = $this->validateShouldNotGenerate(
            candidates: $actualCandidates,
            shouldNotGenerate: (array) ($expected['should_not_generate'] ?? [])
        );

        $recognitionErrors = [
            ...$recognitionErrors,
            ...$excludedValidation['recognition_errors'],
        ];

        $recognitionStatus = $recognitionErrors !== []
            ? 'FAIL'
            : ($recognitionWarnings !== [] ? 'WARN' : 'OK');

        $recognitionMessages = array_values(array_unique([
            ...$recognitionErrors,
            ...$recognitionWarnings,
        ]));

        $completionMessages = array_values(array_unique($completionWarnings));

        return [
            'recognition_status' => $recognitionStatus,
            'recognition_error_count' => count($recognitionErrors),
            'recognition_warning_count' => count($recognitionWarnings),
            'completion_warning_count' => count($completionMessages),
            'type_label' => ($expectedType ?? '-') . '→' . ($actualType ?? '-'),
            'lines_label' => ($expectedProductLinesCount ?? '-') . '→' . $productLines->count(),
            'candidates_label' => ($expectedCandidatesCount ?? '-') . '→' . $actualCandidates->count(),
            'matched_label' => $candidateValidation['matched'] . '/' . $candidateValidation['expected'],
            'excluded_label' => $excludedValidation['violations'] . '/' . $excludedValidation['expected'],
            'recognition_messages_label' => $recognitionMessages === []
                ? '-'
                : Str::limit(implode(' | ', $recognitionMessages), 180),
            'completion_messages_label' => $completionMessages === []
                ? '-'
                : Str::limit(implode(' | ', $completionMessages), 180),
        ];
    }

    /**
     * Valida campi aggiuntivi richiesti dalla modalità strict.
     *
     * Il totale documento resta un warning di recognition perché una differenza
     * può indicare un problema di parsing degli importi. Merchant e data sono
     * dati completabili manualmente e restano non bloccanti.
     *
     * @param  array<int, string>  $recognitionWarnings
     * @param  array<int, string>  $completionWarnings
     */
    private function validateStrictDocumentFields(
        Document $document,
        array $expected,
        array &$recognitionWarnings,
        array &$completionWarnings
    ): void {
        if (array_key_exists('merchant', $expected)) {
            $expectedMerchant = $expected['merchant'];
            $actualMerchant = $document->merchant?->name;

            if (
                $expectedMerchant !== null
                && (string) $actualMerchant !== (string) $expectedMerchant
            ) {
                $completionWarnings[] = 'merchant expected '
                    . $expectedMerchant
                    . ', got '
                    . ($actualMerchant ?? '-');
            }
        }

        if (array_key_exists('purchase_date', $expected)) {
            $expectedPurchaseDate = $expected['purchase_date'];
            $actualPurchaseDate = $document->purchase_date?->toDateString();

            if (
                $expectedPurchaseDate !== null
                && (string) $actualPurchaseDate !== (string) $expectedPurchaseDate
            ) {
                $completionWarnings[] = 'purchase_date expected '
                    . $expectedPurchaseDate
                    . ', got '
                    . ($actualPurchaseDate ?? '-');
            }
        }

        if (array_key_exists('total_amount', $expected)) {
            $expectedTotalAmount = $this->formatMoney($expected['total_amount']);
            $actualTotalAmount = $this->formatMoney($document->total_amount);

            if ($expectedTotalAmount !== $actualTotalAmount) {
                $recognitionWarnings[] = 'total_amount expected '
                    . ($expectedTotalAmount ?? '-')
                    . ', got '
                    . ($actualTotalAmount ?? '-');
            }
        }
    }

    /**
     * Valida i candidati attesi.
     *
     * @param  Collection<int, ProductIdentificationCandidate>  $candidates
     * @param  array<int, array<string, mixed>>  $expectedCandidates
     * @return array{
     *     expected:int,
     *     matched:int,
     *     recognition_errors:array<int, string>,
     *     completion_warnings:array<int, string>
     * }
     */
    private function validateExpectedCandidates(
        Collection $candidates,
        array $expectedCandidates,
        bool $strict
    ): array {
        $recognitionErrors = [];
        $completionWarnings = [];
        $matched = 0;

        $unmatchedCandidates = $candidates->values();

        foreach ($expectedCandidates as $index => $expectedCandidate) {
            if (($expectedCandidate['should_generate_candidate'] ?? true) !== true) {
                continue;
            }

            $matchedIndex = $unmatchedCandidates->search(
                fn (ProductIdentificationCandidate $candidate): bool => $this->candidateNameMatches(
                    candidate: $candidate,
                    expectedCandidate: $expectedCandidate
                )
            );

            if ($matchedIndex === false) {
                $recognitionErrors[] = 'candidate #' . ($index + 1) . ' not found: ' . (string) (
                    $expectedCandidate['name_contains']
                    ?? $expectedCandidate['name']
                    ?? 'unknown'
                );

                continue;
            }

            /** @var ProductIdentificationCandidate $candidate */
            $candidate = $unmatchedCandidates->get($matchedIndex);

            $matched++;

            $this->validateMatchedCandidateFields(
                candidate: $candidate,
                expectedCandidate: $expectedCandidate,
                strict: $strict,
                recognitionErrors: $recognitionErrors,
                completionWarnings: $completionWarnings
            );

            $unmatchedCandidates->forget($matchedIndex);
            $unmatchedCandidates = $unmatchedCandidates->values();
        }

        return [
            'expected' => count(array_filter(
                $expectedCandidates,
                fn (array $expectedCandidate): bool => (
                    $expectedCandidate['should_generate_candidate'] ?? true
                ) === true
            )),
            'matched' => $matched,
            'recognition_errors' => $recognitionErrors,
            'completion_warnings' => $completionWarnings,
        ];
    }

    /**
     * Verifica nome candidato con name_contains o name.
     */
    private function candidateNameMatches(ProductIdentificationCandidate $candidate, array $expectedCandidate): bool
    {
        $actualName = $this->normalizeComparableText((string) $candidate->name);

        $needles = array_values(array_filter([
            $expectedCandidate['name_contains'] ?? null,
            $expectedCandidate['name'] ?? null,
        ]));

        foreach ($needles as $needle) {
            $needle = $this->normalizeComparableText((string) $needle);

            if ($needle !== '' && str_contains($actualName, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Valida i campi del candidato trovato.
     *
     * Quantità e importi appartengono al recognition contract. Brand,
     * categoria, EAN e seriale appartengono alla completion non bloccante.
     *
     * @param  array<int, string>  $recognitionErrors
     * @param  array<int, string>  $completionWarnings
     */
    private function validateMatchedCandidateFields(
        ProductIdentificationCandidate $candidate,
        array $expectedCandidate,
        bool $strict,
        array &$recognitionErrors,
        array &$completionWarnings
    ): void {
        $candidateLabel = Str::limit((string) $candidate->name, 48);

        foreach ([
            'quantity' => 3,
            'unit_price' => 2,
            'total_price' => 2,
        ] as $field => $decimals) {
            if (! array_key_exists($field, $expectedCandidate)) {
                continue;
            }

            $expectedValue = $this->formatDecimal(
                $expectedCandidate[$field],
                $decimals
            );

            $actualValue = $this->formatDecimal(
                data_get($candidate->metadata, $field),
                $decimals
            );

            if ($expectedValue !== $actualValue) {
                $recognitionErrors[] = $candidateLabel
                    . ' '
                    . $field
                    . ' expected '
                    . ($expectedValue ?? '-')
                    . ', got '
                    . ($actualValue ?? '-');
            }
        }

        if (
            ($expectedCandidate['amount_consistency'] ?? null) === 'consistent'
            && data_get(
                $candidate->metadata,
                'document_line_amount_consistency.checked'
            ) === true
            && data_get(
                $candidate->metadata,
                'document_line_amount_consistency.is_consistent'
            ) !== true
        ) {
            $recognitionErrors[] = $candidateLabel
                . ' amount_consistency expected consistent';
        }

        if (! $strict) {
            return;
        }

        if (
            array_key_exists('brand', $expectedCandidate)
            && $expectedCandidate['brand'] !== null
        ) {
            $actualBrand = $candidate->brand?->name;

            if (
                $actualBrand === null
                || ! str_contains(
                    $this->normalizeComparableText($actualBrand),
                    $this->normalizeComparableText(
                        (string) $expectedCandidate['brand']
                    )
                )
            ) {
                $completionWarnings[] = $candidateLabel
                    . ' brand expected '
                    . $expectedCandidate['brand']
                    . ', got '
                    . ($actualBrand ?? '-');
            }
        }

        if (
            array_key_exists('category', $expectedCandidate)
            && $expectedCandidate['category'] !== null
        ) {
            $actualCategory = $candidate->category?->slug;

            if ($actualCategory === null) {
                $completionWarnings[] = $candidateLabel
                    . ' category expected '
                    . $expectedCandidate['category']
                    . ', got -';
            }
        }

        foreach (['ean_code', 'serial_number'] as $field) {
            if (
                ! array_key_exists($field, $expectedCandidate)
                || $expectedCandidate[$field] === null
            ) {
                continue;
            }

            $actualValue = $candidate->{$field} ?? null;

            if ((string) $actualValue !== (string) $expectedCandidate[$field]) {
                $completionWarnings[] = $candidateLabel
                    . ' '
                    . $field
                    . ' expected '
                    . $expectedCandidate[$field]
                    . ', got '
                    . ($actualValue ?? '-');
            }
        }
    }

    /**
     * Verifica che le righe da non generare non abbiano prodotto candidati.
     *
     * @param  Collection<int, ProductIdentificationCandidate>  $candidates
     * @param  array<int, array<string, mixed>>  $shouldNotGenerate
     * @return array{
     *     expected:int,
     *     violations:int,
     *     recognition_errors:array<int, string>
     * }
     */
    private function validateShouldNotGenerate(
        Collection $candidates,
        array $shouldNotGenerate
    ): array {
        $recognitionErrors = [];
        $violations = 0;

        foreach ($shouldNotGenerate as $excluded) {
            $text = trim((string) ($excluded['text'] ?? ''));

            if ($text === '') {
                continue;
            }

            $normalizedText = $this->normalizeComparableText($text);

            $matchedCandidate = $candidates->first(
                function (
                    ProductIdentificationCandidate $candidate
                ) use ($normalizedText): bool {
                    $haystacks = [
                        (string) $candidate->name,
                        (string) data_get(
                            $candidate->metadata,
                            'raw_line_text',
                            ''
                        ),
                        (string) $candidate->documentLine?->description,
                        (string) $candidate->documentLine?->raw_text,
                    ];

                    foreach ($haystacks as $haystack) {
                        if (
                            str_contains(
                                $this->normalizeComparableText($haystack),
                                $normalizedText
                            )
                        ) {
                            return true;
                        }
                    }

                    return false;
                }
            );

            if ($matchedCandidate) {
                $violations++;

                $recognitionErrors[] = 'should_not_generate violation: ' . $text;
            }
        }

        return [
            'expected' => count($shouldNotGenerate),
            'violations' => $violations,
            'recognition_errors' => $recognitionErrors,
        ];
    }

    /**
     * Righe documento classificate come product.
     *
     * @return Collection<int, DocumentLine>
     */
    private function productLines(Document $document): Collection
    {
        return $document->lines
            ->filter(fn (DocumentLine $line): bool => $line->documentLineType?->code === 'product')
            ->values();
    }

    /**
     * Righe dettaglio candidati effettivi.
     *
     * @return array<int, array<int, string|int|null>>
     */
    private function candidateDetailRows(Document $document): array
    {
        return $document->productIdentificationCandidates
            ->sortBy('id')
            ->map(fn (ProductIdentificationCandidate $candidate): array => [
                $document->id,
                $candidate->id,
                $candidate->review_status,
                Str::limit((string) $candidate->name, 44),
                $candidate->brand?->name ?? '-',
                $candidate->category?->slug ?? '-',
                $this->formatDecimal(data_get($candidate->metadata, 'quantity'), 3) ?? '-',
                $this->formatMoney(data_get($candidate->metadata, 'unit_price')) ?? '-',
                $this->formatMoney(data_get($candidate->metadata, 'total_price')) ?? '-',
                $this->candidateAmountLabel($candidate),
            ])
            ->values()
            ->all();
    }

    /**
     * Etichetta sintetica amount consistency candidato.
     */
    private function candidateAmountLabel(ProductIdentificationCandidate $candidate): string
    {
        $amountConsistency = (array) data_get(
            $candidate->metadata,
            'document_line_amount_consistency',
            []
        );

        if ($amountConsistency === []) {
            return '-';
        }

        if (! (bool) data_get($amountConsistency, 'checked', false)) {
            return 'SKIP:' . (string) data_get($amountConsistency, 'reason', '-');
        }

        return data_get($amountConsistency, 'is_consistent') === true
            ? 'OK'
            : 'MIS:' . (string) data_get($amountConsistency, 'reason', '-');
    }

    /**
     * Carica e valida il file JSON expected.
     *
     * @return array<int, array<string, mixed>>|null
     */
    private function loadExpectedBatch(string $path): ?array
    {
        $contents = file_get_contents($path);

        if ($contents === false) {
            $this->error('Impossibile leggere il file expected: ' . $path);

            return null;
        }

        $decoded = json_decode($contents, true);

        if (! is_array($decoded)) {
            $this->error('Il file expected non contiene JSON valido.');

            return null;
        }

        foreach ($decoded as $index => $row) {
            if (! is_array($row) || empty($row['filename']) || ! isset($row['expected']) || ! is_array($row['expected'])) {
                $this->error('Formato expected non valido alla riga indice ' . $index . '.');

                return null;
            }
        }

        return $decoded;
    }

    /**
     * Risolve path expected da path assoluto o relativo.
     */
    private function resolveExpectedPath(string $path): ?string
    {
        $path = trim($path);

        if ($path === '') {
            return null;
        }

        $candidates = [
            $path,
            base_path($path),
            storage_path($path),
            storage_path('app/' . $path),
        ];

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
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
     * Format uniforme importi.
     */
    private function formatMoney(mixed $value): ?string
    {
        return $this->formatDecimal($value, 2);
    }

    /**
     * Format uniforme numerico.
     */
    private function formatDecimal(mixed $value, int $decimals): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            return null;
        }

        return number_format((float) $value, $decimals, '.', '');
    }

    /**
     * Normalizza stringhe per confronti robusti ma leggibili.
     */
    private function normalizeComparableText(string $value): string
    {
        $value = mb_strtolower($value);
        $value = str_replace(['-', '_', '/', '\\', '.', ',', ':', ';', '€'], ' ', $value);
        $value = preg_replace('/\s+/u', ' ', $value) ?: $value;

        return trim($value);
    }
}