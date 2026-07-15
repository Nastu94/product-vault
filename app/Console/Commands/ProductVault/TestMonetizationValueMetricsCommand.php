<?php

namespace App\Console\Commands\ProductVault;

use App\Models\Product;
use App\Models\ProductCase;
use App\Models\Team;
use App\Services\Monetization\MonetizationValueMetricsResolver;
use Database\Seeders\PlanSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class TestMonetizationValueMetricsCommand extends Command
{
    protected $signature =
        'product-vault:test-monetization-value-metrics';

    protected $description =
        'Verifica le metriche economiche basate sugli esiti delle pratiche.';

    public function handle(
        MonetizationValueMetricsResolver $resolver
    ): int {
        $rows = [];
        $failures = [];
        $originalMode = config('monetization.enforcement_mode');
        $casesBefore = ProductCase::query()->withTrashed()->count();

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

            $product = Product::query()
                ->whereNotNull('team_id')
                ->orderBy('id')
                ->first();

            if ($product === null) {
                throw new RuntimeException(
                    'Nessun prodotto disponibile per il test.'
                );
            }

            $team = Team::query()->find($product->team_id);

            if ($team === null) {
                throw new RuntimeException(
                    'Workspace del prodotto non disponibile.'
                );
            }

            $baseline = $resolver->resolve($team);
            $userId = (int) $team->user_id;

            $createCase = function (
                string $status,
                ?string $outcome,
                int $openedDaysAgo,
                ?int $resolvedDaysAgo = null
            ) use ($team, $product, $userId): ProductCase {
                return ProductCase::unguarded(
                    fn (): ProductCase => ProductCase::query()->create([
                        'team_id' => $team->id,
                        'product_id' => $product->id,
                        'opened_by_user_id' => $userId,
                        'status' => $status,
                        'title' => 'Metriche ' . Str::uuid(),
                        'original_description' =>
                            'Fixture metriche monetizzazione.',
                        'description' =>
                            'Fixture metriche monetizzazione.',
                        'occurred_on' => now()
                            ->subDays($openedDaysAgo)
                            ->toDateString(),
                        'usability_status' =>
                            ProductCase::USABILITY_UNKNOWN,
                        'accidental_damage_declared' => false,
                        'opened_at' => now()->subDays($openedDaysAgo),
                        'contacted_at' => $resolvedDaysAgo !== null
                            ? now()->subDays($resolvedDaysAgo + 1)
                            : null,
                        'resolved_at' => $resolvedDaysAgo !== null
                            ? now()->subDays($resolvedDaysAgo)
                            : null,
                        'closed_at' => $status
                            === ProductCase::STATUS_CLOSED
                                ? now()->subDays(
                                    max(0, ($resolvedDaysAgo ?? 0) - 1)
                                )
                                : null,
                        'cancelled_at' => $status
                            === ProductCase::STATUS_CANCELLED
                                ? now()->subDay()
                                : null,
                        'outcome' => $outcome,
                    ])
                );
            };

            $createCase(
                ProductCase::STATUS_CLOSED,
                ProductCase::OUTCOME_REPAIRED,
                10,
                5
            );
            $createCase(
                ProductCase::STATUS_CLOSED,
                ProductCase::OUTCOME_REFUNDED,
                4,
                2
            );
            $createCase(
                ProductCase::STATUS_CANCELLED,
                null,
                3
            );
            $createCase(
                ProductCase::STATUS_DRAFT,
                null,
                1
            );

            $metrics = $resolver->resolve($team);

            $assertSame(
                'volume',
                'four practices added',
                data_get($baseline, 'practices_started', 0) + 4,
                data_get($metrics, 'practices_started')
            );
            $assertSame(
                'volume',
                'two concluded practices added',
                data_get($baseline, 'practices_concluded', 0) + 2,
                data_get($metrics, 'practices_concluded')
            );
            $assertSame(
                'volume',
                'one cancelled practice added',
                data_get($baseline, 'practices_cancelled', 0) + 1,
                data_get($metrics, 'practices_cancelled')
            );
            $assertSame(
                'outcomes',
                'repaired outcome incremented',
                data_get($baseline, 'outcomes.repaired', 0) + 1,
                data_get($metrics, 'outcomes.repaired')
            );
            $assertSame(
                'outcomes',
                'refunded outcome incremented',
                data_get($baseline, 'outcomes.refunded', 0) + 1,
                data_get($metrics, 'outcomes.refunded')
            );
            $assertSame(
                'outcomes',
                'successful outcomes incremented',
                data_get($baseline, 'successful_outcomes', 0) + 2,
                data_get($metrics, 'successful_outcomes')
            );

            $assertSame(
                'timing',
                'average resolution metric available',
                true,
                is_float(data_get($metrics, 'average_resolution_days'))
                    || is_int(data_get(
                        $metrics,
                        'average_resolution_days'
                    ))
            );

            $assertSame(
                'retention',
                'repeat user metric available',
                true,
                data_get($metrics, 'repeat_users', 0) >= 1
            );
        } catch (Throwable $exception) {
            $rows[] = ['runtime', 'value metrics completed', 'FAIL'];
            $failures[] = [
                'scenario' => 'runtime',
                'assertion' => 'value metrics completed',
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
            'case count restored',
            $casesBefore,
            ProductCase::query()->withTrashed()->count()
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

        $this->info('Monetization value metric checks passed.');

        return self::SUCCESS;
    }
}
