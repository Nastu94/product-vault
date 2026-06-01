<?php

use App\Jobs\ProcessDocumentJob;
use App\Models\Document;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Product Vault document regression
|--------------------------------------------------------------------------
|
| Comando di supporto sviluppo per rilanciare la pipeline sui documenti
| canonici usati nei test manuali OCR/parsing/candidati.
|
| Non crea fixture e non carica nuovi file: usa documenti già presenti nel DB
| locale di sviluppo.
|
*/
Artisan::command('product-vault:regression-documents {--ids= : Lista ID separati da virgola}', function () {
    $expected = [
        12 => [
            'filename' => 'test_001_scontrino_elettronica_digitale.pdf',
            'status' => 'needs_review',
            'text_extraction_status' => 'completed',
            'type' => 'receipt',
            'merchant' => 'TECHMARKET ROMA SRL',
            'purchase_date' => '2026-05-29',
            'total_amount' => '752.70',
            'lines_count' => 3,
            'candidates_count' => 3,
        ],
        15 => [
            'filename' => 'test_001_scontrino_elettronica_scan_ocr.pdf',
            'status' => 'needs_review',
            'text_extraction_status' => 'completed',
            'type' => 'receipt',
            'merchant' => 'TECHMARKET ROMA SRL',
            'purchase_date' => '2026-05-29',
            'total_amount' => '752.70',
            'lines_count' => 3,
            'candidates_count' => 3,
        ],
        16 => [
            'filename' => 'test_001_scontrino_elettronica_ocr.png',
            'status' => 'needs_review',
            'text_extraction_status' => 'completed',
            'type' => 'receipt',
            'merchant' => 'TECHMARKET ROMA SRL',
            'purchase_date' => '2026-05-29',
            'total_amount' => '752.70',
            'lines_count' => 3,
            'candidates_count' => 3,
        ],
        17 => [
            'filename' => 'lo-scontrino-fiscale.jpg',
            'status' => 'parsed',
            'text_extraction_status' => 'completed',
            'type' => 'receipt',
            'merchant' => 'Trattoria I Gabbiano',
            'purchase_date' => '2016-02-06',
            'total_amount' => '69.00',
            'lines_count' => 9,
            'candidates_count' => 0,
        ],
        18 => [
            'filename' => 'test_002A_fattura_mista_multicategoria_digitale.pdf',
            'status' => 'needs_review',
            'text_extraction_status' => 'completed',
            'type' => 'invoice',
            'merchant' => 'ALFA MULTISTORE SRL',
            'purchase_date' => '2026-05-30',
            'total_amount' => '597.99',
            'lines_count' => 7,
            'candidates_count' => 3,
        ],
        19 => [
            'filename' => 'test_002A_fattura_mista_multicategoria_ocr_foto.jpg',
            'status' => 'needs_review',
            'text_extraction_status' => 'completed',
            'type' => 'invoice',
            'merchant' => 'ALFA MULTISTORE SRL',
            'purchase_date' => '2026-05-30',
            'total_amount' => '597.99',
            'lines_count' => 7,
            'candidates_count' => 3,
        ],
        20 => [
            'filename' => 'test_002A_fattura_mista_multicategoria_scan_ocr.pdf',
            'status' => 'needs_review',
            'text_extraction_status' => 'completed',
            'type' => 'invoice',
            'merchant' => 'ALFA MULTISTORE SRL',
            'purchase_date' => '2026-05-30',
            'total_amount' => '597.99',
            'lines_count' => 7,
            'candidates_count' => 3,
        ],
        21 => [
            'filename' => 'test_002B_fattura_compatta_layout_alternativo_digitale.pdf',
            'status' => 'needs_review',
            'text_extraction_status' => 'completed',
            'type' => 'invoice',
            'merchant' => 'RITOCLEAN & TECH TEST SNC',
            'purchase_date' => '2026-05-31',
            'total_amount' => '439.98',
            'lines_count' => 4,
            'candidates_count' => 1,
        ],
        22 => [
            'filename' => 'test_002B_fattura_compatta_layout_alternativo_ocr_foto.jpg',
            'status' => 'needs_review',
            'text_extraction_status' => 'completed',
            'type' => 'invoice',
            'merchant' => 'RITOCLEAN & TECH TEST SNC',
            'purchase_date' => '2026-05-31',
            'total_amount' => '439.98',
            'lines_count' => 3,
            'candidates_count' => 1,
        ],
        23 => [
            'filename' => 'test_002B_fattura_compatta_layout_alternativo_scan_ocr.pdf',
            'status' => 'needs_review',
            'text_extraction_status' => 'completed',
            'type' => 'invoice',
            'merchant' => 'RITOCLEAN & TECH TEST SNC',
            'purchase_date' => '2026-05-31',
            'total_amount' => '439.98',
            'lines_count' => 4,
            'candidates_count' => 1,
        ],
        24 => [
            'filename' => 'test_003A_scontrino_lungo_multicategoria_digitale.pdf',
            'status' => 'needs_review',
            'text_extraction_status' => 'completed',
            'type' => 'receipt',
            'merchant' => 'MARKET CASA & TECH S.R.L.',
            'purchase_date' => '2026-05-30',
            'total_amount' => '266.75',
            'lines_count' => 21,
            'candidates_count' => 6,
        ],
        25 => [
            'filename' => 'test_003A_scontrino_lungo_multicategoria_ocr.png',
            'status' => 'needs_review',
            'text_extraction_status' => 'completed',
            'type' => 'receipt',
            'merchant' => 'MARKET CASA & TECH S.R.L.',
            'purchase_date' => '2026-05-30',
            'total_amount' => '266.75',
            'lines_count' => 21,
            'candidates_count' => 6,
        ],
        26 => [
            'filename' => 'test_003A_scontrino_lungo_multicategoria_scan_ocr.pdf',
            'status' => 'needs_review',
            'text_extraction_status' => 'completed',
            'type' => 'receipt',
            'merchant' => 'MARKET CASA & TECH S.R.L.',
            'purchase_date' => '2026-05-30',
            'total_amount' => '266.75',
            'lines_count' => 21,
            'candidates_count' => 6,
        ],
        27 => [
            'filename' => 'test_003B_fattura_prodotto_spezzato_digitale.pdf',
            'status' => 'needs_review',
            'text_extraction_status' => 'completed',
            'type' => 'invoice',
            'merchant' => 'OFFICINA DIGITALE SHOP S.R.L.',
            'purchase_date' => '2026-05-31',
            'total_amount' => '2130.00',
            'lines_count' => 5,
            'candidates_count' => 2,
            'expected_candidates' => [
                [
                    'name_contains' => 'Notebook Lenovo ThinkPad X1 Carbon Gen 11',
                    'ean_code' => '0196388123456',
                    'serial_number' => 'PF4TEST0091',
                    'price' => '1499.00',
                ],
                [
                    'ean_code' => '8055555012222',
                    'price' => '119.00',
                ],
            ],
        ],
        28 => [
            'filename' => 'test_003B_fattura_prodotto_spezzato_ocr.png',
            'status' => 'needs_review',
            'text_extraction_status' => 'completed',
            'type' => 'invoice',
            'merchant' => 'OFFICINA DIGITALE SHOP S.R.L.',
            'purchase_date' => '2026-05-31',
            'total_amount' => '2130.00',
            'lines_count' => 5,
            'candidates_count' => 2,
            'expected_candidates' => [
                [
                    'name_contains' => 'Notebook Lenovo ThinkPad X1 Carbon Gen 11',
                    'ean_code' => '0196388123456',
                    'serial_number' => 'PF4TEST0091',
                    'price' => '1499.00',
                ],
                [
                    'ean_code' => '8055555012222',
                    'price' => '119.00',
                ],
            ],
        ],
        29 => [
            'filename' => 'test_003B_fattura_prodotto_spezzato_scan_ocr.pdf',
            'status' => 'needs_review',
            'text_extraction_status' => 'completed',
            'type' => 'invoice',
            'merchant' => 'OFFICINA DIGITALE SHOP S.R.L.',
            'purchase_date' => '2026-05-31',
            'total_amount' => '2130.00',
            'lines_count' => 5,
            'candidates_count' => 2,
            'expected_candidates' => [
                [
                    'name_contains' => 'Notebook Lenovo ThinkPad X1 Carbon Gen 11',
                    'ean_code' => '0196388123456',
                    'serial_number' => 'PF4TEST0091',
                    'price' => '1499.00',
                ],
                [
                    'ean_code' => '8055555012222',
                    'price' => '119.00',
                ],
            ],
        ],
        30 => [
            'filename' => 'ChatGPT Image 1 giu 2026, 19_19_37.png',
            'status' => 'needs_review',
            'text_extraction_status' => 'completed',
            'type' => 'order_confirmation',
            'merchant' => 'SHOPCASA24',
            'purchase_date' => '2026-05-31',
            'total_amount' => '277.79',
            'lines_count' => 3,
            'candidates_count' => 1,
            'expected_candidates' => [
                [
                    'name' => 'Robot Aspirapolvere SmartClean X200',
                    'model' => 'RVA-X200',
                    'ean_code' => '8057777001234',
                    'price' => '249.90',
                    'quantity' => '1.000',
                ],
            ],
        ],
    ];

    $idsOption = $this->option('ids');

    $ids = $idsOption
        ? collect(explode(',', $idsOption))
            ->map(fn (string $id): int => (int) trim($id))
            ->filter()
            ->values()
            ->all()
        : array_keys($expected);

    $rows = [];
    $failed = false;

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

    foreach ($ids as $id) {
        if (! isset($expected[$id])) {
            $failed = true;

            $rows[] = [
                'id' => $id,
                'file' => 'n/a',
                'status' => 'FAIL',
                'errors' => 'ID non presente nella baseline regressione.',
            ];

            continue;
        }

        $document = Document::query()->find($id);

        if (! $document) {
            $failed = true;

            $rows[] = [
                'id' => $id,
                'file' => $expected[$id]['filename'],
                'status' => 'FAIL',
                'errors' => 'Documento non trovato nel DB locale.',
            ];

            continue;
        }

        try {
            ProcessDocumentJob::dispatchSync($id);

            $document = Document::query()
                ->with(['documentType', 'merchant'])
                ->findOrFail($id);

            $actualCandidates = $document->productIdentificationCandidates()
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

            foreach ($expected[$id] as $key => $expectedValue) {
                if ($key === 'expected_candidates') {
                    continue;
                }

                $actualValue = $actual[$key] ?? null;

                if ((string) $actualValue !== (string) $expectedValue) {
                    $errors[] = "{$key}: expected [{$expectedValue}], got [{$actualValue}]";
                }
            }

            $expectedCandidates = $expected[$id]['expected_candidates'] ?? [];

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
                'id' => $id,
                'file' => $document->original_filename,
                'status' => $errors === [] ? 'OK' : 'FAIL',
                'errors' => $errors === [] ? '-' : implode(' | ', $errors),
            ];
        } catch (Throwable $exception) {
            $failed = true;

            $rows[] = [
                'id' => $id,
                'file' => $expected[$id]['filename'],
                'status' => 'ERROR',
                'errors' => $exception->getMessage(),
            ];
        }
    }

    $this->table(
        ['ID', 'File', 'Status', 'Errors'],
        $rows
    );

    if ($failed) {
        $this->error('Document regression failed.');

        return self::FAILURE;
    }

    $this->info('Document regression passed.');

    return self::SUCCESS;
})->purpose('Run Product Vault document parsing regression on local test documents');