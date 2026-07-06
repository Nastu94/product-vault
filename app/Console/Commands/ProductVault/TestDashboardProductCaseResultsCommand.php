<?php

namespace App\Console\Commands\ProductVault;

use App\Livewire\Dashboard\DashboardResultsCenter;
use App\Models\Product;
use App\Models\ProductCase;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;
use Throwable;

final class TestDashboardProductCaseResultsCommand extends Command
{
    protected $signature =
        'product-vault:test-dashboard-product-case-results';

    protected $description =
        'Verifica con rollback i risultati delle pratiche chiuse in dashboard.';

    public function handle(): int
    {
        $rows = [];
        $failures = [];

        $casesBefore = ProductCase::query()->count();
        $teamsBefore = DB::table('teams')->count();

        $permissionRegistrar = app(PermissionRegistrar::class);

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

            $concludedBefore = ProductCase::query()
                ->where('team_id', $product->team_id)
                ->where('status', ProductCase::STATUS_CLOSED)
                ->count();

            $repairedBefore = ProductCase::query()
                ->where('team_id', $product->team_id)
                ->where('status', ProductCase::STATUS_CLOSED)
                ->where('outcome', ProductCase::OUTCOME_REPAIRED)
                ->count();

            $replacedBefore = ProductCase::query()
                ->where('team_id', $product->team_id)
                ->where('status', ProductCase::STATUS_CLOSED)
                ->where('outcome', ProductCase::OUTCOME_REPLACED)
                ->count();

            $refundedBefore = ProductCase::query()
                ->where('team_id', $product->team_id)
                ->where('status', ProductCase::STATUS_CLOSED)
                ->where('outcome', ProductCase::OUTCOME_REFUNDED)
                ->count();

            $createCase = function (
                int $teamId,
                string $status,
                string $title,
                ?string $outcome,
                ?string $closedAt
            ) use ($product, $user): ProductCase {
                return ProductCase::unguarded(
                    fn (): ProductCase => ProductCase::query()->create([
                        'team_id' => $teamId,
                        'product_id' => $product->id,
                        'opened_by_user_id' => $user->id,
                        'status' => $status,
                        'title' => $title,
                        'original_description' =>
                            'Fixture dashboard risultati.',
                        'description' =>
                            'Fixture dashboard risultati.',
                        'usability_status' =>
                            ProductCase::USABILITY_UNKNOWN,
                        'opened_at' => now(),
                        'contacted_at' =>
                            $status === ProductCase::STATUS_CLOSED
                                ? now()
                                : null,
                        'resolved_at' =>
                            $status === ProductCase::STATUS_CLOSED
                                ? now()
                                : null,
                        'closed_at' => $closedAt,
                        'cancelled_at' =>
                            $status === ProductCase::STATUS_CANCELLED
                                ? now()
                                : null,
                        'outcome' => $outcome,
                        'resolution_notes' =>
                            $outcome !== null
                                ? 'Esito di prova.'
                                : null,
                    ])
                );
            };

            $repairedCase = $createCase(
                (int) $product->team_id,
                ProductCase::STATUS_CLOSED,
                'Dashboard risultato riparato',
                ProductCase::OUTCOME_REPAIRED,
                now()->addDays(10)->toDateTimeString()
            );

            $replacedCase = $createCase(
                (int) $product->team_id,
                ProductCase::STATUS_CLOSED,
                'Dashboard risultato sostituito',
                ProductCase::OUTCOME_REPLACED,
                now()->addDays(9)->toDateTimeString()
            );

            $refundedCase = $createCase(
                (int) $product->team_id,
                ProductCase::STATUS_CLOSED,
                'Dashboard risultato rimborsato',
                ProductCase::OUTCOME_REFUNDED,
                now()->addDays(8)->toDateTimeString()
            );

            $cancelledCase = $createCase(
                (int) $product->team_id,
                ProductCase::STATUS_CANCELLED,
                'Dashboard annullata da escludere',
                null,
                null
            );

            $otherTeamId = DB::table('teams')->insertGetId([
                'user_id' => $user->id,
                'name' => 'Dashboard Results ' . Str::uuid(),
                'personal_team' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $otherTeamCase = $createCase(
                (int) $otherTeamId,
                ProductCase::STATUS_CLOSED,
                'Dashboard altro workspace',
                ProductCase::OUTCOME_REPAIRED,
                now()->addDays(20)->toDateTimeString()
            );

            $component = app(DashboardResultsCenter::class);
            $component->mount();

            $assertSame(
                'counts',
                'three concluded cases added',
                $concludedBefore + 3,
                $component->concludedCount
            );

            $assertSame(
                'counts',
                'repaired count updated',
                $repairedBefore + 1,
                $component->repairedCount
            );

            $assertSame(
                'counts',
                'replaced count updated',
                $replacedBefore + 1,
                $component->replacedCount
            );

            $assertSame(
                'counts',
                'refunded count updated',
                $refundedBefore + 1,
                $component->refundedCount
            );

            $listedResults = collect($component->recentResults);
            $listedIds = $listedResults
                ->pluck('id')
                ->map(fn (mixed $id): int => (int) $id)
                ->all();

            foreach (
                [$repairedCase, $replacedCase, $refundedCase]
                as $expectedCase
            ) {
                $assertSame(
                    'recent results',
                    'fixture ' . $expectedCase->id . ' listed',
                    true,
                    in_array((int) $expectedCase->id, $listedIds, true)
                );
            }

            $assertSame(
                'filters',
                'cancelled case excluded',
                false,
                in_array((int) $cancelledCase->id, $listedIds, true)
            );

            $assertSame(
                'filters',
                'other workspace excluded',
                false,
                in_array((int) $otherTeamCase->id, $listedIds, true)
            );

            $labelsById = $listedResults
                ->keyBy('id')
                ->map(fn (array $item): string => $item['outcome_label'])
                ->all();

            $assertSame(
                'labels',
                'repaired label human readable',
                'Prodotto riparato',
                $labelsById[$repairedCase->id] ?? null
            );

            $assertSame(
                'labels',
                'replaced label human readable',
                'Prodotto sostituito',
                $labelsById[$replacedCase->id] ?? null
            );

            $assertSame(
                'labels',
                'refunded label human readable',
                'Importo rimborsato',
                $labelsById[$refundedCase->id] ?? null
            );

            $html = $component
                ->render()
                ->with([
                    'recentResults' => $component->recentResults,
                    'concludedCount' => $component->concludedCount,
                    'repairedCount' => $component->repairedCount,
                    'replacedCount' => $component->replacedCount,
                    'refundedCount' => $component->refundedCount,
                ])
                ->render();

            $assertSame(
                'html',
                'results panel rendered',
                true,
                str_contains($html, 'dashboard-product-case-results')
            );

            $assertSame(
                'html',
                'human result rendered',
                true,
                str_contains($html, 'Prodotto riparato')
            );

            $assertSame(
                'html',
                'case link rendered',
                true,
                str_contains(
                    $html,
                    route('product-cases.show', $repairedCase->id)
                )
            );
        } catch (Throwable $exception) {
            $rows[] = [
                'runtime',
                'dashboard product case results completed',
                'FAIL',
            ];

            $failures[] = [
                'scenario' => 'runtime',
                'assertion' =>
                    'dashboard product case results completed',
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
            'case count restored',
            $casesBefore,
            ProductCase::query()->count()
        );

        $assertSame(
            'rollback',
            'team count restored',
            $teamsBefore,
            DB::table('teams')->count()
        );

        $this->table(['Scenario', 'Assertion', 'Status'], $rows);

        if ($failures !== []) {
            foreach ($failures as $failure) {
                $this->error(
                    $failure['scenario'] . ' / ' . $failure['assertion']
                );

                $this->line(
                    'Expected: ' . var_export($failure['expected'], true)
                );

                $this->line(
                    'Actual: ' . var_export($failure['actual'], true)
                );
            }

            return self::FAILURE;
        }

        $this->info('Dashboard product case result checks passed.');

        return self::SUCCESS;
    }
}
