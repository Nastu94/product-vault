<?php

use App\Jobs\ProcessDocumentJob;
use App\Models\Document;
use App\Models\DocumentLine;
use App\Models\Plan;
use App\Models\ProductIdentificationCandidate;
use App\Models\ProductUnderstandingFeedback;
use App\Models\ProductUnderstandingGlobalFact;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

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

/*
|--------------------------------------------------------------------------
| Product Understanding knowledge seed
|--------------------------------------------------------------------------
|
| Comando di sviluppo per ricreare una base controllata di conoscenza
| Product Understanding su database pulito.
|
| Non importa file, non usa storage e non crea documenti reali.
| Serve solo a testare feedback matcher, global facts e Python similarity.
|
*/
Artisan::command('product-vault:seed-understanding-knowledge', function () {
    DB::transaction(function () {
        $freePlan = Plan::query()
            ->where('code', 'free')
            ->first();

        $user = User::query()->updateOrCreate(
            ['email' => 'understanding@example.com'],
            [
                'name' => 'Product Understanding Test User',
                'password' => Hash::make('password'),
            ],
        );

        $team = Team::query()
            ->where('user_id', $user->id)
            ->where('name', 'Product Understanding Test Workspace')
            ->first();

        if (! $team) {
            $team = Team::forceCreate([
                'user_id' => $user->id,
                'name' => 'Product Understanding Test Workspace',
                'personal_team' => true,
                'plan_id' => $freePlan?->id,
            ]);
        } else {
            $team->forceFill([
                'personal_team' => true,
                'plan_id' => $freePlan?->id,
            ])->save();
        }

        $user->forceFill([
            'current_team_id' => $team->id,
        ])->save();

        /*
        |--------------------------------------------------------------------------
        | Global facts forti
        |--------------------------------------------------------------------------
        |
        | Solo EAN-based. Questa è conoscenza globale sintetica, privacy-safe e
        | controllata. Non deriva da documenti reali dell'utente.
        */
        $globalFacts = [
            [
                'fact_type' => 'ean',
                'fact_value' => '0196388123456',
                'canonical_name' => 'Notebook Lenovo ThinkPad X1 Carbon Gen 11',
                'suggested_category' => 'notebook',
                'suggested_line_type' => 'durable_product',
                'seen_count' => 2,
                'confirmed_count' => 2,
                'ignored_count' => 0,
                'global_registration_rate' => 100.00,
                'global_product_confidence_score' => 69,
                'canonical_name_counts' => [
                    'Notebook Lenovo ThinkPad X1 Carbon Gen 11' => 2,
                ],
                'category_counts' => [
                    'notebook' => 2,
                ],
                'line_type_counts' => [
                    'durable_product' => 2,
                ],
            ],
            [
                'fact_type' => 'ean',
                'fact_value' => '8055555012222',
                'canonical_name' => 'Docking Station USB-C Dual HDMI 4K',
                'suggested_category' => 'docking_station',
                'suggested_line_type' => 'accessory',
                'seen_count' => 2,
                'confirmed_count' => 1,
                'ignored_count' => 1,
                'global_registration_rate' => 50.00,
                'global_product_confidence_score' => 62,
                'canonical_name_counts' => [
                    'Docking Station USB-C Dual HDMI 4K' => 2,
                ],
                'category_counts' => [
                    'docking_station' => 2,
                ],
                'line_type_counts' => [
                    'accessory' => 2,
                ],
            ],
        ];

        foreach ($globalFacts as $fact) {
            ProductUnderstandingGlobalFact::query()->updateOrCreate(
                [
                    'fact_type' => $fact['fact_type'],
                    'fact_key' => hash('sha256', $fact['fact_value']),
                ],
                $fact + [
                    'metadata' => [
                        'seeded_by' => 'product-vault:seed-understanding-knowledge',
                        'purpose' => 'development_scenario_knowledge',
                    ],
                    'first_seen_at' => now(),
                    'last_seen_at' => now(),
                ],
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Feedback workspace controllato
        |--------------------------------------------------------------------------
        |
        | Simula revisioni utente già avvenute nello stesso workspace.
        | Non è conoscenza globale: è team-scoped.
        */
        $feedbackRows = [
            [
                'review_status' => 'confirmed',
                'candidate_name' => 'Notebook Lenovo ThinkPad X1 Carbon Gen 11',
                'final_product_name' => 'Notebook Lenovo ThinkPad X1 Carbon Gen 11',
                'line_description' => 'Notebook Lenovo ThinkPad X1 Carbon Gen 11',
                'normalized_line_description' => 'notebook lenovo thinkpad x1 carbon gen 11',
                'analyzer_line_type' => 'durable_product',
                'analyzer_suggested_category' => 'notebook',
                'registerable_score' => 84,
                'non_product_score' => 0,
            ],
            [
                'review_status' => 'confirmed',
                'candidate_name' => 'Docking Station USB-C Dual HDMI 4K',
                'final_product_name' => 'Docking Station USB-C Dual HDMI 4K',
                'line_description' => 'Docking Station USB-C Duat HOMI 4K',
                'normalized_line_description' => 'docking station usb c duat homi 4k',
                'analyzer_line_type' => 'accessory',
                'analyzer_suggested_category' => 'docking_station',
                'registerable_score' => 68,
                'non_product_score' => 0,
            ],
            [
                'review_status' => 'confirmed',
                'candidate_name' => 'Sony WH-1000XM5 cuffie wireless nero',
                'final_product_name' => 'Sony WH-1000XM5 cuffie wireless nero',
                'line_description' => 'Sony WH-1000XM5 cuffie wireless nero',
                'normalized_line_description' => 'sony wh 1000xm5 cuffie wireless nero',
                'analyzer_line_type' => 'durable_product',
                'analyzer_suggested_category' => 'audio_device',
                'registerable_score' => 84,
                'non_product_score' => 0,
            ],
            [
                'review_status' => 'confirmed',
                'candidate_name' => 'Sony WH1000XM5 wireless nero',
                'final_product_name' => 'Sony WH1000XM5 wireless nero',
                'line_description' => 'Sony WH1000XM5 wireless nero',
                'normalized_line_description' => 'sony wh1000xm5 wireless nero',
                'analyzer_line_type' => 'unknown',
                'analyzer_suggested_category' => null,
                'registerable_score' => 54,
                'non_product_score' => 0,
            ],
            [
                'review_status' => 'ignored',
                'ignored_reason' => 'not_worth_registering',
                'candidate_name' => 'ADATTATORE HDMI 4K USB-C',
                'final_product_name' => null,
                'line_description' => 'ADATTATORE HDMI 4K USB-C',
                'normalized_line_description' => 'adattatore hdmi 4k usb c',
                'analyzer_line_type' => 'accessory',
                'analyzer_suggested_category' => 'cable',
                'registerable_score' => 42,
                'non_product_score' => 0,
            ],
        ];

        ProductUnderstandingFeedback::query()
            ->where('team_id', $team->id)
            ->where('metadata->seeded_by', 'product-vault:seed-understanding-knowledge')
            ->delete();

        foreach ($feedbackRows as $row) {
            ProductUnderstandingFeedback::query()->create($row + [
                'team_id' => $team->id,
                'reviewed_by_user_id' => $user->id,
                'candidate_price' => null,
                'candidate_ean_code' => null,
                'raw_text_hash' => hash('sha256', $row['normalized_line_description']),
                'analyzer_version' => 'seeded_product_understanding_fixture_v1',
                'signals' => [],
                'negative_signals' => [],
                'warnings' => [],
                'score_breakdown' => [],
                'metadata' => [
                    'seeded_by' => 'product-vault:seed-understanding-knowledge',
                    'purpose' => 'development_scenario_knowledge',
                ],
                'reviewed_at' => now(),
            ]);
        }

        $this->info('Product Understanding knowledge seeded.');
        $this->table(
            ['Metric', 'Value'],
            [
                ['user_id', $user->id],
                ['team_id', $team->id],
                ['global_facts', ProductUnderstandingGlobalFact::count()],
                ['workspace_feedback', ProductUnderstandingFeedback::query()->where('team_id', $team->id)->count()],
            ],
        );
    });
})->purpose('Seed controlled Product Understanding knowledge for development scenarios');

/*
|--------------------------------------------------------------------------
| Product Understanding scenario runner
|--------------------------------------------------------------------------
|
| Comando di sviluppo per verificare automaticamente gli scenari principali
| di Product Understanding senza caricare PDF e senza usare storage.
|
| Richiede prima:
| php artisan product-vault:seed-understanding-knowledge
|
*/
Artisan::command('product-vault:run-understanding-scenarios', function () {
    $user = User::query()
        ->where('email', 'understanding@example.com')
        ->first();

    if (! $user || ! $user->current_team_id) {
        $this->error('Knowledge seed mancante. Esegui prima: php artisan product-vault:seed-understanding-knowledge');

        return 1;
    }

    $team = Team::query()->find($user->current_team_id);

    if (! $team) {
        $this->error('Team/workspace seed non trovato. Esegui prima: php artisan product-vault:seed-understanding-knowledge');

        return 1;
    }

    if (ProductUnderstandingGlobalFact::count() < 2 || ProductUnderstandingFeedback::query()->where('team_id', $team->id)->count() < 5) {
        $this->error('Knowledge incompleta. Esegui prima: php artisan product-vault:seed-understanding-knowledge');

        return 1;
    }

    /*
    |--------------------------------------------------------------------------
    | Cleanup scenari sintetici precedenti
    |--------------------------------------------------------------------------
    */
    DocumentLine::query()
        ->whereHas('document', fn ($query) => $query->where('original_filename', 'synthetic-understanding-scenario.txt'))
        ->delete();

    Document::withTrashed()
        ->where('original_filename', 'synthetic-understanding-scenario.txt')
        ->forceDelete();

    $document = Document::query()->create([
        'team_id' => $team->id,
        'uploaded_by_user_id' => $user->id,
        'status' => 'parsed',
        'text_extraction_status' => 'completed',
        'original_filename' => 'synthetic-understanding-scenario.txt',
        'mime_type' => 'text/plain',
        'file_size' => 0,
        'raw_text' => 'Synthetic Product Understanding scenario document.',
    ]);

    $line = DocumentLine::query()->create([
        'document_id' => $document->id,
        'line_number' => 1,
        'raw_text' => 'Synthetic Product Understanding test line.',
        'description' => 'Synthetic Product Understanding test line.',
        'quantity' => 1,
        'unit_price' => 0,
        'total_price' => 0,
        'confidence_score' => 100,
        'metadata' => [
            'synthetic' => true,
            'seeded_for' => 'product_understanding_scenarios',
        ],
    ]);

    $feedbackMatcher = app(App\Services\Documents\ProductUnderstanding\ProductUnderstandingFeedbackMatcher::class);
    $pythonAnalyzer = app(App\Services\Documents\ProductUnderstanding\ProductTextSimilarityAnalyzer::class);

    $failures = [];
    $rows = [];

    $pass = function (string $scenario, string $assertion) use (&$rows): void {
        $rows[] = [$scenario, $assertion, 'OK'];
    };

    $fail = function (string $scenario, string $assertion, mixed $expected, mixed $actual) use (&$rows, &$failures): void {
        $rows[] = [$scenario, $assertion, 'FAIL'];

        $failures[] = [
            'scenario' => $scenario,
            'assertion' => $assertion,
            'expected' => $expected,
            'actual' => $actual,
        ];
    };

    $assertEquals = function (string $scenario, string $assertion, mixed $expected, mixed $actual) use ($pass, $fail): void {
        $expected === $actual
            ? $pass($scenario, $assertion)
            : $fail($scenario, $assertion, $expected, $actual);
    };

    $assertContains = function (string $scenario, string $assertion, string $expected, array $actual) use ($pass, $fail): void {
        in_array($expected, $actual, true)
            ? $pass($scenario, $assertion)
            : $fail($scenario, $assertion, $expected, $actual);
    };

    $assertNotContains = function (string $scenario, string $assertion, string $notExpected, array $actual) use ($pass, $fail): void {
        ! in_array($notExpected, $actual, true)
            ? $pass($scenario, $assertion)
            : $fail($scenario, $assertion, 'not '.$notExpected, $actual);
    };

    $assertTrue = function (string $scenario, string $assertion, bool $actual) use ($pass, $fail): void {
        $actual
            ? $pass($scenario, $assertion)
            : $fail($scenario, $assertion, true, $actual);
    };

    $sonySameModel = $feedbackMatcher->match(
        line: $line,
        candidateName: 'Sony WH 1000 XM5 cuffie wireless nero',
        eanCode: null,
    );

    $sonyDifferentModel = $feedbackMatcher->match(
        line: $line,
        candidateName: 'Sony WH-1000XM4 cuffie wireless nero',
        eanCode: null,
    );

    $thinkPadDifferentGeneration = $feedbackMatcher->match(
        line: $line,
        candidateName: 'Notebook Lenovo ThinkPad X1 Carbon Gen 10',
        eanCode: null,
    );

    $pythonThinkPadDifferentGeneration = $pythonAnalyzer->analyze(
        candidateName: 'Notebook Lenovo ThinkPad X1 Carbon Gen 10',
        eanCode: null,
        globalFactContext: [],
        suggestedCategory: 'notebook',
        suggestedLineType: 'durable_product',
    );

    $pythonDockingSpecDifference = $pythonAnalyzer->analyze(
        candidateName: 'Docking Station USB-C HDMI 2 porte',
        eanCode: null,
        globalFactContext: [],
        suggestedCategory: 'docking_station',
        suggestedLineType: 'accessory',
    );

    $pythonDockingOcrVariant = $pythonAnalyzer->analyze(
        candidateName: 'Dock Station USB C Dual HDMl 4K',
        eanCode: null,
        globalFactContext: [],
        suggestedCategory: 'docking_station',
        suggestedLineType: 'accessory',
    );

    /*
    |--------------------------------------------------------------------------
    | Assertions feedback matcher
    |--------------------------------------------------------------------------
    */
    $assertEquals(
        'feedback_sony_same_model_split',
        'suggested_bias',
        'positive',
        data_get($sonySameModel, 'suggested_bias'),
    );

    $assertEquals(
        'feedback_sony_same_model_split',
        'review_hint',
        'similar_description_previously_confirmed',
        data_get($sonySameModel, 'review_hint'),
    );

    $assertTrue(
        'feedback_sony_same_model_split',
        'best_similarity >= 0.75',
        (float) data_get($sonySameModel, 'similar_description.best_similarity', 0) >= 0.75,
    );

    $assertEquals(
        'feedback_sony_different_model',
        'suggested_bias',
        'neutral',
        data_get($sonyDifferentModel, 'suggested_bias'),
    );

    $assertTrue(
        'feedback_sony_different_model',
        'best_similarity < 0.75',
        (float) data_get($sonyDifferentModel, 'similar_description.best_similarity', 0) < 0.75,
    );

    $assertEquals(
        'feedback_sony_different_model',
        'model_conflict',
        true,
        (bool) data_get($sonyDifferentModel, 'similar_description.matches.0.model_conflict'),
    );

    $assertEquals(
        'feedback_thinkpad_different_generation',
        'suggested_bias',
        'neutral',
        data_get($thinkPadDifferentGeneration, 'suggested_bias'),
    );

    $assertEquals(
        'feedback_thinkpad_different_generation',
        'model_conflict',
        true,
        (bool) data_get($thinkPadDifferentGeneration, 'similar_description.matches.0.model_conflict'),
    );

    /*
    |--------------------------------------------------------------------------
    | Assertions Python similarity
    |--------------------------------------------------------------------------
    */
    $assertContains(
        'python_thinkpad_different_generation',
        'signals contains candidate_name_similar_but_different_model',
        'candidate_name_similar_but_different_model',
        data_get($pythonThinkPadDifferentGeneration, 'signals', []),
    );

    $assertContains(
        'python_thinkpad_different_generation',
        'warnings contains high_similarity_but_model_conflict',
        'high_similarity_but_model_conflict',
        data_get($pythonThinkPadDifferentGeneration, 'warnings', []),
    );

    $assertNotContains(
        'python_thinkpad_different_generation',
        'signals does not contain candidate_name_probably_ocr_variant',
        'candidate_name_probably_ocr_variant',
        data_get($pythonThinkPadDifferentGeneration, 'signals', []),
    );

    $assertContains(
        'python_docking_spec_difference',
        'signals contains candidate_name_similar_but_spec_difference',
        'candidate_name_similar_but_spec_difference',
        data_get($pythonDockingSpecDifference, 'signals', []),
    );

    $assertContains(
        'python_docking_spec_difference',
        'warnings contains high_similarity_but_spec_difference',
        'high_similarity_but_spec_difference',
        data_get($pythonDockingSpecDifference, 'warnings', []),
    );

    $assertNotContains(
        'python_docking_spec_difference',
        'signals does not contain candidate_name_probably_ocr_variant',
        'candidate_name_probably_ocr_variant',
        data_get($pythonDockingSpecDifference, 'signals', []),
    );

    $assertContains(
        'python_docking_ocr_variant',
        'signals contains candidate_name_probably_ocr_variant',
        'candidate_name_probably_ocr_variant',
        data_get($pythonDockingOcrVariant, 'signals', []),
    );

    $assertEquals(
        'python_docking_ocr_variant',
        'warnings empty',
        [],
        data_get($pythonDockingOcrVariant, 'warnings', []),
    );

    $this->table(['Scenario', 'Assertion', 'Status'], $rows);

    if ($failures !== []) {
        $this->error('Product Understanding scenarios failed.');

        foreach ($failures as $failure) {
            $this->line('');
            $this->warn($failure['scenario'].' / '.$failure['assertion']);
            $this->line('Expected: '.json_encode($failure['expected'], JSON_UNESCAPED_UNICODE));
            $this->line('Actual:   '.json_encode($failure['actual'], JSON_UNESCAPED_UNICODE));
        }

        return 1;
    }

    $this->info('Product Understanding scenarios passed.');

    return 0;
})->purpose('Run controlled Product Understanding scenarios without uploading files');

/*
|--------------------------------------------------------------------------
| Product Understanding fixture runner
|--------------------------------------------------------------------------
|
| Esegue scenari Product Understanding definiti in fixture versionate.
|
*/
Artisan::command('product-vault:run-understanding-fixtures', function () {
    $fixturePath = base_path('tests/Fixtures/ProductUnderstanding/scenarios.php');

    if (! file_exists($fixturePath)) {
        $this->error('Fixture mancante: '.$fixturePath);

        return 1;
    }

    $fixtures = require $fixturePath;

    $user = User::query()
        ->where('email', 'understanding@example.com')
        ->first();

    if (! $user || ! $user->current_team_id) {
        $this->error('Knowledge seed mancante. Esegui prima: php artisan product-vault:seed-understanding-knowledge');

        return 1;
    }

    $team = Team::query()->find($user->current_team_id);

    if (! $team) {
        $this->error('Team/workspace seed non trovato. Esegui prima: php artisan product-vault:seed-understanding-knowledge');

        return 1;
    }

    if (
        ProductUnderstandingGlobalFact::count() < 2
        || ProductUnderstandingFeedback::query()->where('team_id', $team->id)->count() < 5
    ) {
        $this->error('Knowledge incompleta. Esegui prima: php artisan product-vault:seed-understanding-knowledge');

        return 1;
    }

    DocumentLine::query()
        ->whereHas('document', fn ($query) => $query->where('original_filename', 'synthetic-understanding-fixtures.txt'))
        ->delete();

    Document::withTrashed()
        ->where('original_filename', 'synthetic-understanding-fixtures.txt')
        ->forceDelete();

    $document = Document::query()->create([
        'team_id' => $team->id,
        'uploaded_by_user_id' => $user->id,
        'status' => 'parsed',
        'text_extraction_status' => 'completed',
        'original_filename' => 'synthetic-understanding-fixtures.txt',
        'mime_type' => 'text/plain',
        'file_size' => 0,
        'raw_text' => 'Synthetic Product Understanding fixture document.',
    ]);

    $line = DocumentLine::query()->create([
        'document_id' => $document->id,
        'line_number' => 1,
        'raw_text' => 'Synthetic Product Understanding fixture line.',
        'description' => 'Synthetic Product Understanding fixture line.',
        'quantity' => 1,
        'unit_price' => 0,
        'total_price' => 0,
        'confidence_score' => 100,
        'metadata' => [
            'synthetic' => true,
            'seeded_for' => 'product_understanding_fixtures',
        ],
    ]);

    $feedbackMatcher = app(App\Services\Documents\ProductUnderstanding\ProductUnderstandingFeedbackMatcher::class);
    $pythonAnalyzer = app(App\Services\Documents\ProductUnderstanding\ProductTextSimilarityAnalyzer::class);

    $rows = [];
    $failures = [];

    $record = function (string $group, string $scenario, string $assertion, bool $passed, mixed $expected = null, mixed $actual = null) use (&$rows, &$failures): void {
        $rows[] = [
            $group,
            $scenario,
            $assertion,
            $passed ? 'OK' : 'FAIL',
        ];

        if (! $passed) {
            $failures[] = [
                'group' => $group,
                'scenario' => $scenario,
                'assertion' => $assertion,
                'expected' => $expected,
                'actual' => $actual,
            ];
        }
    };

    $assertEquals = function (string $group, string $scenario, string $assertion, mixed $expected, mixed $actual) use ($record): void {
        $record($group, $scenario, $assertion, $expected === $actual, $expected, $actual);
    };

    $assertMin = function (string $group, string $scenario, string $assertion, float $min, mixed $actual) use ($record): void {
        $actual = (float) $actual;
        $record($group, $scenario, $assertion, $actual >= $min, '>= '.$min, $actual);
    };

    $assertMax = function (string $group, string $scenario, string $assertion, float $max, mixed $actual) use ($record): void {
        $actual = (float) $actual;
        $record($group, $scenario, $assertion, $actual <= $max, '<= '.$max, $actual);
    };

    $assertContains = function (string $group, string $scenario, string $assertion, array $needles, array $haystack) use ($record): void {
        foreach ($needles as $needle) {
            $record(
                $group,
                $scenario,
                $assertion.': '.$needle,
                in_array($needle, $haystack, true),
                $needle,
                $haystack,
            );
        }
    };

    $assertNotContains = function (string $group, string $scenario, string $assertion, array $needles, array $haystack) use ($record): void {
        foreach ($needles as $needle) {
            $record(
                $group,
                $scenario,
                $assertion.': '.$needle,
                ! in_array($needle, $haystack, true),
                'not '.$needle,
                $haystack,
            );
        }
    };

    foreach (($fixtures['feedback'] ?? []) as $scenario) {
        $name = (string) ($scenario['name'] ?? 'unnamed_feedback_scenario');
        $expect = $scenario['expect'] ?? [];

        $result = $feedbackMatcher->match(
            line: $line,
            candidateName: $scenario['candidate_name'] ?? null,
            eanCode: $scenario['ean_code'] ?? null,
        );

        if (array_key_exists('suggested_bias', $expect)) {
            $assertEquals('feedback', $name, 'suggested_bias', $expect['suggested_bias'], data_get($result, 'suggested_bias'));
        }

        if (array_key_exists('review_hint', $expect)) {
            $assertEquals('feedback', $name, 'review_hint', $expect['review_hint'], data_get($result, 'review_hint'));
        }

        if (array_key_exists('min_best_similarity', $expect)) {
            $assertMin('feedback', $name, 'best_similarity', (float) $expect['min_best_similarity'], data_get($result, 'similar_description.best_similarity', 0));
        }

        if (array_key_exists('max_best_similarity', $expect)) {
            $assertMax('feedback', $name, 'best_similarity', (float) $expect['max_best_similarity'], data_get($result, 'similar_description.best_similarity', 0));
        }

        if (array_key_exists('min_product_identity_score', $expect)) {
            $assertMin('feedback', $name, 'product_identity_score', (float) $expect['min_product_identity_score'], data_get($result, 'product_identity_score', 0));
        }

        if (array_key_exists('min_registration_preference_score', $expect)) {
            $assertMin('feedback', $name, 'registration_preference_score', (float) $expect['min_registration_preference_score'], data_get($result, 'registration_preference_score', 0));
        }

        if (array_key_exists('model_conflict', $expect)) {
            $assertEquals(
                'feedback',
                $name,
                'model_conflict',
                (bool) $expect['model_conflict'],
                (bool) data_get($result, 'similar_description.matches.0.model_conflict'),
            );
        }

        if (! empty($expect['contains_model_overlap'])) {
            $overlap = collect(data_get($result, 'similar_description.matches', []))
                ->flatMap(fn ($match) => $match['model_overlap'] ?? [])
                ->unique()
                ->values()
                ->all();

            $assertContains('feedback', $name, 'model_overlap contains', $expect['contains_model_overlap'], $overlap);
        }
    }

    foreach (($fixtures['python'] ?? []) as $scenario) {
        $name = (string) ($scenario['name'] ?? 'unnamed_python_scenario');
        $expect = $scenario['expect'] ?? [];

        $result = $pythonAnalyzer->analyze(
            candidateName: $scenario['candidate_name'] ?? '',
            eanCode: null,
            globalFactContext: [],
            suggestedCategory: $scenario['suggested_category'] ?? null,
            suggestedLineType: $scenario['suggested_line_type'] ?? null,
        );

        if (array_key_exists('best_match', $expect)) {
            $assertEquals('python', $name, 'best_match', $expect['best_match'], data_get($result, 'best_match.canonical_name'));
        }

        if (array_key_exists('min_similarity', $expect)) {
            $assertMin('python', $name, 'similarity', (float) $expect['min_similarity'], data_get($result, 'best_match.similarity', 0));
        }

        if (! empty($expect['contains_signals'])) {
            $assertContains('python', $name, 'signals contains', $expect['contains_signals'], data_get($result, 'signals', []));
        }

        if (! empty($expect['not_contains_signals'])) {
            $assertNotContains('python', $name, 'signals does not contain', $expect['not_contains_signals'], data_get($result, 'signals', []));
        }

        if (! empty($expect['contains_warnings'])) {
            $assertContains('python', $name, 'warnings contains', $expect['contains_warnings'], data_get($result, 'warnings', []));
        }

        if (! empty($expect['not_contains_warnings'])) {
            $assertNotContains('python', $name, 'warnings does not contain', $expect['not_contains_warnings'], data_get($result, 'warnings', []));
        }
    }

    foreach (($fixtures['pipeline'] ?? []) as $scenario) {
        $name = (string) ($scenario['name'] ?? 'unnamed_pipeline_scenario');
        $expect = $scenario['expect'] ?? [];

        $filename = 'synthetic-pipeline-'.$name.'.txt';

        ProductIdentificationCandidate::query()
            ->whereHas('document', fn ($query) => $query->where('original_filename', $filename))
            ->delete();

        DocumentLine::query()
            ->whereHas('document', fn ($query) => $query->where('original_filename', $filename))
            ->delete();

        Document::withTrashed()
            ->where('original_filename', $filename)
            ->forceDelete();

        $documentTypeId = App\Models\DocumentType::query()
            ->where('code', $scenario['document_type'] ?? 'invoice')
            ->value('id');

        $rawText = implode(PHP_EOL, $scenario['raw_text_lines'] ?? []);

        $pipelineDocument = Document::query()->create([
            'team_id' => $team->id,
            'uploaded_by_user_id' => $user->id,
            'document_type_id' => $documentTypeId,
            'status' => 'parsed',
            'text_extraction_status' => 'completed',
            'original_filename' => $filename,
            'mime_type' => 'text/plain',
            'file_size' => strlen($rawText),
            'raw_text' => $rawText,
        ]);

        $lineCount = app(App\Services\Documents\DocumentLineParser::class)
            ->parse($pipelineDocument);

        $candidateCount = app(App\Services\Documents\ProductCandidateGenerator::class)
            ->generate($pipelineDocument);

        $pipelineDocument->update([
            'status' => $candidateCount > 0 ? 'needs_review' : 'parsed',
        ]);

        $pipelineDocument->refresh();

        if (array_key_exists('line_count', $expect)) {
            $assertEquals('pipeline', $name, 'line_count', $expect['line_count'], $lineCount);
        }

        if (array_key_exists('candidate_count', $expect)) {
            $assertEquals('pipeline', $name, 'candidate_count', $expect['candidate_count'], $candidateCount);
        }

        if (array_key_exists('document_status', $expect)) {
            $assertEquals('pipeline', $name, 'document_status', $expect['document_status'], $pipelineDocument->status);
        }

        $actualLines = $pipelineDocument->lines()
            ->orderBy('line_number')
            ->get();

        foreach (($expect['lines'] ?? []) as $index => $expectedLine) {
            $actualLine = $actualLines->get($index);

            $record(
                'pipeline',
                $name,
                'line '.($index + 1).' exists',
                $actualLine !== null,
                'line exists',
                null,
            );

            if (! $actualLine) {
                continue;
            }

            if (array_key_exists('description', $expectedLine)) {
                $assertEquals(
                    'pipeline',
                    $name,
                    'line '.($index + 1).' description',
                    $expectedLine['description'],
                    $actualLine->description,
                );
            }

            if (array_key_exists('quantity', $expectedLine)) {
                $assertEquals(
                    'pipeline',
                    $name,
                    'line '.($index + 1).' quantity',
                    $expectedLine['quantity'],
                    (string) $actualLine->quantity,
                );
            }

            if (array_key_exists('unit_price', $expectedLine)) {
                $assertEquals(
                    'pipeline',
                    $name,
                    'line '.($index + 1).' unit_price',
                    $expectedLine['unit_price'],
                    (string) $actualLine->unit_price,
                );
            }

            if (array_key_exists('total_price', $expectedLine)) {
                $assertEquals(
                    'pipeline',
                    $name,
                    'line '.($index + 1).' total_price',
                    $expectedLine['total_price'],
                    (string) $actualLine->total_price,
                );
            }

            if (array_key_exists('mode', $expectedLine)) {
                $assertEquals(
                    'pipeline',
                    $name,
                    'line '.($index + 1).' mode',
                    $expectedLine['mode'],
                    $actualLine->metadata['mode'] ?? null,
                );
            }
        }

        $actualCandidates = $pipelineDocument->productIdentificationCandidates()
            ->orderBy('id')
            ->get();

        foreach (($expect['candidates'] ?? []) as $expectedCandidate) {
            $needle = (string) ($expectedCandidate['name_contains'] ?? '');

            $actualCandidate = $actualCandidates
                ->first(fn ($candidate) => $needle !== '' && str_contains($candidate->name, $needle));

            $record(
                'pipeline',
                $name,
                'candidate exists: '.$needle,
                $actualCandidate !== null,
                'candidate containing '.$needle,
                $actualCandidates->pluck('name')->values()->all(),
            );

            if (! $actualCandidate) {
                continue;
            }

            if (array_key_exists('ean_code', $expectedCandidate)) {
                $assertEquals(
                    'pipeline',
                    $name,
                    $needle.' ean_code',
                    $expectedCandidate['ean_code'],
                    $actualCandidate->ean_code,
                );
            }

            if (array_key_exists('serial_number', $expectedCandidate)) {
                $assertEquals(
                    'pipeline',
                    $name,
                    $needle.' serial_number',
                    $expectedCandidate['serial_number'],
                    $actualCandidate->serial_number,
                );
            }

            if (array_key_exists('global_fact_matched', $expectedCandidate)) {
                $assertEquals(
                    'pipeline',
                    $name,
                    $needle.' global_fact matched',
                    (bool) $expectedCandidate['global_fact_matched'],
                    (bool) data_get($actualCandidate->metadata, 'product_understanding_global_fact.matched'),
                );
            }

            if (array_key_exists('global_fact_canonical_name', $expectedCandidate)) {
                $assertEquals(
                    'pipeline',
                    $name,
                    $needle.' global_fact canonical_name',
                    $expectedCandidate['global_fact_canonical_name'],
                    data_get($actualCandidate->metadata, 'product_understanding_global_fact.canonical_name'),
                );
            }

            if (! empty($expectedCandidate['global_fact_contains_signals'])) {
                $assertContains(
                    'pipeline',
                    $name,
                    $needle.' global_fact signals contains',
                    $expectedCandidate['global_fact_contains_signals'],
                    data_get($actualCandidate->metadata, 'product_understanding_global_fact.signals', []),
                );
            }

            if (array_key_exists('feedback_suggested_bias', $expectedCandidate)) {
                $assertEquals(
                    'pipeline',
                    $name,
                    $needle.' feedback suggested_bias',
                    $expectedCandidate['feedback_suggested_bias'],
                    data_get($actualCandidate->metadata, 'product_understanding_feedback.suggested_bias'),
                );
            }

            if (array_key_exists('feedback_model_conflict', $expectedCandidate)) {
                $assertEquals(
                    'pipeline',
                    $name,
                    $needle.' feedback model_conflict',
                    (bool) $expectedCandidate['feedback_model_conflict'],
                    (bool) data_get($actualCandidate->metadata, 'product_understanding_feedback.similar_description.matches.0.model_conflict'),
                );
            }

            if (array_key_exists('python_best_match', $expectedCandidate)) {
                $assertEquals(
                    'pipeline',
                    $name,
                    $needle.' python best_match',
                    $expectedCandidate['python_best_match'],
                    data_get($actualCandidate->metadata, 'product_understanding_python.best_match.canonical_name'),
                );
            }

            if (! empty($expectedCandidate['python_contains_signals'])) {
                $assertContains(
                    'pipeline',
                    $name,
                    $needle.' python signals contains',
                    $expectedCandidate['python_contains_signals'],
                    data_get($actualCandidate->metadata, 'product_understanding_python.signals', []),
                );
            }

            if (! empty($expectedCandidate['python_not_contains_signals'])) {
                $assertNotContains(
                    'pipeline',
                    $name,
                    $needle.' python signals does not contain',
                    $expectedCandidate['python_not_contains_signals'],
                    data_get($actualCandidate->metadata, 'product_understanding_python.signals', []),
                );
            }

            if (! empty($expectedCandidate['python_contains_warnings'])) {
                $assertContains(
                    'pipeline',
                    $name,
                    $needle.' python warnings contains',
                    $expectedCandidate['python_contains_warnings'],
                    data_get($actualCandidate->metadata, 'product_understanding_python.warnings', []),
                );
            }
        }
    }

    $this->table(['Group', 'Scenario', 'Assertion', 'Status'], $rows);

    if ($failures !== []) {
        $this->error('Product Understanding fixtures failed.');

        foreach ($failures as $failure) {
            $this->line('');
            $this->warn($failure['group'].' / '.$failure['scenario'].' / '.$failure['assertion']);
            $this->line('Expected: '.json_encode($failure['expected'], JSON_UNESCAPED_UNICODE));
            $this->line('Actual:   '.json_encode($failure['actual'], JSON_UNESCAPED_UNICODE));
        }

        return 1;
    }

    $this->info('Product Understanding fixtures passed.');

    return 0;
})->purpose('Run Product Understanding scenarios from versioned fixtures');

/*
|--------------------------------------------------------------------------
| Product Understanding full test command
|--------------------------------------------------------------------------
|
| Comando comodo per eseguire tutta la suite Product Understanding locale.
|
| Uso:
| php artisan product-vault:test-understanding
|
| Uso da database pulito:
| php artisan product-vault:test-understanding --fresh
|
*/
Artisan::command('product-vault:test-understanding {--fresh : Reset database with migrate:fresh --seed before running understanding tests}', function () {
    if ($this->option('fresh')) {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('The --fresh option is allowed only in local/testing environments.');

            return 1;
        }

        $this->warn('Running migrate:fresh --seed. Local database data will be deleted.');

        if (! $this->confirm('Continue?', false)) {
            $this->info('Aborted.');

            return 1;
        }

        $freshExitCode = $this->call('migrate:fresh', [
            '--seed' => true,
        ]);

        if ($freshExitCode !== 0) {
            return $freshExitCode;
        }
    }

    $seedExitCode = $this->call('product-vault:seed-understanding-knowledge');

    if ($seedExitCode !== 0) {
        return $seedExitCode;
    }

    $fixturesExitCode = $this->call('product-vault:run-understanding-fixtures');

    if ($fixturesExitCode !== 0) {
        return $fixturesExitCode;
    }

    $this->info('Product Understanding test suite passed.');

    return 0;
})->purpose('Seed and run the full Product Understanding fixture suite');