<?php

namespace App\Console\Commands\ProductVault;

use App\Jobs\ProcessDocumentJob;
use App\Models\Document;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('product-vault:regression-documents {--keys= : Lista chiavi baseline separate da virgola} {--filenames= : Lista original_filename separati da virgola} {--strict-missing : Fallisce se una baseline non è presente nel DB locale}')]
#[Description('Run Product Vault document parsing regression on local test documents')]
class RunDocumentRegressionCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        /*
        |--------------------------------------------------------------------------
        | Baseline locale per documenti reali caricati nel DB
        |--------------------------------------------------------------------------
        |
        | Non usiamo più gli ID come verità, perché nel DB locale gli ID possono
        | cambiare o puntare a documenti diversi dopo reset, import o cancellazioni.
        |
        | La chiave stabile è il filename originale. L'ID viene usato solo per
        | debug/output dopo aver trovato il documento.
        |
        */
        $baselines = [
            'pv_smoke_01_invoice_ean_new_products' => [
                'filename' => 'PV_smoke_01_fattura_ean_nuovi_prodotti.pdf',
                'status' => 'needs_review',
                'text_extraction_status' => 'completed',
                'type' => 'invoice',
                'lines_count' => 3,
                'candidates_count' => 3,
                'expected_candidates' => [
                    [
                        'name_contains' => 'Monitor ViewMax Creator XR27 4K',
                    ],
                    [
                        'name_contains' => 'NAS TerraVault Home Duo 8TB',
                    ],
                    [
                        'name_contains' => 'Robot Aspirapolvere CasaBot MappaPro 900',
                    ],
                ],
            ],

            'pv_smoke_02_invoice_serials_new_products' => [
                'filename' => 'PV_smoke_02_fattura_seriali_nuovi_prodotti.pdf',
                'status' => 'needs_review',
                'text_extraction_status' => 'completed',
                'type' => 'invoice',
                'lines_count' => 3,
                'candidates_count' => 3,
                'expected_candidates' => [
                    [
                        'name_contains' => 'Fotocamera LumioShot Z5 Mirrorless',
                    ],
                    [
                        'name_contains' => 'Obiettivo LumioPrime 35mm F1.8',
                    ],
                    [
                        'name_contains' => 'Stabilizzatore Gimbal SteadyCam Mini 3',
                    ],
                ],
            ],

            'pv_smoke_03_order_confirmation_variants' => [
                'filename' => 'PV_smoke_03_conferma_ordine_varianti.pdf',
                'status' => 'needs_review',
                'text_extraction_status' => 'completed',
                'type' => 'order_confirmation',
                'lines_count' => 4,
                'candidates_count' => 4,
                'expected_candidates' => [
                    [
                        'name_contains' => 'Monitor View Max Creator XR 27 UHD',
                    ],
                    [
                        'name_contains' => 'TerraVault Home Duo NAS 8 TB',
                    ],
                    [
                        'name_contains' => 'CasaBot Mappa Pro 900 robot aspirapolvere',
                    ],
                    [
                        'name_contains' => 'Gimbal Steady Cam Mini III',
                    ],
                ],
            ],

            'pv_smoke_04_non_relevant_document' => [
                'filename' => 'PV_smoke_04_documento_non_pertinente.pdf',
                'text_extraction_status' => 'completed',
                'lines_count' => 0,
                'candidates_count' => 0,
            ],
        ];

        $keysOption = $this->option('keys');
        $filenamesOption = $this->option('filenames');

        $selectedBaselines = collect($baselines);

        if ($keysOption) {
            $keys = collect(explode(',', (string) $keysOption))
                ->map(fn (string $key): string => trim($key))
                ->filter()
                ->values();

            $selectedBaselines = $selectedBaselines->only($keys->all());
        }

        if ($filenamesOption) {
            $filenames = collect(explode(',', (string) $filenamesOption))
                ->map(fn (string $filename): string => trim($filename))
                ->filter()
                ->values()
                ->all();

            $selectedBaselines = $selectedBaselines->filter(
                fn (array $baseline): bool => in_array((string) ($baseline['filename'] ?? ''), $filenames, true)
            );
        }

        $rows = [];
        $failed = false;
        $executed = 0;
        $strictMissing = (bool) $this->option('strict-missing');

        $formatMoney = function ($value): ?string {
            if ($value === null || $value === '') {
                return null;
            }

            return number_format((float) $value, 2, '.', '');
        };

        $formatQuantity = function ($value): ?string {
            if ($value === null || $value === '') {
                return null;
            }

            return number_format((float) $value, 3, '.', '');
        };

        $formatCandidate = function ($candidate) use ($formatMoney): string {
            return sprintf(
                'name=%s, model=%s, ean=%s, serial=%s, price=%s',
                $candidate->name ?? 'null',
                $candidate->model ?? 'null',
                $candidate->ean_code ?? 'null',
                $candidate->serial_number ?? 'null',
                $formatMoney($candidate->price) ?? 'null'
            );
        };

        $candidateMatchesExpectation = function ($candidate, array $expectation) use ($formatMoney, $formatQuantity): bool {
            foreach ($expectation as $key => $expectedValue) {
                if ($key === 'name_contains') {
                    $actualName = mb_strtolower((string) $candidate->name);
                    $expectedNamePart = mb_strtolower((string) $expectedValue);

                    if (! str_contains($actualName, $expectedNamePart)) {
                        return false;
                    }

                    continue;
                }

                if ($key === 'price') {
                    if ($formatMoney($candidate->price) !== $formatMoney($expectedValue)) {
                        return false;
                    }

                    continue;
                }

                if ($key === 'quantity') {
                    $actualQuantity = $candidate->metadata['quantity'] ?? null;

                    if ($formatQuantity($actualQuantity) !== $formatQuantity($expectedValue)) {
                        return false;
                    }

                    continue;
                }

                if (in_array($key, ['line_parser', 'line_mode'], true)) {
                    $actualValue = $candidate->metadata[$key] ?? null;

                    if ((string) $actualValue !== (string) $expectedValue) {
                        return false;
                    }

                    continue;
                }

                $actualValue = $candidate->{$key} ?? null;

                if ($expectedValue === null) {
                    if ($actualValue !== null && $actualValue !== '') {
                        return false;
                    }

                    continue;
                }

                if ((string) $actualValue !== (string) $expectedValue) {
                    return false;
                }
            }

            return true;
        };

        foreach ($selectedBaselines as $key => $expected) {
            $filename = (string) ($expected['filename'] ?? '');

            $document = Document::query()
                ->where('original_filename', $filename)
                ->latest('id')
                ->first();

            if (! $document) {
                if ($strictMissing) {
                    $failed = true;
                }

                $rows[] = [
                    'key' => $key,
                    'id' => '-',
                    'file' => $filename,
                    'status' => $strictMissing ? 'FAIL' : 'SKIP',
                    'errors' => 'Documento non presente nel DB locale.',
                ];

                continue;
            }

            $executed++;

            try {
                ProcessDocumentJob::dispatchSync($document->id);

                $document = Document::query()
                    ->with(['documentType', 'merchant'])
                    ->findOrFail($document->id);

                /*
                |--------------------------------------------------------------------------
                | Candidati generati dalla pipeline
                |--------------------------------------------------------------------------
                |
                | Manteniamo il comportamento precedente: la regression verifica i
                | candidati pending non ancora collegati a un Product. Questo comando
                | serve soprattutto per controllare l'output appena generato dal parser.
                |
                */
                $actualCandidates = $document->productIdentificationCandidates()
                    ->where('review_status', 'pending')
                    ->whereNull('product_id')
                    ->orderBy('id')
                    ->get();

                $actual = [
                    'filename' => $document->original_filename,
                    'status' => $document->status,
                    'text_extraction_status' => $document->text_extraction_status,
                    'type' => $document->documentType?->code,
                    'merchant' => $document->merchant?->name,
                    'purchase_date' => $document->purchase_date?->toDateString(),
                    'total_amount' => $document->total_amount !== null
                        ? number_format((float) $document->total_amount, 2, '.', '')
                        : null,
                    'lines_count' => $document->lines()->count(),
                    'candidates_count' => $actualCandidates->count(),
                ];

                $errors = [];

                foreach ($expected as $expectedKey => $expectedValue) {
                    if ($expectedKey === 'expected_candidates') {
                        continue;
                    }

                    $actualValue = $actual[$expectedKey] ?? null;

                    if ((string) $actualValue !== (string) $expectedValue) {
                        $errors[] = "{$expectedKey}: expected [{$expectedValue}], got [{$actualValue}]";
                    }
                }

                $expectedCandidates = $expected['expected_candidates'] ?? [];

                if ($expectedCandidates !== []) {
                    $unmatchedCandidates = $actualCandidates->values();

                    foreach ($expectedCandidates as $candidateIndex => $expectedCandidate) {
                        $matchedIndex = $unmatchedCandidates->search(
                            fn ($candidate): bool => $candidateMatchesExpectation($candidate, $expectedCandidate)
                        );

                        if ($matchedIndex === false) {
                            $actualCandidateSummary = $actualCandidates
                                ->map(fn ($candidate): string => $formatCandidate($candidate))
                                ->implode(' || ');

                            $errors[] = 'expected_candidate #' . ($candidateIndex + 1)
                                . ' not found: ' . json_encode($expectedCandidate, JSON_UNESCAPED_UNICODE)
                                . ' | actual candidates: ' . ($actualCandidateSummary !== '' ? $actualCandidateSummary : 'none');

                            continue;
                        }

                        $unmatchedCandidates->forget($matchedIndex);
                        $unmatchedCandidates = $unmatchedCandidates->values();
                    }
                }

                if ($errors !== []) {
                    $failed = true;
                }

                $rows[] = [
                    'key' => $key,
                    'id' => $document->id,
                    'file' => $document->original_filename,
                    'status' => $errors === [] ? 'OK' : 'FAIL',
                    'errors' => $errors === [] ? '-' : implode(' | ', $errors),
                ];
            } catch (Throwable $exception) {
                $failed = true;

                $rows[] = [
                    'key' => $key,
                    'id' => $document->id ?? '-',
                    'file' => $filename,
                    'status' => 'ERROR',
                    'errors' => $exception->getMessage(),
                ];
            }
        }

        $this->table(
            ['Key', 'ID', 'File', 'Status', 'Errors'],
            $rows
        );

        if ($executed === 0) {
            $this->error('Nessuna baseline eseguita: i documenti attesi non sono presenti nel DB locale.');

            return self::FAILURE;
        }

        if ($failed) {
            $this->error('Document regression failed.');

            return self::FAILURE;
        }

        $this->info('Document regression passed.');

        return self::SUCCESS;
    }
}