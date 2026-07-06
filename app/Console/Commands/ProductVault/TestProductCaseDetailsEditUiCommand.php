<?php

namespace App\Console\Commands\ProductVault;

use App\Livewire\ProductCases\ProductCaseShow;
use App\Models\Product;
use App\Models\ProductCase;
use App\Models\ProductCaseEvent;
use App\Models\User;
use App\Services\ProductCases\ProductCaseCreator;
use App\Services\ProductCases\ProductCaseDetailsUpdater;
use App\Services\ProductCases\ProductCaseStatusTransitionService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\Permission\PermissionRegistrar;
use Throwable;

final class TestProductCaseDetailsEditUiCommand
    extends Command
{
    protected $signature =
        'product-vault:test-product-case-details-edit-ui';

    protected $description =
        'Verifica con rollback la modifica UI dei dati iniziali della pratica.';

    public function handle(
        ProductCaseCreator $creator,
        ProductCaseDetailsUpdater $updater,
        ProductCaseStatusTransitionService $transitionService
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

        $linksBefore =
            DB::table(
                'product_case_documents'
            )->count();

        $teamsBefore =
            DB::table('teams')->count();

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

        $render = function (
            ProductCaseShow $component
        ): string {
            return $component
                ->render()
                ->with([
                    'errors' =>
                        new ViewErrorBag(),

                    'productCase' =>
                        $component->productCase,

                    'readiness' =>
                        $component->readiness,

                    'timeline' =>
                        $component->timeline,

                    'issuePhotos' =>
                        $component->issuePhotos,

                    'statusLabel' =>
                        $component->statusLabel,

                    'statusBadgeClasses' =>
                        $component
                            ->statusBadgeClasses,

                    'readinessLabel' =>
                        $component->readinessLabel,

                    'readinessBadgeClasses' =>
                        $component
                            ->readinessBadgeClasses,

                    'usabilityLabel' =>
                        $component->usabilityLabel,

                    'accidentalDamageLabel' =>
                        $component
                            ->accidentalDamageLabel,

                    'requestDraftSourceLabel' =>
                        $component
                            ->requestDraftSourceLabel,

                    'isEditingDetails' =>
                        $component
                            ->isEditingDetails,

                    'detailsTitle' =>
                        $component->detailsTitle,

                    'detailsDescription' =>
                        $component
                            ->detailsDescription,

                    'detailsOccurredOn' =>
                        $component
                            ->detailsOccurredOn,

                    'detailsUsabilityStatus' =>
                        $component
                            ->detailsUsabilityStatus,

                    'detailsAccidentalDamageDeclared' =>
                        $component
                            ->detailsAccidentalDamageDeclared,

                    'detailsAccidentalDamageNotes' =>
                        $component
                            ->detailsAccidentalDamageNotes,

                    'detailsSuccessMessage' =>
                        $component
                            ->detailsSuccessMessage,

                    'selectableDocuments' =>
                        $component
                            ->selectableDocuments,

                    'isManagingDocuments' =>
                        $component
                            ->isManagingDocuments,

                    'documentToSelectId' =>
                        $component
                            ->documentToSelectId,

                    'documentSelectionNotes' =>
                        $component
                            ->documentSelectionNotes,

                    'documentsSuccessMessage' =>
                        $component
                            ->documentsSuccessMessage,

                    'isManagingPhotos' =>
                        $component
                            ->isManagingPhotos,

                    'photoUpload' =>
                        $component
                            ->photoUpload,

                    'photosSuccessMessage' =>
                        $component
                            ->photosSuccessMessage,
                ])
                ->render();
        };

        DB::beginTransaction();

        try {
            $product = Product::query()
                ->with('team')
                ->whereNotNull(
                    'team_id'
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

            $originalDescription =
                'Descrizione originale immutabile della pratica.';

            $productCase =
                $creator->create(
                    product:
                        $product,

                    openedBy:
                        $user,

                    attributes: [
                        'title' =>
                            'Problema iniziale UI',

                        'description' =>
                            $originalDescription,

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

            $createdCaseId =
                (int) $productCase->id;

            $component =
                app(
                    ProductCaseShow::class
                );

            $component->mount(
                $productCase
            );

            /*
             |--------------------------------------------------------------------------
             | Stato iniziale
             |--------------------------------------------------------------------------
             */

            $assertSame(
                'initial',
                'form starts closed',
                false,
                $component
                    ->isEditingDetails
            );

            $closedHtml =
                $render(
                    $component
                );

            $assertSame(
                'html',
                'edit action visible in draft',
                true,
                str_contains(
                    $closedHtml,
                    'start-product-case-details-edit'
                )
            );

            $assertSame(
                'html',
                'form hidden initially',
                false,
                str_contains(
                    $closedHtml,
                    'product-case-details-edit-form'
                )
            );

            /*
             |--------------------------------------------------------------------------
             | Apertura e precompilazione
             |--------------------------------------------------------------------------
             */

            $component
                ->startDetailsEdit();

            $assertSame(
                'form',
                'form opened',
                true,
                $component
                    ->isEditingDetails
            );

            $assertSame(
                'form',
                'title prefilled',
                'Problema iniziale UI',
                $component
                    ->detailsTitle
            );

            $assertSame(
                'form',
                'description prefilled',
                $originalDescription,
                $component
                    ->detailsDescription
            );

            $assertSame(
                'form',
                'date prefilled',
                today()
                    ->toDateString(),
                $component
                    ->detailsOccurredOn
            );

            $openHtml =
                $render(
                    $component
                );

            $assertSame(
                'html',
                'edit form rendered',
                true,
                str_contains(
                    $openHtml,
                    'product-case-details-edit-form'
                )
            );

            $assertSame(
                'html',
                'save action rendered',
                true,
                str_contains(
                    $openHtml,
                    'wire:submit.prevent="saveDetails"'
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

            /*
             |--------------------------------------------------------------------------
             | Annullamento
             |--------------------------------------------------------------------------
             */

            $component
                ->cancelDetailsEdit();

            $assertSame(
                'cancellation',
                'form closed',
                false,
                $component
                    ->isEditingDetails
            );

            $assertSame(
                'cancellation',
                'form title cleared',
                '',
                $component
                    ->detailsTitle
            );

            /*
             |--------------------------------------------------------------------------
             | Validazione UI
             |--------------------------------------------------------------------------
             */

            $component
                ->startDetailsEdit();

            $component->detailsTitle =
                '   ';

            $component->detailsDescription =
                '   ';

            $casesBeforeInvalid =
                ProductCase::query()->count();

            $eventsBeforeInvalid =
                ProductCaseEvent::query()
                    ->count();

            $invalidRejected =
                false;

            $invalidFields =
                [];

            try {
                $component->saveDetails(
                    $updater
                );
            } catch (
                ValidationException $exception
            ) {
                $invalidRejected =
                    true;

                $invalidFields =
                    array_keys(
                        $exception->errors()
                    );
            }

            sort(
                $invalidFields
            );

            $assertSame(
                'validation',
                'blank fields rejected',
                true,
                $invalidRejected
            );

            $assertSame(
                'validation',
                'required fields reported',
                [
                    'detailsDescription',
                    'detailsTitle',
                ],
                $invalidFields
            );

            $assertSame(
                'validation',
                'invalid save creates no case',
                $casesBeforeInvalid,
                ProductCase::query()->count()
            );

            $assertSame(
                'validation',
                'invalid save creates no event',
                $eventsBeforeInvalid,
                ProductCaseEvent::query()
                    ->count()
            );

            /*
             |--------------------------------------------------------------------------
             | Salvataggio valido
             |--------------------------------------------------------------------------
             */

            $metadataBefore =
                $productCase->metadata;

            $requestDraftBefore =
                $productCase
                    ->request_draft;

            $mediaBeforeSave =
                Media::query()->count();

            $linksBeforeSave =
                DB::table(
                    'product_case_documents'
                )->count();

            $eventsBeforeSave =
                ProductCaseEvent::query()
                    ->count();

            $component->detailsTitle =
                '  Problema aggiornato dalla UI  ';

            $component->detailsDescription =
                '  Il prodotto presenta un funzionamento intermittente.  ';

            $component->detailsOccurredOn =
                today()
                    ->subDay()
                    ->toDateString();

            $component->detailsUsabilityStatus =
                ProductCase
                    ::USABILITY_PARTIALLY_USABLE;

            $component
                ->detailsAccidentalDamageDeclared =
                    '1';

            $component
                ->detailsAccidentalDamageNotes =
                    '  Possibile urto sul lato destro.  ';

            $component->saveDetails(
                $updater
            );

            $savedCase =
                $component
                    ->productCase
                    ->fresh();

            if ($savedCase === null) {
                throw new RuntimeException(
                    'La pratica aggiornata non è disponibile.'
                );
            }

            $assertSame(
                'save',
                'title normalized',
                'Problema aggiornato dalla UI',
                $savedCase->title
            );

            $assertSame(
                'save',
                'description normalized',
                'Il prodotto presenta un funzionamento intermittente.',
                $savedCase->description
            );

            $assertSame(
                'save',
                'original description preserved',
                $originalDescription,
                $savedCase
                    ->original_description
            );

            $assertSame(
                'save',
                'usability updated',
                ProductCase
                    ::USABILITY_PARTIALLY_USABLE,
                $savedCase
                    ->usability_status
            );

            $assertSame(
                'save',
                'damage declaration updated',
                true,
                $savedCase
                    ->accidental_damage_declared
            );

            $assertSame(
                'save',
                'damage notes normalized',
                'Possibile urto sul lato destro.',
                $savedCase
                    ->accidental_damage_notes
            );

            $assertSame(
                'protection',
                'status remains draft',
                ProductCase::STATUS_DRAFT,
                $savedCase->status
            );

            $assertSame(
                'protection',
                'metadata unchanged',
                $metadataBefore,
                $savedCase->metadata
            );

            $assertSame(
                'protection',
                'request draft unchanged',
                $requestDraftBefore,
                $savedCase
                    ->request_draft
            );

            $assertSame(
                'scope',
                'media unchanged',
                $mediaBeforeSave,
                Media::query()->count()
            );

            $assertSame(
                'scope',
                'document links unchanged',
                $linksBeforeSave,
                DB::table(
                    'product_case_documents'
                )->count()
            );

            $assertSame(
                'event',
                'one update event created',
                $eventsBeforeSave + 1,
                ProductCaseEvent::query()
                    ->count()
            );

            $assertSame(
                'component',
                'form closed after save',
                false,
                $component
                    ->isEditingDetails
            );

            $assertSame(
                'component',
                'usability label refreshed',
                'Parzialmente utilizzabile',
                $component
                    ->usabilityLabel
            );

            $assertSame(
                'component',
                'damage label refreshed',
                'Sì',
                $component
                    ->accidentalDamageLabel
            );

            $assertSame(
                'component',
                'success feedback exposed',
                'Dati della pratica aggiornati correttamente.',
                $component
                    ->detailsSuccessMessage
            );

            $timelineEvent =
                collect(
                    data_get(
                        $component->timeline,
                        'events',
                        []
                    )
                )
                    ->where(
                        'type',
                        ProductCaseEvent
                            ::TYPE_CASE_DETAILS_UPDATED
                    )
                    ->last();

            $assertSame(
                'timeline',
                'update event immediately visible',
                true,
                $timelineEvent !== null
            );

            $savedHtml =
                $render(
                    $component
                );

            $assertSame(
                'html',
                'updated description visible',
                true,
                str_contains(
                    $savedHtml,
                    'Il prodotto presenta un funzionamento intermittente.'
                )
            );

            $assertSame(
                'html',
                'success message rendered',
                true,
                str_contains(
                    $savedHtml,
                    'product-case-details-success'
                )
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
                            'Product Case Edit UI '
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

            $eventsBeforeCrossTeam =
                ProductCaseEvent::query()
                    ->count();

            $crossTeamRejected =
                false;

            try {
                $component
                    ->startDetailsEdit();
            } catch (
                AuthorizationException
            ) {
                $crossTeamRejected =
                    true;
            }

            $assertSame(
                'authorization',
                'cross-team edit rejected',
                true,
                $crossTeamRejected
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

            /*
             |--------------------------------------------------------------------------
             | Stato non modificabile
             |--------------------------------------------------------------------------
             */

            $cancelledCase =
                $transitionService
                    ->transition(
                        productCase:
                            $savedCase,

                        performedBy:
                            $user,

                        targetStatus:
                            ProductCase
                                ::STATUS_CANCELLED,
                    );

            $terminalComponent =
                app(
                    ProductCaseShow::class
                );

            $terminalComponent->mount(
                $cancelledCase
            );

            $terminalHtml =
                $render(
                    $terminalComponent
                );

            $assertSame(
                'state',
                'edit action hidden outside draft',
                false,
                str_contains(
                    $terminalHtml,
                    'start-product-case-details-edit'
                )
            );

            $assertSame(
                'state',
                'edit form hidden outside draft',
                false,
                str_contains(
                    $terminalHtml,
                    'product-case-details-edit-form'
                )
            );
        } catch (Throwable $exception) {
            $rows[] = [
                'runtime',
                'details edit UI workflow completed',
                'FAIL',
            ];

            $failures[] = [
                'scenario' =>
                    'runtime',

                'assertion' =>
                    'details edit UI workflow completed',

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
            'media count restored',
            $mediaBefore,
            Media::query()->count()
        );

        $assertSame(
            'rollback',
            'document links restored',
            $linksBefore,
            DB::table(
                'product_case_documents'
            )->count()
        );

        $assertSame(
            'rollback',
            'team count restored',
            $teamsBefore,
            DB::table('teams')->count()
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
            'Product case details edit UI checks passed.'
        );

        return self::SUCCESS;
    }
}