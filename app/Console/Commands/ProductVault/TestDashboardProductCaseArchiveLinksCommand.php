<?php

namespace App\Console\Commands\ProductVault;

use App\Livewire\ProductCases\ProductCaseIndex;
use Illuminate\Console\Command;
use Throwable;

final class TestDashboardProductCaseArchiveLinksCommand extends Command
{
    protected $signature =
        'product-vault:test-dashboard-product-case-archive-links';

    protected $description =
        'Verifica i collegamenti filtrati tra dashboard e archivio pratiche.';

    public function handle(): int
    {
        $rows = [];
        $failures = [];
        $originalScope = request()->query('scope');

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
            $openHtml = view(
                'livewire.dashboard.dashboard-action-center',
                [
                    'openProductCases' => [],
                    'openProductCasesCount' => 0,
                ]
            )->render();

            $assertSame(
                'open archive link',
                'link rendered',
                true,
                str_contains(
                    $openHtml,
                    'dashboard-open-product-cases-archive-link'
                )
            );

            $assertSame(
                'open archive link',
                'open scope included',
                true,
                str_contains(
                    $openHtml,
                    route(
                        'product-cases.index',
                        ['scope' => 'open']
                    )
                )
            );

            $closedHtml = view(
                'livewire.dashboard.dashboard-results-center',
                [
                    'recentResults' => [],
                    'concludedCount' => 0,
                    'repairedCount' => 0,
                    'replacedCount' => 0,
                    'refundedCount' => 0,
                ]
            )->render();

            $assertSame(
                'closed archive link',
                'link rendered',
                true,
                str_contains(
                    $closedHtml,
                    'dashboard-closed-product-cases-archive-link'
                )
            );

            $assertSame(
                'closed archive link',
                'closed scope included',
                true,
                str_contains(
                    $closedHtml,
                    route(
                        'product-cases.index',
                        ['scope' => 'closed']
                    )
                )
            );

            request()->query->set('scope', 'closed');

            $closedIndex = app(ProductCaseIndex::class);
            $closedIndex->mount();

            $assertSame(
                'archive initialization',
                'closed scope accepted',
                'closed',
                $closedIndex->scope
            );

            request()->query->set('scope', 'invalid');

            $safeIndex = app(ProductCaseIndex::class);
            $safeIndex->mount();

            $assertSame(
                'archive initialization',
                'invalid scope falls back to open',
                'open',
                $safeIndex->scope
            );
        } catch (Throwable $exception) {
            $rows[] = [
                'runtime',
                'archive links workflow completed',
                'FAIL',
            ];

            $failures[] = [
                'scenario' => 'runtime',
                'assertion' =>
                    'archive links workflow completed',
                'expected' => 'no exception',
                'actual' =>
                    $exception::class . ': ' . $exception->getMessage(),
            ];
        } finally {
            if (is_string($originalScope)) {
                request()->query->set(
                    'scope',
                    $originalScope
                );
            } else {
                request()->query->remove('scope');
            }
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
            'Dashboard product case archive link checks passed.'
        );

        return self::SUCCESS;
    }
}
