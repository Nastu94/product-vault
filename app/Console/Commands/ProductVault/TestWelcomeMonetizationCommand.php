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

            $html = view('welcome', [
                'planCatalog' => $catalog,
                'oneTimeOffers' => is_array($offers)
                    ? array_values($offers)
                    : [],
            ])->render();

            $assertSame(
                'catalog',
                'four public plans available',
                4,
                count($catalog)
            );
            $assertSame(
                'html',
                'monetization section rendered',
                true,
                str_contains(
                    $html,
                    'data-testid="welcome-monetization"'
                )
            );
            $assertSame(
                'html',
                'all plan names rendered',
                true,
                collect([
                    'Free',
                    'Premium personale',
                    'Famiglia',
                    'Business',
                ])->every(
                    fn (string $name): bool =>
                        str_contains($html, $name)
                )
            );
            $assertSame(
                'html',
                'checkout inactivity explained',
                true,
                str_contains(
                    $html,
                    'Checkout e pagamenti non sono ancora attivi'
                )
            );
            $assertSame(
                'html',
                'observe mode explained',
                true,
                str_contains(
                    $html,
                    'modalità monitoraggio'
                )
            );
            $assertSame(
                'html',
                'one-time offers rendered',
                true,
                str_contains($html, 'Fascicolo assistenza')
                    && str_contains($html, 'Importazione massiva')
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
