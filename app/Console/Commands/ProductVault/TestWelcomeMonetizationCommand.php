<?php

namespace App\Console\Commands\ProductVault;

use App\Models\Plan;
use App\Services\Monetization\PlanCatalogResolver;
use Database\Seeders\PlanSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Throwable;

final class TestWelcomeMonetizationCommand extends Command
{
    protected $signature =
        'product-vault:test-welcome-monetization';

    protected $description =
        'Verifica catalogo piani, messaggi e navigazione della welcome page.';

    public function handle(
        PlanCatalogResolver $catalogResolver
    ): int {
        $rows = [];
        $failures = [];
        $plansBefore = Plan::query()->count();

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

            $catalog = $catalogResolver->resolve();
            $offers = config('monetization.one_time_offers', []);
            $welcome = File::get(
                resource_path('views/welcome.blade.php')
            );

            $assertSame(
                'catalog',
                'four public plans available',
                4,
                count($catalog)
            );
            $assertSame(
                'catalog',
                'expected plan names available',
                [
                    'Free',
                    'Premium personale',
                    'Famiglia',
                    'Business',
                ],
                collect($catalog)->pluck('name')->all()
            );
            $assertSame(
                'welcome source',
                'monetization section present',
                true,
                str_contains(
                    $welcome,
                    'data-testid="welcome-monetization"'
                )
            );
            $assertSame(
                'welcome source',
                'dynamic catalog consumed',
                true,
                str_contains($welcome, '@forelse ($publicPlans as $plan)')
            );
            $assertSame(
                'welcome source',
                'plan code label removed',
                false,
                str_contains(
                    $welcome,
                    "{{ \$plan['code'] ?? 'piano' }}"
                )
            );
            $assertSame(
                'welcome source',
                'recommended badge aligned with title',
                true,
                str_contains(
                    $welcome,
                    'class="flex items-start justify-between gap-3"'
                )
                    && str_contains(
                        $welcome,
                        'class="shrink-0 rounded-full bg-slate-900'
                    )
                    && ! str_contains(
                        $welcome,
                        'class="absolute right-5 top-5'
                    )
            );
            $assertSame(
                'welcome source',
                'checkout inactivity explained',
                true,
                str_contains(
                    $welcome,
                    'Checkout e pagamenti non sono ancora attivi'
                )
            );
            $assertSame(
                'welcome source',
                'observe mode explained',
                true,
                str_contains(
                    $welcome,
                    'modalità monitoraggio'
                )
            );
            $assertSame(
                'offers',
                'four one-time offers configured',
                4,
                is_array($offers) ? count($offers) : 0
            );
            $assertSame(
                'welcome source',
                'one-time offer catalog consumed',
                true,
                str_contains(
                    $welcome,
                    '@foreach ($publicOffers as $offer)'
                )
            );

            $navigation = File::get(
                resource_path(
                    'views/components/welcome/nav-links.blade.php'
                )
            );

            $assertSame(
                'navigation',
                'plans anchor linked',
                true,
                str_contains($navigation, "'href' => '#piani'")
            );
            $assertSame(
                'routing',
                'welcome route available',
                true,
                Route::has('welcome')
            );
        } catch (Throwable $exception) {
            $rows[] = ['runtime', 'welcome monetization completed', 'FAIL'];
            $failures[] = [
                'scenario' => 'runtime',
                'assertion' => 'welcome monetization completed',
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
            $plansBefore,
            Plan::query()->count()
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

        $this->info('Welcome monetization checks passed.');

        return self::SUCCESS;
    }
}
