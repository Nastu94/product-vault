<?php

namespace App\Console\Commands\ProductVault;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use App\Models\Document;
use App\Models\DocumentLine;
use App\Models\DocumentType;
use App\Models\ProductIdentificationCandidate;
use App\Models\ProductUnderstandingFeedback;
use App\Models\ProductUnderstandingGlobalFact;
use App\Models\Team;
use App\Models\User;
use App\Services\Documents\DocumentLineParser;
use App\Services\Documents\ProductCandidateGenerator;
use App\Services\Documents\DocumentLines\DocumentLineAmountConsistencyChecker;
use App\Services\Documents\ProductUnderstanding\ProductTextSimilarityAnalyzer;
use App\Services\Documents\ProductUnderstanding\ProductUnderstandingFeedbackMatcher;

#[Signature('product-vault:run-understanding-fixtures')]
#[Description('Run Product Understanding scenarios from versioned fixtures')]
class RunUnderstandingFixturesCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle()
    {
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

        $feedbackMatcher = app(ProductUnderstandingFeedbackMatcher::class);
        $pythonAnalyzer = app(ProductTextSimilarityAnalyzer::class);
        $amountConsistencyChecker = app(DocumentLineAmountConsistencyChecker::class);

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

        foreach (($fixtures['amount_consistency'] ?? []) as $scenario) {
            $name = (string) ($scenario['name'] ?? 'unnamed_amount_consistency_scenario');
            $expect = $scenario['expect'] ?? [];

            $result = $amountConsistencyChecker->check(
                quantity: $scenario['quantity'] ?? null,
                unitPrice: $scenario['unit_price'] ?? null,
                totalPrice: $scenario['total_price'] ?? null,
                tolerance: array_key_exists('tolerance', $scenario)
                    ? (float) $scenario['tolerance']
                    : null,
            );

            if (array_key_exists('checked', $expect)) {
                $assertEquals(
                    'amount_consistency',
                    $name,
                    'checked',
                    (bool) $expect['checked'],
                    (bool) data_get($result, 'checked'),
                );
            }

            if (array_key_exists('is_consistent', $expect)) {
                $assertEquals(
                    'amount_consistency',
                    $name,
                    'is_consistent',
                    $expect['is_consistent'],
                    data_get($result, 'is_consistent'),
                );
            }

            foreach (['expected_total', 'actual_total', 'delta', 'tolerance'] as $key) {
                if (! array_key_exists($key, $expect)) {
                    continue;
                }

                $expectedValue = $expect[$key] === null ? null : (float) $expect[$key];
                $actualValue = data_get($result, $key);
                $actualValue = $actualValue === null ? null : (float) $actualValue;

                $assertEquals(
                    'amount_consistency',
                    $name,
                    $key,
                    $expectedValue,
                    $actualValue,
                );
            }

            if (array_key_exists('reason', $expect)) {
                $assertEquals(
                    'amount_consistency',
                    $name,
                    'reason',
                    $expect['reason'],
                    data_get($result, 'reason'),
                );
            }

            if (! empty($expect['contains_signals'])) {
                $assertContains(
                    'amount_consistency',
                    $name,
                    'signals contains',
                    $expect['contains_signals'],
                    data_get($result, 'signals', []),
                );
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

            $documentTypeId = DocumentType::query()
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

            $lineCount = app(DocumentLineParser::class)
                ->parse($pipelineDocument);

            $candidateCount = app(ProductCandidateGenerator::class)
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

                if (array_key_exists('brand_name', $expectedCandidate)) {
                    $actualCandidate->loadMissing('brand');

                    $assertEquals(
                        'pipeline',
                        $name,
                        $needle.' brand name',
                        $expectedCandidate['brand_name'],
                        $actualCandidate->brand?->name,
                    );
                }

                if (array_key_exists('brand_candidate', $expectedCandidate)) {
                    $assertEquals(
                        'pipeline',
                        $name,
                        $needle.' brand candidate',
                        $expectedCandidate['brand_candidate'],
                        data_get($actualCandidate->metadata, 'product_understanding.brand_candidate'),
                    );
                }

                if (array_key_exists('brand_match_type', $expectedCandidate)) {
                    $assertEquals(
                        'pipeline',
                        $name,
                        $needle.' brand match type',
                        $expectedCandidate['brand_match_type'],
                        data_get($actualCandidate->metadata, 'product_understanding_brand.match_type'),
                    );
                }

                if (array_key_exists('brand_alias', $expectedCandidate)) {
                    $assertEquals(
                        'pipeline',
                        $name,
                        $needle.' brand alias',
                        $expectedCandidate['brand_alias'],
                        data_get($actualCandidate->metadata, 'product_understanding_brand.alias'),
                    );
                }

                if (array_key_exists('category_slug', $expectedCandidate)) {
                    $actualCandidate->loadMissing('category');

                    $assertEquals(
                        'pipeline',
                        $name,
                        $needle.' category slug',
                        $expectedCandidate['category_slug'],
                        $actualCandidate->category?->slug,
                    );
                }

                if (array_key_exists('initial_category_matched', $expectedCandidate)) {
                    $assertEquals(
                        'pipeline',
                        $name,
                        $needle.' initial category matched',
                        (bool) $expectedCandidate['initial_category_matched'],
                        (bool) data_get($actualCandidate->metadata, 'product_understanding_category.matched'),
                    );
                }

                if (! empty($expectedCandidate['initial_line_patterns_contain'])) {
                    $actualPatterns = collect(data_get(
                        $actualCandidate->metadata,
                        'product_understanding_initial_knowledge.line_patterns',
                        []
                    ))
                        ->pluck('pattern')
                        ->values()
                        ->all();

                    $assertContains(
                        'pipeline',
                        $name,
                        $needle.' initial line patterns contain',
                        $expectedCandidate['initial_line_patterns_contain'],
                        $actualPatterns,
                    );
                }

                if (! empty($expectedCandidate['initial_line_pattern_match_types'])) {
                    $actualPatterns = collect(data_get(
                        $actualCandidate->metadata,
                        'product_understanding_initial_knowledge.line_patterns',
                        []
                    ));

                    foreach ($expectedCandidate['initial_line_pattern_match_types'] as $pattern => $expectedMatchType) {
                        $actualPattern = $actualPatterns
                            ->first(fn ($item): bool => ($item['pattern'] ?? null) === $pattern);

                        $assertEquals(
                            'pipeline',
                            $name,
                            $needle.' initial line pattern '.$pattern.' match type',
                            $expectedMatchType,
                            $actualPattern['match_type'] ?? null,
                        );
                    }
                }

                if (! empty($expectedCandidate['initial_knowledge_summary'])) {
                    $actualSummary = data_get(
                        $actualCandidate->metadata,
                        'product_understanding_initial_knowledge.summary',
                        []
                    );

                    foreach ($expectedCandidate['initial_knowledge_summary'] as $key => $expectedValue) {
                        $assertEquals(
                            'pipeline',
                            $name,
                            $needle.' initial knowledge summary '.$key,
                            $expectedValue,
                            data_get($actualSummary, $key),
                        );
                    }
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

            return self::FAILURE;
        }

        $this->info('Product Understanding fixtures passed.');

        return self::SUCCESS;
    }
}
