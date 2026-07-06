<?php

namespace App\Console\Commands\ProductVault;

use App\Livewire\ProductCases\ProductCaseIndex;
use App\Models\Product;
use App\Models\ProductCase;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;
use Throwable;

final class TestProductCaseIndexUiCommand extends Command
{
    protected $signature =
        'product-vault:test-product-case-index-ui';

    protected $description =
        'Verifica con rollback elenco, filtri e isolamento delle pratiche prodotto.';

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

            $prefix = 'INDEX-' . Str::upper(Str::random(8));

            $createCase = function (
                int $teamId,
                string $status,
                string $title,
                ?string $outcome = null
            ) use ($product, $user): ProductCase {
                return ProductCase::unguarded(
                    fn (): ProductCase => ProductCase::query()->create([
                        'team_id' => $teamId,
                        'product_id' => $product->id,
                        'opened_by_user_id' => $user->id,
                        'status' => $status,
                        'title' => $title,
                        'original_description' =>
                            'Fixture elenco pratiche prodotto.',
                        'description' =>
                            'Fixture elenco pratiche prodotto.',
                        'occurred_on' => today()->toDateString(),
                        'usability_status' =>
                            ProductCase::USABILITY_UNKNOWN,
                        'accidental_damage_declared' => false,
                        'opened_at' => now(),
                        'contacted_at' => in_array(
                            $status,
                            [
                                ProductCase::STATUS_CONTACTED,
                                ProductCase::STATUS_RESOLVED,
                                ProductCase::STATUS_CLOSED,
                            ],
                            true
                        ) ? now() : null,
                        'resolved_at' => in_array(
                            $status,
                            [
                                ProductCase::STATUS_RESOLVED,
                                ProductCase::STATUS_CLOSED,
                            ],
                            true
                        ) ? now() : null,
                        'closed_at' =>
                            $status === ProductCase::STATUS_CLOSED
                                ? now()
                                : null,
                        'cancelled_at' =>
                            $status === ProductCase::STATUS_CANCELLED
                                ? now()
                                : null,
                        'outcome' => $outcome,
                        'resolution_notes' =>
                            $outcome !== null
                                ? 'Esito fixture.'
                                : null,
                    ])
                );
            };

            $draftCase = $createCase(
                (int) $product->team_id,
                ProductCase::STATUS_DRAFT,
                $prefix . ' pratica bozza'
            );

            $readyCase = $createCase(
                (int) $product->team_id,
                ProductCase::STATUS_READY_TO_CONTACT,
                $prefix . ' pratica pronta'
            );

            $contactedCase = $createCase(
                (int) $product->team_id,
                ProductCase::STATUS_CONTACTED,
                $prefix . ' pratica contattata'
            );

            $resolvedCase = $createCase(
                (int) $product->team_id,
                ProductCase::STATUS_RESOLVED,
                $prefix . ' pratica risolta',
                ProductCase::OUTCOME_REPAIRED
            );

            $closedCase = $createCase(
                (int) $product->team_id,
                ProductCase::STATUS_CLOSED,
                $prefix . ' pratica chiusa',
                ProductCase::OUTCOME_REPLACED
            );

            $cancelledCase = $createCase(
                (int) $product->team_id,
                ProductCase::STATUS_CANCELLED,
                $prefix . ' pratica annullata'
            );

            $otherTeamId = DB::table('teams')->insertGetId([
                'user_id' => $user->id,
                'name' => 'Product Case Index ' . Str::uuid(),
                'personal_team' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $otherTeamCase = $createCase(
                (int) $otherTeamId,
                ProductCase::STATUS_DRAFT,
                $prefix . ' altro workspace'
            );

            $component = app(ProductCaseIndex::class);
            $component->perPage = 100;

            $openView = $component->render();
            $openData = $openView->getData();
            $openCases = $openData['productCases'] ?? null;

            if (! $openCases instanceof LengthAwarePaginator) {
                throw new RuntimeException(
                    'Paginatore pratiche non disponibile.'
                );
            }

            $openIds = collect($openCases->items())
                ->pluck('id')
                ->map(fn (mixed $id): int => (int) $id)
                ->all();

            foreach (
                [$draftCase, $readyCase, $contactedCase, $resolvedCase]
                as $expectedCase
            ) {
                $assertSame(
                    'open filter',
                    'open case ' . $expectedCase->id . ' listed',
                    true,
                    in_array((int) $expectedCase->id, $openIds, true)
                );
            }

            $assertSame(
                'open filter',
                'closed case excluded',
                false,
                in_array((int) $closedCase->id, $openIds, true)
            );

            $assertSame(
                'open filter',
                'cancelled case excluded',
                false,
                in_array((int) $cancelledCase->id, $openIds, true)
            );

            $assertSame(
                'workspace',
                'other workspace excluded',
                false,
                in_array((int) $otherTeamCase->id, $openIds, true)
            );

            $component->scope = 'closed';
            $closedView = $component->render();
            $closedCases = $closedView->getData()['productCases'];
            $closedIds = collect($closedCases->items())
                ->pluck('id')
                ->map(fn (mixed $id): int => (int) $id)
                ->all();

            $assertSame(
                'closed filter',
                'closed case listed',
                true,
                in_array((int) $closedCase->id, $closedIds, true)
            );

            $assertSame(
                'closed filter',
                'resolved case excluded',
                false,
                in_array((int) $resolvedCase->id, $closedIds, true)
            );

            $component->scope = 'cancelled';
            $cancelledView = $component->render();
            $cancelledCases = $cancelledView->getData()['productCases'];
            $cancelledIds = collect($cancelledCases->items())
                ->pluck('id')
                ->map(fn (mixed $id): int => (int) $id)
                ->all();

            $assertSame(
                'cancelled filter',
                'cancelled case listed',
                true,
                in_array((int) $cancelledCase->id, $cancelledIds, true)
            );

            $component->scope = 'all';
            $component->search = $prefix . ' pratica contattata';
            $searchView = $component->render();
            $searchCases = $searchView->getData()['productCases'];
            $searchIds = collect($searchCases->items())
                ->pluck('id')
                ->map(fn (mixed $id): int => (int) $id)
                ->all();

            $assertSame(
                'search',
                'matching case returned',
                [(int) $contactedCase->id],
                $searchIds
            );

            $component->search = '';
            $component->scope = 'all';
            $allView = $component->render();
            $allHtml = $allView->render();

            $assertSame(
                'html',
                'index rendered',
                true,
                str_contains($allHtml, 'product-case-index')
            );

            $assertSame(
                'html',
                'human outcome rendered',
                true,
                str_contains($allHtml, 'Prodotto sostituito')
            );

            $assertSame(
                'routing',
                'index route available',
                route('product-cases.index'),
                url('/product-cases')
            );
        } catch (Throwable $exception) {
            $rows[] = [
                'runtime',
                'product case index workflow completed',
                'FAIL',
            ];

            $failures[] = [
                'scenario' => 'runtime',
                'assertion' =>
                    'product case index workflow completed',
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

        $this->info('Product case index UI checks passed.');

        return self::SUCCESS;
    }
}
