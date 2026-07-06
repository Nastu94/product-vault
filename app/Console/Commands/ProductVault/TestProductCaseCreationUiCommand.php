<?php

namespace App\Console\Commands\ProductVault;

use App\Livewire\Products\ProductShow;
use App\Models\Product;
use App\Models\ProductCase;
use App\Models\ProductCaseEvent;
use App\Models\User;
use App\Services\ProductCases\ProductCaseCreator;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Command;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\Permission\PermissionRegistrar;
use Throwable;

final class TestProductCaseCreationUiCommand
    extends Command
{
    /**
     * @var string
     */
    protected $signature =
        'product-vault:test-product-case-creation-ui';

    /**
     * @var string
     */
    protected $description =
        'Verifica con rollback l’apertura guidata di una pratica dalla scheda prodotto.';

    public function handle(
        ProductCaseCreator $creator
    ): int {
        $rows = [];
        $failures = [];

        $createdCaseId = null;

        $casesBefore =
            ProductCase::query()->count();

        $eventsBefore =
            ProductCaseEvent::query()->count();

        $mediaBefore =
            Media::query()->count();

        $teamsBefore =
            DB::table('teams')->count();

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
            /*
             |--------------------------------------------------------------------------
             | Fixture
             |--------------------------------------------------------------------------
             */

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
            ) {
                throw new RuntimeException(
                    'Nessun prodotto con team utilizzabile per il test.'
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

            $user->unsetRelation('roles');
            $user->unsetRelation('permissions');

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

            /*
             |--------------------------------------------------------------------------
             | Stato iniziale e rendering CTA
             |--------------------------------------------------------------------------
             */

            $assertSame(
                'initial_state',
                'creation form starts closed',
                false,
                $component
                    ->isCreatingProductCase
            );

            $closedHtml = View::make(
                'livewire.products.partials.product-cases',
                [
                    'errors' =>
                        new ViewErrorBag(),

                    'product' =>
                        $component->product,

                    'productCases' =>
                        $component
                            ->getProductCaseSummariesProperty(),

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
                'problem call to action visible',
                true,
                str_contains(
                    $closedHtml,
                    'Ho un problema'
                )
            );

            $assertSame(
                'html',
                'form hidden before action',
                false,
                str_contains(
                    $closedHtml,
                    'product-case-create-form'
                )
            );

            /*
             |--------------------------------------------------------------------------
             | Apertura e annullamento
             |--------------------------------------------------------------------------
             */

            $component
                ->startProductCaseCreation();

            $assertSame(
                'form_state',
                'creation form opened',
                true,
                $component
                    ->isCreatingProductCase
            );

            $assertSame(
                'form_state',
                'usability starts unknown',
                ProductCase
                    ::USABILITY_UNKNOWN,
                $component
                    ->productCaseUsabilityStatus
            );

            $openHtml = View::make(
                'livewire.products.partials.product-cases',
                [
                    'errors' =>
                        new ViewErrorBag(),

                    'product' =>
                        $component->product,

                    'productCases' =>
                        $component
                            ->getProductCaseSummariesProperty(),

                    'isCreatingProductCase' =>
                        $component
                            ->isCreatingProductCase,

                    'productCaseTitle' =>
                        $component
                            ->productCaseTitle,

                    'productCaseDescription' =>
                        $component
                            ->productCaseDescription,

                    'productCaseOccurredOn' =>
                        $component
                            ->productCaseOccurredOn,

                    'productCaseUsabilityStatus' =>
                        $component
                            ->productCaseUsabilityStatus,

                    'productCaseAccidentalDamageDeclared' =>
                        $component
                            ->productCaseAccidentalDamageDeclared,

                    'productCaseAccidentalDamageNotes' =>
                        $component
                            ->productCaseAccidentalDamageNotes,
                ]
            )->render();

            $assertSame(
                'html',
                'creation form rendered',
                true,
                str_contains(
                    $openHtml,
                    'product-case-create-form'
                )
            );

            $assertSame(
                'html',
                'creation submit action present',
                true,
                str_contains(
                    $openHtml,
                    'createProductCase'
                )
            );

            $assertSame(
                'scope',
                'no file input rendered',
                false,
                str_contains(
                    $openHtml,
                    'type="file"'
                )
            );

            $component->productCaseTitle =
                'Test da annullare';

            $component->productCaseDescription =
                'Questa descrizione deve essere rimossa.';

            $component
                ->cancelProductCaseCreation();

            $assertSame(
                'cancellation',
                'form closed',
                false,
                $component
                    ->isCreatingProductCase
            );

            $assertSame(
                'cancellation',
                'title reset',
                '',
                $component
                    ->productCaseTitle
            );

            $assertSame(
                'cancellation',
                'description reset',
                '',
                $component
                    ->productCaseDescription
            );

            /*
             |--------------------------------------------------------------------------
             | Validazione
             |--------------------------------------------------------------------------
             */

            $component
                ->startProductCaseCreation();

            $casesBeforeInvalid =
                ProductCase::query()->count();

            $eventsBeforeInvalid =
                ProductCaseEvent::query()
                    ->count();

            $validationRejected =
                false;

            $validationFields =
                [];

            try {
                $component->createProductCase(
                    $creator
                );
            } catch (
                ValidationException $exception
            ) {
                $validationRejected =
                    true;

                $validationFields =
                    array_keys(
                        $exception->errors()
                    );
            }

            sort(
                $validationFields
            );

            $assertSame(
                'validation',
                'blank form rejected',
                true,
                $validationRejected
            );

            $assertSame(
                'validation',
                'required fields reported',
                [
                    'productCaseDescription',
                    'productCaseTitle',
                ],
                $validationFields
            );

            $assertSame(
                'validation',
                'invalid form creates no case',
                $casesBeforeInvalid,
                ProductCase::query()->count()
            );

            $assertSame(
                'validation',
                'invalid form creates no event',
                $eventsBeforeInvalid,
                ProductCaseEvent::query()
                    ->count()
            );

            /*
             |--------------------------------------------------------------------------
             | Creazione valida
             |--------------------------------------------------------------------------
             */

            $component->productCaseTitle =
                '  Prodotto che non si accende  ';

            $component->productCaseDescription =
                '  Il prodotto non si avvia dopo un utilizzo normale.  ';

            $component->productCaseOccurredOn =
                today()
                    ->toDateString();

            $component->productCaseUsabilityStatus =
                ProductCase
                    ::USABILITY_UNUSABLE;

            $component
                ->productCaseAccidentalDamageDeclared =
                    '0';

            /*
             * Valore intenzionalmente presente per verificare che le note
             * vengano eliminate quando il danno dichiarato è false.
             */
            $component
                ->productCaseAccidentalDamageNotes =
                    'Nota che non deve essere conservata.';

            $casesBeforeCreate =
                ProductCase::query()->count();

            $eventsBeforeCreate =
                ProductCaseEvent::query()
                    ->count();

            $mediaBeforeCreate =
                Media::query()->count();

            $linksBeforeCreate =
                DB::table(
                    'product_case_documents'
                )->count();

            $response =
                $component->createProductCase(
                    $creator
                );

            $createdCase =
                ProductCase::query()
                    ->where(
                        'product_id',
                        $product->id
                    )
                    ->where(
                        'opened_by_user_id',
                        $user->id
                    )
                    ->orderByDesc('id')
                    ->first();

            if ($createdCase === null) {
                throw new RuntimeException(
                    'La pratica creata non è disponibile.'
                );
            }

            $createdCaseId =
                (int) $createdCase->id;

            $assertSame(
                'creation',
                'one case created',
                $casesBeforeCreate + 1,
                ProductCase::query()->count()
            );

            $assertSame(
                'creation',
                'team derived from product',
                (int) $product->team_id,
                (int) $createdCase->team_id
            );

            $assertSame(
                'creation',
                'product assigned',
                (int) $product->id,
                (int) $createdCase->product_id
            );

            $assertSame(
                'creation',
                'opening user assigned',
                (int) $user->id,
                (int) $createdCase
                    ->opened_by_user_id
            );

            $assertSame(
                'creation',
                'status starts draft',
                ProductCase::STATUS_DRAFT,
                $createdCase->status
            );

            $assertSame(
                'creation',
                'title normalized',
                'Prodotto che non si accende',
                $createdCase->title
            );

            $assertSame(
                'creation',
                'description normalized',
                'Il prodotto non si avvia dopo un utilizzo normale.',
                $createdCase->description
            );

            $assertSame(
                'creation',
                'original description stored',
                $createdCase->description,
                $createdCase
                    ->original_description
            );

            $assertSame(
                'creation',
                'usability stored',
                ProductCase
                    ::USABILITY_UNUSABLE,
                $createdCase
                    ->usability_status
            );

            $assertSame(
                'creation',
                'false accidental damage stored',
                false,
                $createdCase
                    ->accidental_damage_declared
            );

            $assertSame(
                'creation',
                'irrelevant damage notes removed',
                null,
                $createdCase
                    ->accidental_damage_notes
            );

            $assertSame(
                'creation',
                'request draft starts empty',
                null,
                $createdCase
                    ->request_draft
            );

            $assertSame(
                'creation',
                'no document selected automatically',
                0,
                $createdCase
                    ->documents()
                    ->count()
            );

            $assertSame(
                'creation',
                'no photo created automatically',
                $mediaBeforeCreate,
                Media::query()->count()
            );

            $assertSame(
                'creation',
                'document links unchanged',
                $linksBeforeCreate,
                DB::table(
                    'product_case_documents'
                )->count()
            );

            $assertSame(
                'lifecycle',
                'one opening event created',
                $eventsBeforeCreate + 1,
                ProductCaseEvent::query()
                    ->count()
            );

            $openingEvent =
                $createdCase
                    ->events()
                    ->first();

            $assertSame(
                'lifecycle',
                'opening event type',
                ProductCaseEvent
                    ::TYPE_CASE_OPENED,
                $openingEvent
                    ?->event_type
            );

            $assertSame(
                'redirect',
                'redirect response returned',
                true,
                $response instanceof
                    RedirectResponse
            );

            $assertSame(
                'redirect',
                'redirects to created case',
                route(
                    'product-cases.show',
                    [
                        'productCase' =>
                            $createdCase->id,
                    ]
                ),
                $response->getTargetUrl()
            );

            $assertSame(
                'form_state',
                'form reset after creation',
                false,
                $component
                    ->isCreatingProductCase
            );

            /*
             |--------------------------------------------------------------------------
             | Isolamento workspace
             |--------------------------------------------------------------------------
             */

            $otherTeamId =
                DB::table('teams')
                    ->insertGetId([
                        'user_id' =>
                            $user->id,

                        'name' =>
                            'Product Case Creation '
                            . Str::uuid(),

                        'personal_team' =>
                            false,

                        'created_at' =>
                            now(),

                        'updated_at' =>
                            now(),
                    ]);

            User::query()
                ->whereKey(
                    $user->id
                )
                ->update([
                    'current_team_id' =>
                        $otherTeamId,
                ]);

            $user->refresh();

            $permissionRegistrar
                ->setPermissionsTeamId(
                    $otherTeamId
                );

            $user->unsetRelation('roles');
            $user->unsetRelation('permissions');

            Auth::setUser(
                $user
            );

            $casesBeforeCrossTeam =
                ProductCase::query()->count();

            $eventsBeforeCrossTeam =
                ProductCaseEvent::query()
                    ->count();

            $crossTeamRejected =
                false;

            try {
                $component
                    ->startProductCaseCreation();
            } catch (
                AuthorizationException
            ) {
                $crossTeamRejected =
                    true;
            }

            $assertSame(
                'authorization',
                'cross-team creation rejected',
                true,
                $crossTeamRejected
            );

            $assertSame(
                'authorization',
                'cross-team attempt creates no case',
                $casesBeforeCrossTeam,
                ProductCase::query()->count()
            );

            $assertSame(
                'authorization',
                'cross-team attempt creates no event',
                $eventsBeforeCrossTeam,
                ProductCaseEvent::query()
                    ->count()
            );

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

            $user->unsetRelation('roles');
            $user->unsetRelation('permissions');

            Auth::setUser(
                $user
            );
        } catch (Throwable $exception) {
            $rows[] = [
                'runtime',
                'creation ui workflow completed',
                'FAIL',
            ];

            $failures[] = [
                'scenario' =>
                    'runtime',

                'assertion' =>
                    'creation ui workflow completed',

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

        /*
         |--------------------------------------------------------------------------
         | Rollback
         |--------------------------------------------------------------------------
         */

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
            'media count restored',
            $mediaBefore,
            Media::query()->count()
        );

        $assertSame(
            'rollback',
            'team count restored',
            $teamsBefore,
            DB::table('teams')->count()
        );

        $assertSame(
            'rollback',
            'document links restored',
            $linksBefore,
            DB::table(
                'product_case_documents'
            )->count()
        );

        if ($createdCaseId !== null) {
            $assertSame(
                'rollback',
                'temporary case removed',
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
            'Product case creation UI checks passed.'
        );

        return self::SUCCESS;
    }
}