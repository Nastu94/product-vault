<?php

namespace App\Console\Commands\ProductVault;

use App\Livewire\Products\ProductShow;
use App\Models\Product;
use App\Models\ProductCase;
use App\Models\ProductCaseEvent;
use App\Models\User;
use App\Services\ProductCases\ProductCaseCreator;
use App\Services\ProductCases\ProductCaseDocumentSelector;
use App\Services\ProductCases\ProductCaseStatusTransitionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ViewErrorBag;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;
use Throwable;

final class TestProductShowCasesReadOnlyCommand
    extends Command
{
    /**
     * @var string
     */
    protected $signature =
        'product-vault:test-product-show-cases-read-only';

    /**
     * @var string
     */
    protected $description =
        'Verifica con rollback l’elenco read-only delle pratiche nella scheda prodotto.';

    public function handle(
        ProductCaseCreator $creator,
        ProductCaseDocumentSelector $documentSelector,
        ProductCaseStatusTransitionService $transitionService
    ): int {
        $rows = [];
        $failures = [];

        $createdCaseIds = [];

        $casesBefore =
            ProductCase::query()->count();

        $eventsBefore =
            ProductCaseEvent::query()->count();

        $linksBefore =
            DB::table(
                'product_case_documents'
            )->count();

        $permissionRegistrar =
            app(
                PermissionRegistrar::class
            );

        $assertSame = function (
            string $scenario,
            string $assertion,
            mixed $expected,
            mixed $actual
        ) use (&$rows, &$failures): void {
            $passed =
                $expected === $actual;

            $rows[] = [
                $scenario,
                $assertion,
                $passed ? 'OK' : 'FAIL',
            ];

            if (! $passed) {
                $failures[] = [
                    'scenario' =>
                        $scenario,

                    'assertion' =>
                        $assertion,

                    'expected' =>
                        $expected,

                    'actual' =>
                        $actual,
                ];
            }
        };

        DB::beginTransaction();

        try {
            $assertSame(
                'view',
                'partial exists',
                true,
                View::exists(
                    'livewire.products.partials.product-cases'
                )
            );

            $productShowSource =
                file_get_contents(
                    resource_path(
                        'views/livewire/products/product-show.blade.php'
                    )
                );

            if (
                ! is_string(
                    $productShowSource
                )
            ) {
                throw new RuntimeException(
                    'Impossibile leggere la vista dettaglio prodotto.'
                );
            }

            $assertSame(
                'view',
                'partial included in product page',
                true,
                str_contains(
                    $productShowSource,
                    'livewire.products.partials.product-cases'
                )
            );

            $product = Product::query()
                ->with([
                    'team',
                    'documents',
                    'warranties',
                ])
                ->whereNotNull(
                    'team_id'
                )
                ->whereHas(
                    'documents'
                )
                ->whereHas(
                    'warranties',
                    fn ($query) => $query
                        ->whereNotNull(
                            'starts_at'
                        )
                        ->whereNotNull(
                            'ends_at'
                        )
                )
                ->orderBy('id')
                ->first();

            if (
                $product === null
                || $product->team === null
                || $product
                    ->documents
                    ->isEmpty()
            ) {
                throw new RuntimeException(
                    'Nessun prodotto con team, documenti e garanzia completa utilizzabile per il test.'
                );
            }

            $user = User::query()
                ->find(
                    $product
                        ->team
                        ->user_id
                );

            if ($user === null) {
                throw new RuntimeException(
                    'Nessun utente utilizzabile per il test.'
                );
            }

            User::query()
                ->whereKey(
                    $user->id
                )
                ->update([
                    'current_team_id' =>
                        $product->team_id,
                ]);

            $user->refresh();

            $permissionRegistrar
                ->setPermissionsTeamId(
                    $product->team_id
                );

            $user->unsetRelation(
                'roles'
            );

            $user->unsetRelation(
                'permissions'
            );

            $document =
                $product
                    ->documents
                    ->first();

            if ($document === null) {
                throw new RuntimeException(
                    'Documento prodotto non disponibile.'
                );
            }

            /*
             |--------------------------------------------------------------------------
             | Pratica in bozza
             |--------------------------------------------------------------------------
             */

            $draftCase =
                $creator->create(
                    product:
                        $product,

                    openedBy:
                        $user,

                    attributes: [
                        'title' =>
                            'Pratica in bozza dalla scheda prodotto',

                        'description' =>
                            'Segnalazione ancora incompleta.',

                        'occurred_on' =>
                            today()
                                ->toDateString(),

                        'usability_status' =>
                            ProductCase
                                ::USABILITY_UNKNOWN,

                        'accidental_damage_declared' =>
                            null,
                    ],
                );

            $createdCaseIds[] =
                (int) $draftCase->id;

            /*
             |--------------------------------------------------------------------------
             | Pratica pronta
             |--------------------------------------------------------------------------
             */

            $readyCase =
                $creator->create(
                    product:
                        $product,

                    openedBy:
                        $user,

                    attributes: [
                        'title' =>
                            'Pratica pronta dalla scheda prodotto',

                        'description' =>
                            'Il prodotto non si avvia.',

                        'occurred_on' =>
                            today()
                                ->toDateString(),

                        'usability_status' =>
                            ProductCase
                                ::USABILITY_UNUSABLE,

                        'accidental_damage_declared' =>
                            false,
                    ],
                );

            $createdCaseIds[] =
                (int) $readyCase->id;

            $documentSelector->select(
                productCase:
                    $readyCase,

                document:
                    $document,

                selectedBy:
                    $user,
            );

            $readyCase =
                $transitionService
                    ->transition(
                        productCase:
                            $readyCase,

                        performedBy:
                            $user,

                        targetStatus:
                            ProductCase
                                ::STATUS_READY_TO_CONTACT,
                    );

            $caseCountBeforeRead =
                ProductCase::query()->count();

            $eventCountBeforeRead =
                ProductCaseEvent::query()
                    ->count();

            $linkCountBeforeRead =
                DB::table(
                    'product_case_documents'
                )->count();

            /*
             |--------------------------------------------------------------------------
             | Mount read-only del prodotto
             |--------------------------------------------------------------------------
             */

            Auth::login(
                $user
            );

            $component =
                app(
                    ProductShow::class
                );

            $component->mount(
                $product->fresh()
            );

            $summaries =
                $component
                    ->getProductCaseSummariesProperty();

            $createdSummaries =
                collect($summaries)
                    ->filter(
                        fn (array $summary): bool =>
                            in_array(
                                $summary['id'],
                                $createdCaseIds,
                                true
                            )
                    )
                    ->values()
                    ->all();

            $assertSame(
                'component',
                'two created cases exposed',
                2,
                count(
                    $createdSummaries
                )
            );

            $assertSame(
                'ordering',
                'newest case first',
                (int) $readyCase->id,
                $createdSummaries[0]['id']
                    ?? null
            );

            $assertSame(
                'ordering',
                'older case second',
                (int) $draftCase->id,
                $createdSummaries[1]['id']
                    ?? null
            );

            $readySummary =
                collect($createdSummaries)
                    ->firstWhere(
                        'id',
                        (int) $readyCase->id
                    );

            $draftSummary =
                collect($createdSummaries)
                    ->firstWhere(
                        'id',
                        (int) $draftCase->id
                    );

            $assertSame(
                'status',
                'ready status label',
                'Pronta per il contatto',
                data_get(
                    $readySummary,
                    'status_label'
                )
            );

            $assertSame(
                'status',
                'draft status label',
                'Bozza',
                data_get(
                    $draftSummary,
                    'status_label'
                )
            );

            $assertSame(
                'provenance',
                'opening user exposed',
                $user->name,
                data_get(
                    $readySummary,
                    'opened_by_name'
                )
            );

            $assertSame(
                'dates',
                'opening date exposed',
                true,
                is_string(
                    data_get(
                        $readySummary,
                        'opened_at'
                    )
                )
            );

            /*
             |--------------------------------------------------------------------------
             | Rendering isolato della partial
             |--------------------------------------------------------------------------
             */

            $html =
                View::make(
                    'livewire.products.partials.product-cases',
                    [
                        /*
                         * Nel normale rendering Livewire queste variabili
                         * arrivano dal componente e dal middleware web.
                         *
                         * Il comando renderizza invece la partial in modo
                         * isolato, quindi ricostruisce esplicitamente il
                         * contratto minimo della vista.
                         */
                        'errors' =>
                            new ViewErrorBag(),

                        'product' =>
                            $component->product,

                        'productCases' =>
                            $summaries,

                        'isCreatingProductCase' =>
                            false,

                        'productCaseTitle' =>
                            '',

                        'productCaseDescription' =>
                            '',

                        'productCaseOccurredOn' =>
                            null,

                        'productCaseUsabilityStatus' =>
                            ProductCase
                                ::USABILITY_UNKNOWN,

                        'productCaseAccidentalDamageDeclared' =>
                            null,

                        'productCaseAccidentalDamageNotes' =>
                            null,
                    ]
                )->render();

            $assertSame(
                'html',
                'section rendered',
                true,
                str_contains(
                    $html,
                    'Pratiche prodotto'
                )
            );

            $assertSame(
                'html',
                'draft case visible',
                true,
                str_contains(
                    $html,
                    'Pratica in bozza dalla scheda prodotto'
                )
            );

            $assertSame(
                'html',
                'ready case visible',
                true,
                str_contains(
                    $html,
                    'Pratica pronta dalla scheda prodotto'
                )
            );

            $assertSame(
                'html',
                'draft detail link present',
                true,
                str_contains(
                    $html,
                    route(
                        'product-cases.show',
                        [
                            'productCase' =>
                                $draftCase->id,
                        ]
                    )
                )
            );

            $assertSame(
                'html',
                'ready detail link present',
                true,
                str_contains(
                    $html,
                    route(
                        'product-cases.show',
                        [
                            'productCase' =>
                                $readyCase->id,
                        ]
                    )
                )
            );

            $assertSame(
                'read_only',
                'no form rendered',
                false,
                str_contains(
                    $html,
                    '<form'
                )
            );

            /*
             * L’elenco delle pratiche resta read-only, ma dalla patch 7B1
             * espone intenzionalmente la CTA che apre il form iniziale.
             */
            $assertSame(
                'creation_entry',
                'problem action available',
                true,
                str_contains(
                    $html,
                    'wire:click="startProductCaseCreation"'
                )
            );

            $assertSame(
                'creation_entry',
                'creation form remains closed',
                false,
                str_contains(
                    $html,
                    'product-case-create-form'
                )
            );

            $assertSame(
                'read_only',
                'no wire submit action rendered',
                false,
                str_contains(
                    $html,
                    'wire:submit'
                )
            );

            /*
             |--------------------------------------------------------------------------
             | Assenza di scritture
             |--------------------------------------------------------------------------
             */

            $assertSame(
                'read_only',
                'case count unchanged',
                $caseCountBeforeRead,
                ProductCase::query()->count()
            );

            $assertSame(
                'read_only',
                'event count unchanged',
                $eventCountBeforeRead,
                ProductCaseEvent::query()
                    ->count()
            );

            $assertSame(
                'read_only',
                'document links unchanged',
                $linkCountBeforeRead,
                DB::table(
                    'product_case_documents'
                )->count()
            );
        } catch (Throwable $exception) {
            $rows[] = [
                'runtime',
                'product case list workflow completed',
                'FAIL',
            ];

            $failures[] = [
                'scenario' =>
                    'runtime',

                'assertion' =>
                    'product case list workflow completed',

                'expected' =>
                    'no exception',

                'actual' =>
                    $exception::class
                    . ': '
                    . $exception
                        ->getMessage(),
            ];
        } finally {
            Auth::logout();

            $permissionRegistrar
                ->setPermissionsTeamId(
                    null
                );

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
            'event count restored',
            $eventsBefore,
            ProductCaseEvent::query()->count()
        );

        $assertSame(
            'rollback',
            'document links restored',
            $linksBefore,
            DB::table(
                'product_case_documents'
            )->count()
        );

        foreach ($createdCaseIds as $createdCaseId) {
            $assertSame(
                'rollback',
                'temporary case removed '
                    . $createdCaseId,
                false,
                ProductCase::query()
                    ->whereKey(
                        $createdCaseId
                    )
                    ->exists()
            );
        }

        $this->table(
            [
                'Scenario',
                'Assertion',
                'Status',
            ],
            $rows
        );

        if ($failures !== []) {
            foreach (
                $failures as $failure
            ) {
                $this->error(
                    $failure['scenario']
                    . ' / '
                    . $failure['assertion']
                );

                $this->line(
                    'Expected: '
                    . var_export(
                        $failure[
                            'expected'
                        ],
                        true
                    )
                );

                $this->line(
                    'Actual: '
                    . var_export(
                        $failure[
                            'actual'
                        ],
                        true
                    )
                );
            }

            return self::FAILURE;
        }

        $this->info(
            'Product show case list checks passed.'
        );

        return self::SUCCESS;
    }
}