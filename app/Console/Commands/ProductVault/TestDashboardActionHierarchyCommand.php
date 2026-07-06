<?php

namespace App\Console\Commands\ProductVault;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

final class TestDashboardActionHierarchyCommand extends Command
{
    protected $signature =
        'product-vault:test-dashboard-action-hierarchy';

    protected $description =
        'Verifica gerarchia operativa e rimozione dei pannelli dashboard duplicati.';

    public function handle(): int
    {
        $rows = [];
        $failures = [];

        $assertSame = function (
            string $scenario,
            string $assertion,
            mixed $expected,
            mixed $actual
        ) use (&$rows, &$failures): void {
            $passed = $expected === $actual;

            $rows[] = [
                $scenario,
                $assertion,
                $passed ? 'OK' : 'FAIL',
            ];

            if (! $passed) {
                $failures[] = [
                    'scenario' => $scenario,
                    'assertion' => $assertion,
                    'expected' => $expected,
                    'actual' => $actual,
                ];
            }
        };

        try {
            $dashboardPath = resource_path(
                'views/dashboard.blade.php'
            );

            $layoutPath = resource_path(
                'views/layouts/app.blade.php'
            );

            $controllerPath = app_path(
                'Http/Controllers/DashboardController.php'
            );

            foreach (
                [$dashboardPath, $layoutPath, $controllerPath]
                as $requiredPath
            ) {
                if (! File::exists($requiredPath)) {
                    throw new RuntimeException(
                        'File richiesto non disponibile: '
                        . $requiredPath
                    );
                }
            }

            $dashboard = File::get($dashboardPath);
            $layout = File::get($layoutPath);
            $controller = File::get($controllerPath);

            Blade::compileString($dashboard);

            $assertSame(
                'blade',
                'dashboard compiles',
                true,
                true
            );

            $assertSame(
                'hierarchy',
                'action hierarchy marker present',
                true,
                str_contains(
                    $dashboard,
                    'dashboard-action-hierarchy'
                )
            );

            $orderedTokens = [
                'dashboard.dashboard-action-center',
                'dashboard.dashboard-results-center',
                'dashboard.dashboard-completion-center',
                'dashboard.dashboard-expiry-center',
                'Riepilogo quantitativo secondario',
            ];

            $positions = collect($orderedTokens)
                ->map(
                    fn (string $token): int|false =>
                        strpos($dashboard, $token)
                )
                ->all();

            $allTokensFound = collect($positions)
                ->every(
                    fn (int|false $position): bool =>
                        $position !== false
                );

            $assertSame(
                'hierarchy',
                'all action sections found',
                true,
                $allTokensFound
            );

            $ordered = $allTokensFound
                && $positions === collect($positions)
                    ->sort()
                    ->values()
                    ->all();

            $assertSame(
                'hierarchy',
                'action sections precede counters',
                true,
                $ordered
            );

            foreach (
                [
                    'Prossimo passo',
                    'title="Revisioni aperte"',
                    'title="Coperture da controllare"',
                ] as $legacyLabel
            ) {
                $assertSame(
                    'legacy panels',
                    $legacyLabel . ' removed',
                    false,
                    str_contains($dashboard, $legacyLabel)
                );
            }

            $assertSame(
                'layout',
                'dashboard relocation container removed',
                false,
                str_contains(
                    $layout,
                    'dashboard-product-case-centers'
                )
            );

            $assertSame(
                'layout',
                'dashboard DOM relocation script removed',
                false,
                str_contains($layout, 'insertAdjacentElement')
            );

            foreach (
                [
                    'openReviewDocuments',
                    'expiringWarranties',
                    'expiringWarrantyContexts',
                    'WarrantyCoverageContextResolver',
                ] as $legacyLoader
            ) {
                $assertSame(
                    'controller',
                    $legacyLoader . ' removed',
                    false,
                    str_contains($controller, $legacyLoader)
                );
            }
        } catch (Throwable $exception) {
            $rows[] = [
                'runtime',
                'dashboard hierarchy check completed',
                'FAIL',
            ];

            $failures[] = [
                'scenario' => 'runtime',
                'assertion' =>
                    'dashboard hierarchy check completed',
                'expected' => 'no exception',
                'actual' =>
                    $exception::class . ': ' . $exception->getMessage(),
            ];
        }

        $this->table(
            ['Scenario', 'Assertion', 'Status'],
            $rows
        );

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

        $this->info(
            'Dashboard action hierarchy checks passed.'
        );

        return self::SUCCESS;
    }
}
