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

final class TestProductCaseProductArchiveUiCommand extends Command
{
    protected $signature =
        'product-vault:test-product-case-product-archive-ui';

    protected $description =
        'Verifica con rollback il collegamento prodotto-archivio pratiche filtrato.';

    public function handle(): int
    {
        $rows = [];
        $failures = [];
        $originalScope = request()->query('scope');
        $originalProduct = request()->query('product');

        $productsBefore = Product::query()->count();
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

            $targetProductCasesBefore = ProductCase::query()
                ->where('team_id', $product->team_id)
                ->where('product_id', $product->id)
                ->count();

            $otherProduct = $product->replicate();
            $otherProduct->name =
                'Archivio pratiche altro prodotto ' . Str::uuid();
            $otherProduct->model =
                'PV-OTHER-' . Str::upper(Str::random(6));
            $otherProduct->serial_number = null;
            $otherProduct->ean_code = null;
            $otherProduct->save();

            $createCase = function (
                Product $caseProduct,
                string $title
            ) use ($user): ProductCase {
                return ProductCase::unguarded(
                    fn (): ProductCase => ProductCase::query()->create([
                        'team_id' => $caseProduct->team_id,
                        'product_id' => $caseProduct->id,
                        'opened_by_user_id' => $user->id,
                        'status' => ProductCase::STATUS_DRAFT,
                        'title' => $title,
                        'original_description' =>
                            'Fixture archivio pratiche per prodotto.',
                        'description' =>
                            'Fixture archivio pratiche per prodotto.',
                        'occurred_on' => today()->toDateString(),
                        'usability_status' =>
                            ProductCase::USABILITY_UNKNOWN,
                        'accidental_damage_declared' => false,
                        'opened_at' => now(),
                    ])
                );
            };

            $targetCase = $createCase(
                $product,
                'Archivio pratica prodotto selezionato'
            );

            $otherCase = $createCase(
                $otherProduct,
                'Archivio pratica altro prodotto'
            );

            request()->query->set('scope', 'all');
            request()->query->set(
                'product',
                (string) $product->id
            );

            $component = app(ProductCaseIndex::class);
            $component->perPage = 100;
            $component->mount();

            $assertSame(
                'initialization',
                'product filter accepted',
                (int) $product->id,
                $component->productId
            );

            $assertSame(
                'initialization',
                'product name exposed',
                $product->name,
                $component->productFilterName
            );

            $view = $component->render();
            $data = $view->getData();
            $paginator = $data['productCases'] ?? null;

            if (! $paginator instanceof LengthAwarePaginator) {
                throw new RuntimeException(
                    'Paginatore pratiche non disponibile.'
                );
            }

            $listedIds = collect($paginator->items())
                ->pluck('id')
                ->map(fn (mixed $id): int => (int) $id)
                ->all();

            $assertSame(
                'filter',
                'selected product case listed',
                true,
                in_array((int) $targetCase->id, $listedIds, true)
            );

            $assertSame(
                'filter',
                'other product case excluded',
                false,
                in_array((int) $otherCase->id, $listedIds, true)
            );

            $assertSame(
                'counts',
                'counts restricted to product',
                $targetProductCasesBefore + 1,
                (int) data_get($data, 'counts.all')
            );

            $html = $view->render();

            $assertSame(
                'html',
                'product context rendered',
                true,
                str_contains(
                    $html,
                    'Pratiche per ' . e($product->name)
                )
            );

            $assertSame(
                'html',
                'clear product filter rendered',
                true,
                str_contains(
                    $html,
                    'clear-product-case-product-filter'
                )
            );

            $partialHtml = view(
                'livewire.products.partials.product-cases',
                [
                    'product' => $product,
                    'productCases' => [],
                    'isCreatingProductCase' => false,
                    'productCaseAccidentalDamageDeclared' => null,
                ]
            )->render();

            $assertSame(
                'product page',
                'archive link rendered',
                true,
                str_contains(
                    $partialHtml,
                    'product-case-product-archive-link'
                )
            );

            $expectedArchiveUrl = route(
                'product-cases.index',
                [
                    'scope' => 'all',
                    'product' => $product->id,
                ]
            );

            $assertSame(
                'product page',
                'archive link carries product and all scope',
                true,
                str_contains(
                    $partialHtml,
                    e($expectedArchiveUrl)
                )
            );

            $otherTeamId = DB::table('teams')->insertGetId([
                'user_id' => $user->id,
                'name' => 'Product archive ' . Str::uuid(),
                'personal_team' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $crossTeamProduct = $product->replicate();
            $crossTeamProduct->team_id = $otherTeamId;
            $crossTeamProduct->name =
                'Archivio pratiche altro workspace ' . Str::uuid();
            $crossTeamProduct->model = null;
            $crossTeamProduct->serial_number = null;
            $crossTeamProduct->ean_code = null;
            $crossTeamProduct->save();

            request()->query->set(
                'product',
                (string) $crossTeamProduct->id
            );

            $guardedComponent = app(ProductCaseIndex::class);
            $guardedComponent->mount();

            $assertSame(
                'workspace guard',
                'cross-team product filter rejected',
                null,
                $guardedComponent->productId
            );
        } catch (Throwable $exception) {
            $rows[] = [
                'runtime',
                'product-scoped archive workflow completed',
                'FAIL',
            ];

            $failures[] = [
                'scenario' => 'runtime',
                'assertion' =>
                    'product-scoped archive workflow completed',
                'expected' => 'no exception',
                'actual' =>
                    $exception::class . ': ' . $exception->getMessage(),
            ];
        } finally {
            Auth::logout();
            $permissionRegistrar->setPermissionsTeamId(null);
            DB::rollBack();

            if (is_string($originalScope)) {
                request()->query->set('scope', $originalScope);
            } else {
                request()->query->remove('scope');
            }

            if (is_string($originalProduct)) {
                request()->query->set('product', $originalProduct);
            } else {
                request()->query->remove('product');
            }
        }

        $assertSame(
            'rollback',
            'product count restored',
            $productsBefore,
            Product::query()->count()
        );

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
            'Product-scoped product case archive checks passed.'
        );

        return self::SUCCESS;
    }
}
