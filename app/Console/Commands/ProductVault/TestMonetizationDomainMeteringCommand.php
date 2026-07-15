<?php

namespace App\Console\Commands\ProductVault;

use App\Models\Document;
use App\Models\DocumentTextExtraction;
use App\Models\Plan;
use App\Models\Product;
use App\Models\ProductCase;
use App\Models\Team;
use App\Models\UsageCounter;
use App\Models\UsageEvent;
use App\Support\Monetization\MonetizationKeys;
use Database\Seeders\PlanSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class TestMonetizationDomainMeteringCommand extends Command
{
    protected $signature =
        'product-vault:test-monetization-domain-metering';

    protected $description =
        'Verifica observer e metering sui principali eventi di dominio monetizzabili.';

    public function handle(): int
    {
        $rows = [];
        $failures = [];
        $originalMode = config('monetization.enforcement_mode');

        $before = [
            'products' => Product::query()->count(),
            'cases' => ProductCase::query()->withTrashed()->count(),
            'extractions' => DocumentTextExtraction::query()->count(),
            'events' => UsageEvent::query()->count(),
            'counters' => UsageCounter::query()->count(),
        ];

        $assertSame = function (
            string $scenario,
            string $assertion,
            mixed $expected,
            mixed $actual
        ) use (&$rows, &$failures): void {
            $passed = $expected === $actual;
            $rows[] = [$scenario, $assertion, $passed ? 'OK' : 'FAIL'];

            if (! $passed) {
                $failures[] = compact(
                    'scenario',
                    'assertion',
                    'expected',
                    'actual'
                );
            }
        };

        DB::beginTransaction();

        try {
            config(['monetization.enforcement_mode' => 'observe']);
            app(PlanSeeder::class)->run();

            $document = Document::query()
                ->whereNotNull('team_id')
                ->orderBy('id')
                ->first();

            if ($document === null) {
                throw new RuntimeException(
                    'Nessun documento disponibile per il test.'
                );
            }

            $team = Team::query()->find($document->team_id);

            if ($team === null) {
                throw new RuntimeException(
                    'Workspace del documento non disponibile.'
                );
            }

            $freePlan = Plan::query()
                ->where('code', 'free')
                ->firstOrFail();

            $team->forceFill(['plan_id' => $freePlan->id])->save();

            $sourceProduct = Product::query()
                ->where('team_id', $team->id)
                ->orderBy('id')
                ->first();

            if ($sourceProduct === null) {
                throw new RuntimeException(
                    'Nessun prodotto disponibile nello stesso workspace.'
                );
            }

            $product = $sourceProduct->replicate();
            $product->name = 'Metering prodotto ' . Str::uuid();
            $product->model = null;
            $product->serial_number = null;
            $product->ean_code = null;
            $product->save();

            $productEventKey =
                'product:' . $product->id . ':created';

            $assertSame(
                'product',
                'product creation event recorded once',
                1,
                UsageEvent::query()
                    ->where('team_id', $team->id)
                    ->where(
                        'event_key',
                        MonetizationKeys::EVENT_PRODUCT_CREATED
                    )
                    ->where('idempotency_key', $productEventKey)
                    ->count()
            );

            $productCase = ProductCase::unguarded(
                fn (): ProductCase => ProductCase::query()->create([
                    'team_id' => $team->id,
                    'product_id' => $product->id,
                    'opened_by_user_id' => $team->user_id,
                    'status' => ProductCase::STATUS_DRAFT,
                    'title' => 'Metering pratica ' . Str::uuid(),
                    'original_description' =>
                        'Fixture metering monetizzazione.',
                    'description' =>
                        'Fixture metering monetizzazione.',
                    'occurred_on' => today()->toDateString(),
                    'usability_status' =>
                        ProductCase::USABILITY_UNKNOWN,
                    'accidental_damage_declared' => false,
                    'opened_at' => now(),
                ])
            );

            $assertSame(
                'product case',
                'case opened event recorded once',
                1,
                UsageEvent::query()
                    ->where('team_id', $team->id)
                    ->where(
                        'event_key',
                        MonetizationKeys::EVENT_PRODUCT_CASE_OPENED
                    )
                    ->where(
                        'idempotency_key',
                        'product-case:' . $productCase->id . ':opened'
                    )
                    ->count()
            );

            $productCase->forceFill([
                'status' => ProductCase::STATUS_RESOLVED,
                'outcome' => ProductCase::OUTCOME_REPAIRED,
                'resolved_at' => now(),
            ])->save();

            $productCase->forceFill([
                'status' => ProductCase::STATUS_CLOSED,
                'closed_at' => now(),
            ])->save();

            $assertSame(
                'product case',
                'resolved event recorded once',
                1,
                UsageEvent::query()
                    ->where('team_id', $team->id)
                    ->where(
                        'event_key',
                        MonetizationKeys::EVENT_PRODUCT_CASE_RESOLVED
                    )
                    ->where(
                        'idempotency_key',
                        'product-case:' . $productCase->id . ':resolved'
                    )
                    ->count()
            );

            $assertSame(
                'product case',
                'closed event recorded once',
                1,
                UsageEvent::query()
                    ->where('team_id', $team->id)
                    ->where(
                        'event_key',
                        MonetizationKeys::EVENT_PRODUCT_CASE_CLOSED
                    )
                    ->where(
                        'idempotency_key',
                        'product-case:' . $productCase->id . ':closed'
                    )
                    ->count()
            );

            $extraction = DocumentTextExtraction::query()->create([
                'document_id' => $document->id,
                'engine' => 'paddleocr',
                'status' => 'completed',
                'raw_text' => 'Fixture OCR monetization metering.',
                'confidence_score' => 90,
                'metadata' => ['source' => 'test'],
                'started_at' => now(),
                'completed_at' => now(),
            ]);

            $assertSame(
                'ocr',
                'OCR event recorded once',
                1,
                UsageEvent::query()
                    ->where('team_id', $team->id)
                    ->where(
                        'event_key',
                        MonetizationKeys::EVENT_OCR_RUN
                    )
                    ->where(
                        'idempotency_key',
                        'text-extraction:' . $extraction->id . ':ocr'
                    )
                    ->count()
            );

            $counterKeys = UsageCounter::query()
                ->where('team_id', $team->id)
                ->whereIn('counter_key', [
                    'products_created',
                    'product_cases_opened',
                    'product_cases_resolved',
                    'product_cases_closed',
                    'ocr_runs',
                ])
                ->pluck('counter_key')
                ->unique()
                ->sort()
                ->values()
                ->all();

            $assertSame(
                'counters',
                'domain counters updated',
                [
                    'ocr_runs',
                    'product_cases_closed',
                    'product_cases_opened',
                    'product_cases_resolved',
                    'products_created',
                ],
                $counterKeys
            );
        } catch (Throwable $exception) {
            $rows[] = ['runtime', 'domain metering completed', 'FAIL'];
            $failures[] = [
                'scenario' => 'runtime',
                'assertion' => 'domain metering completed',
                'expected' => 'no exception',
                'actual' => $exception::class
                    . ': '
                    . $exception->getMessage(),
            ];
        } finally {
            config([
                'monetization.enforcement_mode' => $originalMode,
            ]);
            DB::rollBack();
        }

        $assertSame(
            'rollback',
            'product count restored',
            $before['products'],
            Product::query()->count()
        );
        $assertSame(
            'rollback',
            'case count restored',
            $before['cases'],
            ProductCase::query()->withTrashed()->count()
        );
        $assertSame(
            'rollback',
            'extraction count restored',
            $before['extractions'],
            DocumentTextExtraction::query()->count()
        );
        $assertSame(
            'rollback',
            'usage event count restored',
            $before['events'],
            UsageEvent::query()->count()
        );
        $assertSame(
            'rollback',
            'usage counter count restored',
            $before['counters'],
            UsageCounter::query()->count()
        );

        $this->table(['Scenario', 'Assertion', 'Status'], $rows);

        if ($failures !== []) {
            foreach ($failures as $failure) {
                $this->error(
                    $failure['scenario']
                    . ' / '
                    . $failure['assertion']
                );
                $this->line(
                    'Expected: '
                    . var_export($failure['expected'], true)
                );
                $this->line(
                    'Actual: '
                    . var_export($failure['actual'], true)
                );
            }

            return self::FAILURE;
        }

        $this->info('Monetization domain metering checks passed.');

        return self::SUCCESS;
    }
}
