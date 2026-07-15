<?php

namespace App\Console\Commands\ProductVault;

use App\Models\Plan;
use App\Models\PlanFeature;
use App\Models\PlanLimit;
use App\Models\Team;
use App\Services\Monetization\PlanCatalogResolver;
use App\Services\Monetization\PlanEntitlementResolver;
use App\Support\Monetization\MonetizationKeys;
use Database\Seeders\PlanSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

final class TestMonetizationFoundationCommand extends Command
{
    protected $signature =
        'product-vault:test-monetization-foundation';

    protected $description =
        'Verifica catalogo piani, limiti, feature e fallback del workspace.';

    public function handle(
        PlanCatalogResolver $catalogResolver,
        PlanEntitlementResolver $entitlementResolver
    ): int {
        $rows = [];
        $failures = [];

        $before = [
            'plans' => Plan::query()->count(),
            'limits' => PlanLimit::query()->count(),
            'features' => PlanFeature::query()->count(),
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
            app(PlanSeeder::class)->run();
            app(PlanSeeder::class)->run();

            $catalog = $catalogResolver->resolve();
            $codes = collect($catalog)->pluck('code')->all();

            $assertSame(
                'catalog',
                'four active plans available',
                4,
                count($catalog)
            );

            $assertSame(
                'catalog',
                'expected plan order',
                ['free', 'premium_personal', 'family', 'business'],
                $codes
            );

            $expectedLimitKeys = collect(
                MonetizationKeys::limitKeys()
            )->sort()->values()->all();

            $expectedFeatureKeys = collect(
                MonetizationKeys::featureKeys()
            )->sort()->values()->all();

            foreach ($codes as $code) {
                $plan = Plan::query()
                    ->with(['limits', 'features'])
                    ->where('code', $code)
                    ->firstOrFail();

                $actualLimitKeys = $plan->limits
                    ->where('is_active', true)
                    ->pluck('limit_key')
                    ->sort()
                    ->values()
                    ->all();

                $actualFeatureKeys = $plan->features
                    ->pluck('feature_key')
                    ->sort()
                    ->values()
                    ->all();

                $assertSame(
                    'limits',
                    $code . ' has complete limit contract',
                    $expectedLimitKeys,
                    $actualLimitKeys
                );

                $assertSame(
                    'features',
                    $code . ' has complete feature contract',
                    $expectedFeatureKeys,
                    $actualFeatureKeys
                );
            }

            $team = Team::query()->orderBy('id')->first();

            if ($team !== null) {
                $team->forceFill(['plan_id' => null])->save();
                app(PlanSeeder::class)->run();
                $team->refresh();

                $assertSame(
                    'assignment',
                    'free plan assigned to unconfigured team',
                    'free',
                    $team->plan?->code
                );

                $entitlements = $entitlementResolver->resolve($team);

                $assertSame(
                    'resolver',
                    'entitlement plan resolved',
                    'free',
                    data_get($entitlements, 'plan.code')
                );

                $assertSame(
                    'resolver',
                    'manual upload enabled',
                    true,
                    data_get(
                        $entitlements,
                        'features.'
                        . MonetizationKeys::FEATURE_MANUAL_UPLOAD
                        . '.enabled'
                    )
                );
            }
        } catch (Throwable $exception) {
            $rows[] = ['runtime', 'foundation completed', 'FAIL'];
            $failures[] = [
                'scenario' => 'runtime',
                'assertion' => 'foundation completed',
                'expected' => 'no exception',
                'actual' => $exception::class
                    . ': '
                    . $exception->getMessage(),
            ];
        } finally {
            DB::rollBack();
        }

        $assertSame(
            'rollback',
            'plan count restored',
            $before['plans'],
            Plan::query()->count()
        );
        $assertSame(
            'rollback',
            'limit count restored',
            $before['limits'],
            PlanLimit::query()->count()
        );
        $assertSame(
            'rollback',
            'feature count restored',
            $before['features'],
            PlanFeature::query()->count()
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

        $this->info('Monetization foundation checks passed.');

        return self::SUCCESS;
    }
}
