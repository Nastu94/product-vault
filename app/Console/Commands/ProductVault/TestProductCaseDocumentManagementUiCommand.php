<?php

namespace App\Console\Commands\ProductVault;

use App\Livewire\ProductCases\ProductCaseShow;
use App\Models\Document;
use App\Models\Product;
use App\Models\ProductCase;
use App\Models\ProductCaseEvent;
use App\Models\User;
use App\Services\ProductCases\ProductCaseCreator;
use App\Services\ProductCases\ProductCaseDocumentSelector;
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

final class TestProductCaseDocumentManagementUiCommand
    extends Command
{
    protected $signature =
        'product-vault:test-product-case-document-management-ui';

    protected $description =
        'Verifica con rollback la gestione UI dei documenti di una pratica.';

    public function handle(
        ProductCaseCreator $creator,
        ProductCaseDocumentSelector $selector
    ): int {
        $rows = [];
        $failures = [];

        $createdCaseId = null;
        $createdDocumentId = null;

        $casesBefore =
            ProductCase::query()->count();

        $eventsBefore =
            ProductCaseEvent::query()->count();

        $documentsBefore =
            Document::query()->count();

        $mediaBefore =
            Media::query()->count();

        $caseDocumentLinksBefore =
            DB::table(
                'product_case_documents'
            )->count();

        $productDocumentLinksBefore =
            DB::table(
                'product_documents'
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
                    'Nessun prodotto con team, documenti e garanzia utilizzabile per il test.'
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

            $primaryDocument =
                $product
                    ->documents
                    ->first();

            if ($primaryDocument === null) {
                throw new RuntimeException(
                    'Documento principale non disponibile.'
                );
            }

            /*
             * Creiamo un secondo documento controllato, così il test non
             * dipende dal numero di documenti realmente presenti nel database.
             */
            $secondaryDocument =
                $primaryDocument
                    ->replicate();

            $secondaryDocument->forceFill([
                'team_id' =>
                    $product->team_id,

                'uploaded_by_user_id' =>
                    $user->id,

                'original_filename' =>
                    'PV_CASE_SECONDARY_'
                    . Str::uuid()
                    . '.pdf',
            ]);

            $secondaryDocument->save();

            $createdDocumentId =
                (int) $secondaryDocument->id;

            $product
                ->documents()
                ->attach(
                    $secondaryDocument->id,
                    [
                        'relationship_type_id' =>
                            null,

                        'linked_by_user_id' =>
                            $user->id,

                        'notes' =>
                            'Documento sintetico per il test UI.',
                    ]
                );

            $productCase =
                $creator->create(
                    product:
                        $product,

                    openedBy:
                        $user,

                    attributes: [
                        'title' =>
                            'Problema completo per gestione documenti',

                        'description' =>
                            'Il prodotto non funziona correttamente durante il normale utilizzo.',

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

            $caseMetadataBefore =
                $productCase->metadata;

            $requestDraftBefore =
                $productCase
                    ->request_draft;

            $caseUpdatedAtBefore =
                $productCase
                    ->updated_at
                    ?->toISOString();

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
                'manager starts closed',
                false,
                $component
                    ->isManagingDocuments
            );

            $selectableIds =
                collect(
                    $component
                        ->selectableDocuments
                )
                    ->pluck('id')
                    ->map(
                        fn (mixed $id): int =>
                            (int) $id
                    )
                    ->all();

            $assertSame(
                'initial',
                'primary document selectable',
                true,
                in_array(
                    (int) $primaryDocument->id,
                    $selectableIds,
                    true
                )
            );

            $assertSame(
                'initial',
                'secondary document selectable',
                true,
                in_array(
                    (int) $secondaryDocument->id,
                    $selectableIds,
                    true
                )
            );

            $assertSame(
                'readiness',
                'case starts incomplete',
                false,
                data_get(
                    $component->readiness,
                    'is_ready_to_contact'
                )
            );

            $initialBlockerCodes =
                collect(
                    data_get(
                        $component->readiness,
                        'blocking_information',
                        []
                    )
                )
                    ->pluck('code')
                    ->all();

            $assertSame(
                'readiness',
                'missing document blocker present',
                true,
                in_array(
                    'selected_document',
                    $initialBlockerCodes,
                    true
                )
            );

            $closedHtml =
                $render(
                    $component
                );

            $assertSame(
                'html',
                'management action visible',
                true,
                str_contains(
                    $closedHtml,
                    'start-product-case-document-management'
                )
            );

            $assertSame(
                'html',
                'manager hidden initially',
                false,
                str_contains(
                    $closedHtml,
                    'product-case-document-manager'
                )
            );

            /*
             |--------------------------------------------------------------------------
             | Apertura pannello
             |--------------------------------------------------------------------------
             */

            $component
                ->startDocumentManagement();

            $assertSame(
                'manager',
                'manager opened',
                true,
                $component
                    ->isManagingDocuments
            );

            $openHtml =
                $render(
                    $component
                );

            $assertSame(
                'html',
                'manager rendered',
                true,
                str_contains(
                    $openHtml,
                    'product-case-document-manager'
                )
            );

            $assertSame(
                'html',
                'selection action rendered',
                true,
                str_contains(
                    $openHtml,
                    'wire:submit.prevent="selectDocument"'
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
             | Validazione selezione vuota
             |--------------------------------------------------------------------------
             */

            $eventsBeforeInvalid =
                ProductCaseEvent::query()
                    ->count();

            $linksBeforeInvalid =
                DB::table(
                    'product_case_documents'
                )->count();

            $emptySelectionRejected =
                false;

            $invalidFields =
                [];

            try {
                $component->selectDocument(
                    $selector
                );
            } catch (
                ValidationException $exception
            ) {
                $emptySelectionRejected =
                    true;

                $invalidFields =
                    array_keys(
                        $exception->errors()
                    );
            }

            $assertSame(
                'validation',
                'empty selection rejected',
                true,
                $emptySelectionRejected
            );

            $assertSame(
                'validation',
                'document field reported',
                [
                    'documentToSelectId',
                ],
                $invalidFields
            );

            $assertSame(
                'validation',
                'invalid selection creates no event',
                $eventsBeforeInvalid,
                ProductCaseEvent::query()
                    ->count()
            );

            $assertSame(
                'validation',
                'invalid selection creates no link',
                $linksBeforeInvalid,
                DB::table(
                    'product_case_documents'
                )->count()
            );

            /*
             |--------------------------------------------------------------------------
             | Selezione valida
             |--------------------------------------------------------------------------
             */

            $eventsBeforeSelection =
                ProductCaseEvent::query()
                    ->count();

            $linksBeforeSelection =
                DB::table(
                    'product_case_documents'
                )->count();

            $component->documentToSelectId =
                (string) $primaryDocument->id;

            $component->documentSelectionNotes =
                '  Documento principale della pratica.  ';

            $component->selectDocument(
                $selector
            );

            $selection = DB::table(
                'product_case_documents'
            )
                ->where(
                    'product_case_id',
                    $productCase->id
                )
                ->where(
                    'document_id',
                    $primaryDocument->id
                )
                ->first();

            $assertSame(
                'selection',
                'document link created',
                true,
                $selection !== null
            );

            $assertSame(
                'selection',
                'selection note normalized',
                'Documento principale della pratica.',
                $selection?->notes
            );

            $assertSame(
                'selection',
                'selector stored',
                (int) $user->id,
                (int) $selection
                    ?->selected_by_user_id
            );

            $assertSame(
                'selection',
                'one link created',
                $linksBeforeSelection + 1,
                DB::table(
                    'product_case_documents'
                )->count()
            );

            $assertSame(
                'selection',
                'one event created',
                $eventsBeforeSelection + 1,
                ProductCaseEvent::query()
                    ->count()
            );

            $selectionEvent =
                ProductCaseEvent::query()
                    ->where(
                        'product_case_id',
                        $productCase->id
                    )
                    ->where(
                        'event_type',
                        ProductCaseEvent
                            ::TYPE_DOCUMENT_SELECTED
                    )
                    ->orderByDesc('id')
                    ->first();

            $assertSame(
                'selection',
                'selection event available',
                true,
                $selectionEvent !== null
            );

            $assertSame(
                'selection',
                'event document id',
                (int) $primaryDocument->id,
                (int) data_get(
                    $selectionEvent?->metadata,
                    'document_id'
                )
            );

            $assertSame(
                'selection',
                'event note stored',
                'Documento principale della pratica.',
                data_get(
                    $selectionEvent?->metadata,
                    'notes'
                )
            );

            $selectedIds =
                $component
                    ->productCase
                    ->documents
                    ->pluck('id')
                    ->map(
                        fn (mixed $id): int =>
                            (int) $id
                    )
                    ->all();

            $assertSame(
                'component',
                'selected document immediately visible',
                true,
                in_array(
                    (int) $primaryDocument->id,
                    $selectedIds,
                    true
                )
            );

            $selectableAfterSelection =
                collect(
                    $component
                        ->selectableDocuments
                )
                    ->pluck('id')
                    ->map(
                        fn (mixed $id): int =>
                            (int) $id
                    )
                    ->all();

            $assertSame(
                'component',
                'selected document removed from choices',
                false,
                in_array(
                    (int) $primaryDocument->id,
                    $selectableAfterSelection,
                    true
                )
            );

            $assertSame(
                'component',
                'secondary document remains selectable',
                true,
                in_array(
                    (int) $secondaryDocument->id,
                    $selectableAfterSelection,
                    true
                )
            );

            $assertSame(
                'readiness',
                'case becomes ready after selection',
                true,
                data_get(
                    $component->readiness,
                    'is_ready_to_contact'
                )
            );

            $assertSame(
                'readiness',
                'one valid selected document',
                1,
                data_get(
                    $component->readiness,
                    'facts.evidence.valid_selected_document_count'
                )
            );

            $assertSame(
                'component',
                'selection success exposed',
                'Documento aggiunto alla pratica.',
                $component
                    ->documentsSuccessMessage
            );

            $timelineSelection =
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
                            ::TYPE_DOCUMENT_SELECTED
                    )
                    ->last();

            $assertSame(
                'timeline',
                'selection immediately visible',
                true,
                $timelineSelection !== null
            );

            $assertSame(
                'timeline',
                'selection reference current',
                'selected',
                data_get(
                    $timelineSelection,
                    'reference.state'
                )
            );

            /*
             |--------------------------------------------------------------------------
             | Duplicazione rifiutata dalla UI
             |--------------------------------------------------------------------------
             */

            $eventsBeforeDuplicate =
                ProductCaseEvent::query()
                    ->count();

            $linksBeforeDuplicate =
                DB::table(
                    'product_case_documents'
                )->count();

            $component->documentToSelectId =
                (string) $primaryDocument->id;

            $duplicateRejected =
                false;

            try {
                $component->selectDocument(
                    $selector
                );
            } catch (
                ValidationException
            ) {
                $duplicateRejected =
                    true;
            }

            $assertSame(
                'idempotence',
                'already selected document rejected by UI',
                true,
                $duplicateRejected
            );

            $assertSame(
                'idempotence',
                'duplicate attempt creates no event',
                $eventsBeforeDuplicate,
                ProductCaseEvent::query()
                    ->count()
            );

            $assertSame(
                'idempotence',
                'duplicate attempt creates no link',
                $linksBeforeDuplicate,
                DB::table(
                    'product_case_documents'
                )->count()
            );

            /*
             |--------------------------------------------------------------------------
             | Rendering della selezione
             |--------------------------------------------------------------------------
             */

            $selectedHtml =
                $render(
                    $component
                );

            $assertSame(
                'html',
                'selected filename visible',
                true,
                str_contains(
                    $selectedHtml,
                    e(
                        $primaryDocument
                            ->original_filename
                    )
                )
            );

            $assertSame(
                'html',
                'selection note visible',
                true,
                str_contains(
                    $selectedHtml,
                    'Documento principale della pratica.'
                )
            );

            $assertSame(
                'html',
                'remove action visible',
                true,
                str_contains(
                    $selectedHtml,
                    'deselect-product-case-document-'
                    . $primaryDocument->id
                )
            );

            /*
             |--------------------------------------------------------------------------
             | Deselezione
             |--------------------------------------------------------------------------
             */

            $eventsBeforeDeselection =
                ProductCaseEvent::query()
                    ->count();

            $linksBeforeDeselection =
                DB::table(
                    'product_case_documents'
                )->count();

            $component->deselectDocument(
                documentId:
                    (int) $primaryDocument->id,

                selector:
                    $selector,
            );

            $linkStillExists =
                DB::table(
                    'product_case_documents'
                )
                    ->where(
                        'product_case_id',
                        $productCase->id
                    )
                    ->where(
                        'document_id',
                        $primaryDocument->id
                    )
                    ->exists();

            $assertSame(
                'deselection',
                'case link removed',
                false,
                $linkStillExists
            );

            $assertSame(
                'deselection',
                'one link removed',
                $linksBeforeDeselection - 1,
                DB::table(
                    'product_case_documents'
                )->count()
            );

            $assertSame(
                'deselection',
                'one event created',
                $eventsBeforeDeselection + 1,
                ProductCaseEvent::query()
                    ->count()
            );

            $deselectionEvent =
                ProductCaseEvent::query()
                    ->where(
                        'product_case_id',
                        $productCase->id
                    )
                    ->where(
                        'event_type',
                        ProductCaseEvent
                            ::TYPE_DOCUMENT_DESELECTED
                    )
                    ->orderByDesc('id')
                    ->first();

            $assertSame(
                'deselection',
                'deselection event available',
                true,
                $deselectionEvent !== null
            );

            $assertSame(
                'deselection',
                'original selector preserved',
                (int) $user->id,
                (int) data_get(
                    $deselectionEvent?->metadata,
                    'original_selected_by_user_id'
                )
            );

            $assertSame(
                'deselection',
                'selection note preserved',
                'Documento principale della pratica.',
                data_get(
                    $deselectionEvent?->metadata,
                    'notes'
                )
            );

            $selectableAfterDeselection =
                collect(
                    $component
                        ->selectableDocuments
                )
                    ->pluck('id')
                    ->map(
                        fn (mixed $id): int =>
                            (int) $id
                    )
                    ->all();

            $assertSame(
                'component',
                'removed document becomes selectable',
                true,
                in_array(
                    (int) $primaryDocument->id,
                    $selectableAfterDeselection,
                    true
                )
            );

            $assertSame(
                'readiness',
                'case becomes incomplete after removal',
                false,
                data_get(
                    $component->readiness,
                    'is_ready_to_contact'
                )
            );

            $blockerCodesAfterRemoval =
                collect(
                    data_get(
                        $component->readiness,
                        'blocking_information',
                        []
                    )
                )
                    ->pluck('code')
                    ->all();

            $assertSame(
                'readiness',
                'document blocker restored',
                true,
                in_array(
                    'selected_document',
                    $blockerCodesAfterRemoval,
                    true
                )
            );

            $timelineEvents =
                collect(
                    data_get(
                        $component->timeline,
                        'events',
                        []
                    )
                );

            $timelineSelection =
                $timelineEvents
                    ->where(
                        'type',
                        ProductCaseEvent
                            ::TYPE_DOCUMENT_SELECTED
                    )
                    ->last();

            $timelineDeselection =
                $timelineEvents
                    ->where(
                        'type',
                        ProductCaseEvent
                            ::TYPE_DOCUMENT_DESELECTED
                    )
                    ->last();

            $assertSame(
                'timeline',
                'old selection reflects removal',
                'removed',
                data_get(
                    $timelineSelection,
                    'reference.state'
                )
            );

            $assertSame(
                'timeline',
                'deselection reference removed',
                'removed',
                data_get(
                    $timelineDeselection,
                    'reference.state'
                )
            );

            $assertSame(
                'timeline',
                'deselection note exposed',
                'Documento principale della pratica.',
                data_get(
                    $timelineDeselection,
                    'details.notes'
                )
            );

            $assertSame(
                'component',
                'deselection success exposed',
                'Documento rimosso dalla pratica.',
                $component
                    ->documentsSuccessMessage
            );

            /*
             |--------------------------------------------------------------------------
             | Protezioni e scope
             |--------------------------------------------------------------------------
             */

            $productLinkPreserved =
                DB::table(
                    'product_documents'
                )
                    ->where(
                        'product_id',
                        $product->id
                    )
                    ->where(
                        'document_id',
                        $primaryDocument->id
                    )
                    ->exists();

            $assertSame(
                'protection',
                'product document link preserved',
                true,
                $productLinkPreserved
            );

            $assertSame(
                'protection',
                'document record preserved',
                true,
                Document::query()
                    ->whereKey(
                        $primaryDocument->id
                    )
                    ->exists()
            );

            $currentCase =
                ProductCase::query()
                    ->findOrFail(
                        $productCase->id
                    );

            $assertSame(
                'protection',
                'case remains draft',
                ProductCase::STATUS_DRAFT,
                $currentCase->status
            );

            $assertSame(
                'protection',
                'case metadata unchanged',
                $caseMetadataBefore,
                $currentCase->metadata
            );

            $assertSame(
                'protection',
                'request draft unchanged',
                $requestDraftBefore,
                $currentCase
                    ->request_draft
            );

            $assertSame(
                'protection',
                'case timestamp unchanged',
                $caseUpdatedAtBefore,
                $currentCase
                    ->updated_at
                    ?->toISOString()
            );

            $assertSame(
                'scope',
                'media unchanged',
                $mediaBefore,
                Media::query()->count()
            );

            /*
             |--------------------------------------------------------------------------
             | Chiusura pannello
             |--------------------------------------------------------------------------
             */

            $component
                ->cancelDocumentManagement();

            $assertSame(
                'cancellation',
                'manager closed',
                false,
                $component
                    ->isManagingDocuments
            );

            $assertSame(
                'cancellation',
                'document selection reset',
                '',
                $component
                    ->documentToSelectId
            );

            $assertSame(
                'cancellation',
                'selection notes reset',
                null,
                $component
                    ->documentSelectionNotes
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
                            'Product Case Documents UI '
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

            $linksBeforeCrossTeam =
                DB::table(
                    'product_case_documents'
                )->count();

            $crossTeamRejected =
                false;

            try {
                $component
                    ->startDocumentManagement();
            } catch (
                AuthorizationException
            ) {
                $crossTeamRejected =
                    true;
            }

            $assertSame(
                'authorization',
                'cross-team management rejected',
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

            $assertSame(
                'authorization',
                'cross-team attempt changes no link',
                $linksBeforeCrossTeam,
                DB::table(
                    'product_case_documents'
                )->count()
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
                'document management UI workflow completed',
                'FAIL',
            ];

            $failures[] = [
                'scenario' =>
                    'runtime',

                'assertion' =>
                    'document management UI workflow completed',

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
            'document count restored',
            $documentsBefore,
            Document::query()->count()
        );

        $assertSame(
            'rollback',
            'media count restored',
            $mediaBefore,
            Media::query()->count()
        );

        $assertSame(
            'rollback',
            'case document links restored',
            $caseDocumentLinksBefore,
            DB::table(
                'product_case_documents'
            )->count()
        );

        $assertSame(
            'rollback',
            'product document links restored',
            $productDocumentLinksBefore,
            DB::table(
                'product_documents'
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

        if ($createdDocumentId !== null) {
            $assertSame(
                'rollback',
                'temporary document removed',
                false,
                Document::withTrashed()
                    ->whereKey(
                        $createdDocumentId
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
            'Product case document management UI checks passed.'
        );

        return self::SUCCESS;
    }
}