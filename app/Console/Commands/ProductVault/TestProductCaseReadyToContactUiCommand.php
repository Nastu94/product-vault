<?php

namespace App\Console\Commands\ProductVault;

use App\Livewire\ProductCases\ProductCaseShow;
use App\Models\Product;
use App\Models\ProductCase;
use App\Models\ProductCaseEvent;
use App\Models\User;
use App\Services\ProductCases\ProductCaseCreator;
use App\Services\ProductCases\ProductCaseDocumentSelector;
use App\Services\ProductCases\ProductCaseStatusTransitionService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\ViewErrorBag;
use RuntimeException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\Permission\PermissionRegistrar;
use Throwable;

final class TestProductCaseReadyToContactUiCommand
    extends Command
{
    protected $signature =
        'product-vault:test-product-case-ready-to-contact-ui';

    protected $description =
        'Verifica con rollback la transizione UI verso ready_to_contact.';

    public function handle(
        ProductCaseCreator $creator,
        ProductCaseDocumentSelector $documentSelector,
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

        $documentLinksBefore =
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

                    'requestDraftSuccessMessage' =>
                        $component
                            ->requestDraftSuccessMessage,

                    'requestDraftErrorMessage' =>
                        $component
                            ->requestDraftErrorMessage,

                    'isEditingRequestDraft' =>
                        $component
                            ->isEditingRequestDraft,

                    'requestDraftBody' =>
                        $component
                            ->requestDraftBody,

                    'workflowSuccessMessage' =>
                        $component
                            ->workflowSuccessMessage,

                    'workflowErrorMessage' =>
                        $component
                            ->workflowErrorMessage,
                ])
                ->render();
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
                || $product->documents->isEmpty()
            ) {
                throw new RuntimeException(
                    'Nessun prodotto con team, documento e garanzia completa utilizzabile per il test.'
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

            $document =
                $product->documents->first();

            if ($document === null) {
                throw new RuntimeException(
                    'Documento prodotto non disponibile.'
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

            $productCase =
                $creator->create(
                    product:
                        $product,

                    openedBy:
                        $user,

                    attributes: [
                        'title' =>
                            'Prodotto non funzionante',

                        'description' =>
                            'Il prodotto non completa correttamente l’avvio.',

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
             | Readiness iniziale incompleta
             |--------------------------------------------------------------------------
             */

            $assertSame(
                'initial',
                'case starts in draft',
                ProductCase::STATUS_DRAFT,
                $component
                    ->productCase
                    ->status
            );

            $assertSame(
                'initial',
                'readiness incomplete without document',
                false,
                data_get(
                    $component->readiness,
                    'is_ready_to_contact'
                )
            );

            $assertSame(
                'initial',
                'selected document blocker exposed',
                [
                    'selected_document',
                ],
                collect(
                    data_get(
                        $component->readiness,
                        'blocking_information',
                        []
                    )
                )
                    ->pluck('code')
                    ->values()
                    ->all()
            );

            $initialHtml =
                $render(
                    $component
                );

            $assertSame(
                'html',
                'disabled action visible',
                true,
                str_contains(
                    $initialHtml,
                    'mark-product-case-ready-to-contact-disabled'
                )
            );

            $assertSame(
                'html',
                'active action hidden while incomplete',
                false,
                str_contains(
                    $initialHtml,
                    'data-testid="mark-product-case-ready-to-contact"'
                )
            );

            $assertSame(
                'html',
                'contact action not introduced',
                false,
                str_contains(
                    $initialHtml,
                    'mark-product-case-contacted'
                )
            );

            /*
             |--------------------------------------------------------------------------
             | Readiness completa
             |--------------------------------------------------------------------------
             */

            $selected =
                $documentSelector->select(
                    productCase:
                        $productCase,

                    document:
                        $document,

                    selectedBy:
                        $user,
                );

            $assertSame(
                'readiness',
                'document selected',
                true,
                $selected
            );

            $readyComponent =
                app(
                    ProductCaseShow::class
                );

            $readyComponent->mount(
                $productCase->fresh()
            );

            $assertSame(
                'readiness',
                'case becomes operationally complete',
                true,
                data_get(
                    $readyComponent->readiness,
                    'is_ready_to_contact'
                )
            );

            $assertSame(
                'readiness',
                'blocking information empty',
                [],
                data_get(
                    $readyComponent->readiness,
                    'blocking_information',
                    []
                )
            );

            $readyHtml =
                $render(
                    $readyComponent
                );

            $assertSame(
                'html',
                'active ready action visible',
                true,
                str_contains(
                    $readyHtml,
                    'data-testid="mark-product-case-ready-to-contact"'
                )
            );

            $assertSame(
                'html',
                'disabled action hidden when complete',
                false,
                str_contains(
                    $readyHtml,
                    'mark-product-case-ready-to-contact-disabled'
                )
            );

            /*
             |--------------------------------------------------------------------------
             | Readiness cambiata dopo il rendering
             |--------------------------------------------------------------------------
             */

            $deselected =
                $documentSelector->deselect(
                    productCase:
                        $productCase->fresh(),

                    document:
                        $document,

                    deselectedBy:
                        $user,
                );

            $assertSame(
                'stale readiness',
                'document removed after render',
                true,
                $deselected
            );

            /*
             * Il componente conserva ancora lo snapshot precedente.
             */
            $assertSame(
                'stale readiness',
                'component snapshot was ready before click',
                true,
                data_get(
                    $readyComponent->readiness,
                    'is_ready_to_contact'
                )
            );

            $eventsBeforeRejectedTransition =
                ProductCaseEvent::query()
                    ->count();

            $readyComponent
                ->markReadyToContact(
                    $transitionService
                );

            $rejectedCase =
                ProductCase::query()
                    ->findOrFail(
                        $productCase->id
                    );

            $assertSame(
                'stale readiness',
                'failed transition keeps draft',
                ProductCase::STATUS_DRAFT,
                $rejectedCase->status
            );

            $assertSame(
                'stale readiness',
                'failed transition creates no event',
                $eventsBeforeRejectedTransition,
                ProductCaseEvent::query()
                    ->count()
            );

            $assertSame(
                'stale readiness',
                'readiness refreshed after rejection',
                false,
                data_get(
                    $readyComponent->readiness,
                    'is_ready_to_contact'
                )
            );

            $assertSame(
                'stale readiness',
                'controlled error exposed',
                'La pratica non è ancora pronta. Completa le informazioni bloccanti indicate nella sezione Completezza operativa.',
                $readyComponent
                    ->workflowErrorMessage
            );

            $assertSame(
                'stale readiness',
                'success absent after rejection',
                null,
                $readyComponent
                    ->workflowSuccessMessage
            );

            $rejectedHtml =
                $render(
                    $readyComponent
                );

            $assertSame(
                'html',
                'workflow error rendered',
                true,
                str_contains(
                    $rejectedHtml,
                    'product-case-workflow-error'
                )
            );

            $assertSame(
                'html',
                'disabled action restored',
                true,
                str_contains(
                    $rejectedHtml,
                    'mark-product-case-ready-to-contact-disabled'
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
                            'Product Case Ready UI '
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
                $readyComponent
                    ->markReadyToContact(
                        $transitionService
                    );
            } catch (
                AuthorizationException
            ) {
                $crossTeamRejected =
                    true;
            }

            $assertSame(
                'authorization',
                'cross-team transition rejected',
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
             | Transizione valida
             |--------------------------------------------------------------------------
             */

            $reselected =
                $documentSelector->select(
                    productCase:
                        $rejectedCase,

                    document:
                        $document,

                    selectedBy:
                        $user,
                );

            $assertSame(
                'transition',
                'document reselected',
                true,
                $reselected
            );

            $transitionComponent =
                app(
                    ProductCaseShow::class
                );

            $transitionComponent->mount(
                $rejectedCase->fresh()
            );

            $assertSame(
                'transition',
                'fresh readiness complete',
                true,
                data_get(
                    $transitionComponent
                        ->readiness,
                    'is_ready_to_contact'
                )
            );

            $caseBeforeTransition =
                ProductCase::query()
                    ->findOrFail(
                        $productCase->id
                    );

            $requestDraftBeforeTransition =
                $caseBeforeTransition
                    ->request_draft;

            $requestMetadataBeforeTransition =
                $caseBeforeTransition
                    ->metadata;

            $selectedDocumentsBeforeTransition =
                DB::table(
                    'product_case_documents'
                )
                    ->where(
                        'product_case_id',
                        $productCase->id
                    )
                    ->orderBy(
                        'document_id'
                    )
                    ->pluck(
                        'document_id'
                    )
                    ->map(
                        fn (mixed $id): int =>
                            (int) $id
                    )
                    ->all();

            $mediaBeforeTransition =
                Media::query()
                    ->where(
                        'model_type',
                        $productCase
                            ->getMorphClass()
                    )
                    ->where(
                        'model_id',
                        $productCase->id
                    )
                    ->count();

            $eventsBeforeTransition =
                ProductCaseEvent::query()
                    ->count();

            $transitionComponent
                ->markReadyToContact(
                    $transitionService
                );

            $transitionedCase =
                ProductCase::query()
                    ->findOrFail(
                        $productCase->id
                    );

            $assertSame(
                'transition',
                'status changed to ready',
                ProductCase
                    ::STATUS_READY_TO_CONTACT,
                $transitionedCase->status
            );

            $assertSame(
                'transition',
                'one status event created',
                $eventsBeforeTransition + 1,
                ProductCaseEvent::query()
                    ->count()
            );

            $statusEvent =
                ProductCaseEvent::query()
                    ->where(
                        'product_case_id',
                        $productCase->id
                    )
                    ->where(
                        'event_type',
                        ProductCaseEvent
                            ::TYPE_STATUS_CHANGED
                    )
                    ->orderByDesc('id')
                    ->first();

            $assertSame(
                'event',
                'status event available',
                true,
                $statusEvent !== null
            );

            $assertSame(
                'event',
                'event previous status',
                ProductCase::STATUS_DRAFT,
                data_get(
                    $statusEvent?->metadata,
                    'from_status'
                )
            );

            $assertSame(
                'event',
                'event target status',
                ProductCase
                    ::STATUS_READY_TO_CONTACT,
                data_get(
                    $statusEvent?->metadata,
                    'to_status'
                )
            );

            $assertSame(
                'transition',
                'contact timestamp remains empty',
                null,
                $transitionedCase
                    ->contacted_at
            );

            $assertSame(
                'transition',
                'later timestamps remain empty',
                true,
                $transitionedCase
                        ->resolved_at === null
                    && $transitionedCase
                        ->closed_at === null
                    && $transitionedCase
                        ->cancelled_at === null
            );

            $assertSame(
                'scope',
                'request draft unchanged',
                $requestDraftBeforeTransition,
                $transitionedCase
                    ->request_draft
            );

            $assertSame(
                'scope',
                'request metadata unchanged',
                $requestMetadataBeforeTransition,
                $transitionedCase
                    ->metadata
            );

            $selectedDocumentsAfterTransition =
                DB::table(
                    'product_case_documents'
                )
                    ->where(
                        'product_case_id',
                        $productCase->id
                    )
                    ->orderBy(
                        'document_id'
                    )
                    ->pluck(
                        'document_id'
                    )
                    ->map(
                        fn (mixed $id): int =>
                            (int) $id
                    )
                    ->all();

            $assertSame(
                'scope',
                'selected documents unchanged',
                $selectedDocumentsBeforeTransition,
                $selectedDocumentsAfterTransition
            );

            $assertSame(
                'scope',
                'media unchanged',
                $mediaBeforeTransition,
                Media::query()
                    ->where(
                        'model_type',
                        $productCase
                            ->getMorphClass()
                    )
                    ->where(
                        'model_id',
                        $productCase->id
                    )
                    ->count()
            );

            /*
             |--------------------------------------------------------------------------
             | Stato Livewire e timeline
             |--------------------------------------------------------------------------
             */

            $assertSame(
                'component',
                'case refreshed immediately',
                ProductCase
                    ::STATUS_READY_TO_CONTACT,
                $transitionComponent
                    ->productCase
                    ->status
            );

            $assertSame(
                'component',
                'status label refreshed',
                'Pronta per il contatto',
                $transitionComponent
                    ->statusLabel
            );

            $assertSame(
                'component',
                'success exposed',
                'La pratica è ora pronta per il contatto.',
                $transitionComponent
                    ->workflowSuccessMessage
            );

            $assertSame(
                'component',
                'error cleared',
                null,
                $transitionComponent
                    ->workflowErrorMessage
            );

            $assertSame(
                'component',
                'details editor closed',
                false,
                $transitionComponent
                    ->isEditingDetails
            );

            $assertSame(
                'component',
                'document manager closed',
                false,
                $transitionComponent
                    ->isManagingDocuments
            );

            $assertSame(
                'component',
                'photo manager closed',
                false,
                $transitionComponent
                    ->isManagingPhotos
            );

            $assertSame(
                'component',
                'draft editor closed',
                false,
                $transitionComponent
                    ->isEditingRequestDraft
            );

            $assertSame(
                'timeline',
                'current status refreshed',
                ProductCase
                    ::STATUS_READY_TO_CONTACT,
                data_get(
                    $transitionComponent
                        ->timeline,
                    'current_status'
                )
            );

            $statusTimelineEvent =
                collect(
                    data_get(
                        $transitionComponent
                            ->timeline,
                        'events',
                        []
                    )
                )
                    ->where(
                        'type',
                        ProductCaseEvent
                            ::TYPE_STATUS_CHANGED
                    )
                    ->last();

            $assertSame(
                'timeline',
                'status event visible immediately',
                true,
                $statusTimelineEvent !== null
            );

            $assertSame(
                'timeline',
                'timeline previous status',
                ProductCase::STATUS_DRAFT,
                data_get(
                    $statusTimelineEvent,
                    'details.from_status'
                )
            );

            $assertSame(
                'timeline',
                'timeline target status',
                ProductCase
                    ::STATUS_READY_TO_CONTACT,
                data_get(
                    $statusTimelineEvent,
                    'details.to_status'
                )
            );

            $transitionedHtml =
                $render(
                    $transitionComponent
                );

            $assertSame(
                'html',
                'workflow success rendered',
                true,
                str_contains(
                    $transitionedHtml,
                    'product-case-workflow-success'
                )
            );

            $assertSame(
                'html',
                'ready action hidden after transition',
                false,
                str_contains(
                    $transitionedHtml,
                    'mark-product-case-ready-to-contact'
                )
            );

            $assertSame(
                'html',
                'ready status rendered',
                true,
                str_contains(
                    $transitionedHtml,
                    'Pronta per il contatto'
                )
            );

            /*
             |--------------------------------------------------------------------------
             | Secondo tentativo
             |--------------------------------------------------------------------------
             */

            $eventsBeforeSecondAttempt =
                ProductCaseEvent::query()
                    ->count();

            $secondAttemptRejected =
                false;

            try {
                $transitionComponent
                    ->markReadyToContact(
                        $transitionService
                    );
            } catch (
                RuntimeException
            ) {
                $secondAttemptRejected =
                    true;
            }

            $assertSame(
                'guard',
                'second ready transition rejected',
                true,
                $secondAttemptRejected
            );

            $assertSame(
                'guard',
                'second attempt creates no event',
                $eventsBeforeSecondAttempt,
                ProductCaseEvent::query()
                    ->count()
            );

            $assertSame(
                'guard',
                'status remains ready',
                ProductCase
                    ::STATUS_READY_TO_CONTACT,
                ProductCase::query()
                    ->findOrFail(
                        $productCase->id
                    )
                    ->status
            );
        } catch (Throwable $exception) {
            $rows[] = [
                'runtime',
                'ready-to-contact UI workflow completed',
                'FAIL',
            ];

            $failures[] = [
                'scenario' =>
                    'runtime',

                'assertion' =>
                    'ready-to-contact UI workflow completed',

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
            'document links restored',
            $documentLinksBefore,
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
            'Product case ready-to-contact UI checks passed.'
        );

        return self::SUCCESS;
    }
}