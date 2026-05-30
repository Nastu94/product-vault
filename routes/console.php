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
                'candidates_count' => $document->productIdentificationCandidates()
                    ->whereNull('product_id')
                    ->count(),
            ];

            $errors = [];

            foreach ($expected[$id] as $key => $expectedValue) {
                $actualValue = $actual[$key] ?? null;

                if ((string) $actualValue !== (string) $expectedValue) {
                    $errors[] = "{$key}: expected [{$expectedValue}], got [{$actualValue}]";
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