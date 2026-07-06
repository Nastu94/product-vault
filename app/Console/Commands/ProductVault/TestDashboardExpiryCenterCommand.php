<?php

namespace App\Console\Commands\ProductVault;

use App\Livewire\Dashboard\DashboardExpiryCenter;
use App\Models\Product;
use App\Models\User;
use App\Models\Warranty;
use App\Services\Warranties\WarrantyCoverageContextResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;
use Throwable;

final class TestDashboardExpiryCenterCommand extends Command
{
    protected $signature =
        'product-vault:test-dashboard-expiry-center';

    protected $description =
        'Verifica con rollback le coperture in scadenza mostrate in dashboard.';

    public function handle(): int
    {
        $rows = [];
        $failures = [];

        $productsBefore = Product::query()->count();
        $warrantiesBefore = Warranty::query()->count();
        $teamsBefore = DB::table('teams')->count();

        $permissionRegistrar = app(PermissionRegistrar::class);
        $coverageResolver = app(
            WarrantyCoverageContextResolver::class
        );

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

        DB::beginTransaction();

        try {
            $product = Product::query()
                ->with('team')
                ->whereNotNull('team_id')
                ->orderBy('id')
                ->first();

            if ($product === null || $product->team === null) {
                throw new RuntimeException(
                    'Nessun prodotto con team utilizzabile per il test.'
                );
            }

            $user = User::query()->find($product->team->user_id);

            if ($user === null) {
                throw new RuntimeException(
                    'Nessun utente utilizzabile per il test.'
                );
            }

            User::query()
                ->whereKey($user->id)
                ->update([
                    'current_team_id' => $product->team_id,
                ]);

            $user->refresh();

            $permissionRegistrar
                ->setPermissionsTeamId($product->team_id);

            $user->unsetRelation('roles');
            $user->unsetRelation('permissions');
            Auth::login($user);

            $baseline = app(DashboardExpiryCenter::class);
            $baseline->mount($coverageResolver);

            $urgentWarranty = Warranty::query()->create([
                'product_id' => $product->id,
                'starts_at' => today()->subMonth()->toDateString(),
                'ends_at' => today()->toDateString(),
                'duration_months' => 1,
                'source' => 'manual',
                'confidence_score' => 100,
                'notes' => 'Fixture scadenza urgente dashboard.',
                'metadata' => [],
            ]);

            Warranty::query()->create([
                'product_id' => $product->id,
                'starts_at' => today()->subMonth()->toDateString(),
                'ends_at' => today()->addDays(15)->toDateString(),
                'duration_months' => 1,
                'source' => 'manual',
                'confidence_score' => 100,
                'notes' => 'Fixture scadenza prossima dashboard.',
                'metadata' => [],
            ]);

            $component = app(DashboardExpiryCenter::class);
            $component->mount($coverageResolver);

            $assertSame(
                'counts',
                'two expiring coverages added',
                $baseline->expiringCount + 2,
                $component->expiringCount
            );

            $assertSame(
                'counts',
                'urgent coverage incremented',
                $baseline->urgentCount + 1,
                $component->urgentCount
            );

            $assertSame(
                'counts',
                'upcoming coverage incremented',
                $baseline->upcomingCount + 1,
                $component->upcomingCount
            );

            $assertSame(
                'list',
                'list limited to six items',
                true,
                count($component->expiringItems) <= 6
            );

            $assertSame(
                'list',
                'urgent fixture shown',
                true,
                collect($component->expiringItems)->contains(
                    fn (array $item): bool =>
                        (int) $item['id'] === (int) $urgentWarranty->id
                        && $item['remaining_label'] === 'Scade oggi'
                )
            );

            $html = $component
                ->render()
                ->with([
                    'expiringItems' => $component->expiringItems,
                    'expiringCount' => $component->expiringCount,
                    'urgentCount' => $component->urgentCount,
                    'upcomingCount' => $component->upcomingCount,
                ])
                ->render();

            $assertSame(
                'html',
                'expiry center rendered',
                true,
                str_contains($html, 'dashboard-expiry-center')
            );

            $assertSame(
                'html',
                'filtered archive link rendered',
                true,
                str_contains(
                    $html,
                    e(route(
                        'warranties.index',
                        ['status' => 'expiring']
                    ))
                )
            );

            $assertSame(
                'html',
                'urgent label rendered',
                true,
                str_contains($html, 'Scade oggi')
            );

            $otherTeamId = DB::table('teams')->insertGetId([
                'user_id' => $user->id,
                'name' => 'Dashboard expiry ' . Str::uuid(),
                'personal_team' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $otherTeamProduct = $product->replicate();
            $otherTeamProduct->team_id = $otherTeamId;
            $otherTeamProduct->name =
                'Dashboard scadenza altro workspace ' . Str::uuid();
            $otherTeamProduct->model = null;
            $otherTeamProduct->serial_number = null;
            $otherTeamProduct->ean_code = null;
            $otherTeamProduct->save();

            Warranty::query()->create([
                'product_id' => $otherTeamProduct->id,
                'starts_at' => today()->subMonth()->toDateString(),
                'ends_at' => today()->addDays(3)->toDateString(),
                'duration_months' => 1,
                'source' => 'manual',
                'confidence_score' => 100,
                'metadata' => [],
            ]);

            $isolated = app(DashboardExpiryCenter::class);
            $isolated->mount($coverageResolver);

            $assertSame(
                'workspace',
                'other workspace coverage excluded',
                [
                    $component->expiringCount,
                    $component->urgentCount,
                    $component->upcomingCount,
                ],
                [
                    $isolated->expiringCount,
                    $isolated->urgentCount,
                    $isolated->upcomingCount,
                ]
            );
        } catch (Throwable $exception) {
            $rows[] = [
                'runtime',
                'dashboard expiry center completed',
                'FAIL',
            ];

            $failures[] = [
                'scenario' => 'runtime',
                'assertion' =>
                    'dashboard expiry center completed',
                'expected' => 'no exception',
                'actual' =>
                    $exception::class . ': ' . $exception->getMessage(),
            ];
        } finally {
            Auth::logout();
            $permissionRegistrar->setPermissionsTeamId(null);
            DB::rollBack();
        }

        $assertSame(
            'rollback',
            'product count restored',
            $productsBefore,
            Product::query()->count()
        );

        $assertSame(
            'rollback',
            'warranty count restored',
            $warrantiesBefore,
            Warranty::query()->count()
        );

        $assertSame(
            'rollback',
            'team count restored',
            $teamsBefore,
            DB::table('teams')->count()
        );

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
            'Dashboard expiry center checks passed.'
        );

        return self::SUCCESS;
    }
}
