<?php

namespace App\Console\Commands\ProductVault;

use App\Livewire\ProductCases\ProductCaseShow;
use App\Models\Product;
use App\Models\ProductCase;
use App\Models\ProductCaseEvent;
use App\Models\User;
use App\Services\ProductCases\ProductCaseCreator;
use App\Services\ProductCases\ProductCaseRequestDraftEditor;
use App\Services\ProductCases\ProductCaseRequestDraftGenerator;
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

final class TestProductCaseRequestDraftEditUiCommand
    extends Command
{
    protected $signature =
        'product-vault:test-product-case-request-draft-edit-ui';

    protected $description =
        'Verifica con rollback la modifica manuale UI della bozza di richiesta.';

    public function handle(
        ProductCaseCreator $creator,
        ProductCaseRequestDraftGenerator $generator,
        ProductCaseRequestDraftEditor $editor,
        ProductCaseStatusTransitionService $transitionService
    ): int {
        $rows = [];
        $failures = [];

        $createdCaseIds = [];

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

            $manualCase =
                $creator->create(
                    product:
                        $product,

                    openedBy:
                        $user,

                    attributes: [
                        'title' =>
                            'Bozza manuale da compilare',

                        'description' =>
                            'Il prodotto presenta un problema da descrivere al venditore.',

                        'occurred_on' =>
                            today()
                                ->toDateString(),

                        'usability_status' =>
                            ProductCase
                                ::USABILITY_PARTIALLY_USABLE,

                        'accidental_damage_declared' =>
                            false,
                    ],
                );

            $createdCaseIds[] =
                (int) $manualCase->id;

            $component =
                app(
                    ProductCaseShow::class
                );

            $component->mount(
                $manualCase
            );

            $initialStatus =
                $manualCase->status;

            $documentLinksBeforeOperations =
                DB::table(
                    'product_case_documents'
                )->count();

            $mediaBeforeOperations =
                Media::query()->count();

            /*
             |--------------------------------------------------------------------------
             | Stato iniziale
             |--------------------------------------------------------------------------
             */

            $initialHtml =
                $render(
                    $component
                );

            $assertSame(
                'initial',
                'editor starts closed',
                false,
                $component
                    ->isEditingRequestDraft
            );

            $assertSame(
                'initial',
                'draft body starts empty',
                '',
                $component
                    ->requestDraftBody
            );

            $assertSame(
                'html',
                'manual action visible',
                true,
                str_contains(
                    $initialHtml,
                    'start-product-case-request-draft-edit'
                )
            );

            $assertSame(
                'html',
                'manual creation label visible',
                true,
                str_contains(
                    $initialHtml,
                    'Scrivi manualmente'
                )
            );

            $assertSame(
                'html',
                'editor hidden initially',
                false,
                str_contains(
                    $initialHtml,
                    'product-case-request-draft-editor'
                )
            );

            /*
             |--------------------------------------------------------------------------
             | Salvataggio senza editor aperto
             |--------------------------------------------------------------------------
             */

            $eventsBeforeClosedAttempt =
                ProductCaseEvent::query()
                    ->count();

            $closedAttemptRejected =
                false;

            try {
                $component
                    ->saveRequestDraft(
                        $editor
                    );
            } catch (
                RuntimeException
            ) {
                $closedAttemptRejected =
                    true;
            }

            $assertSame(
                'guard',
                'save rejected when editor closed',
                true,
                $closedAttemptRejected
            );

            $assertSame(
                'guard',
                'closed attempt creates no event',
                $eventsBeforeClosedAttempt,
                ProductCaseEvent::query()
                    ->count()
            );

            /*
             |--------------------------------------------------------------------------
             | Apertura editor
             |--------------------------------------------------------------------------
             */

            $component
                ->startRequestDraftEdit();

            $assertSame(
                'editor',
                'editor opened',
                true,
                $component
                    ->isEditingRequestDraft
            );

            $assertSame(
                'editor',
                'empty case loads empty body',
                '',
                $component
                    ->requestDraftBody
            );

            $assertSame(
                'editor',
                'details editor closed',
                false,
                $component
                    ->isEditingDetails
            );

            $assertSame(
                'editor',
                'document manager closed',
                false,
                $component
                    ->isManagingDocuments
            );

            $assertSame(
                'editor',
                'photo manager closed',
                false,
                $component
                    ->isManagingPhotos
            );

            $openHtml =
                $render(
                    $component
                );

            $assertSame(
                'html',
                'editor rendered',
                true,
                str_contains(
                    $openHtml,
                    'product-case-request-draft-editor'
                )
            );

            $assertSame(
                'html',
                'textarea rendered',
                true,
                str_contains(
                    $openHtml,
                    'product-case-request-draft-body'
                )
            );

            $assertSame(
                'html',
                'save action rendered',
                true,
                str_contains(
                    $openHtml,
                    'wire:submit.prevent="saveRequestDraft"'
                )
            );

            $assertSame(
                'html',
                'automatic generation hidden during editing',
                false,
                str_contains(
                    $openHtml,
                    'generate-product-case-request-draft'
                )
            );

            /*
             |--------------------------------------------------------------------------
             | Validazione testo vuoto
             |--------------------------------------------------------------------------
             */

            $eventsBeforeInvalid =
                ProductCaseEvent::query()
                    ->count();

            $invalidRejected =
                false;

            $invalidFields =
                [];

            try {
                $component
                    ->saveRequestDraft(
                        $editor
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

            $assertSame(
                'validation',
                'empty draft rejected',
                true,
                $invalidRejected
            );

            $assertSame(
                'validation',
                'draft field reported',
                [
                    'requestDraftBody',
                ],
                $invalidFields
            );

            $assertSame(
                'validation',
                'invalid draft creates no event',
                $eventsBeforeInvalid,
                ProductCaseEvent::query()
                    ->count()
            );

            $assertSame(
                'validation',
                'invalid draft not persisted',
                null,
                ProductCase::query()
                    ->findOrFail(
                        $manualCase->id
                    )
                    ->request_draft
            );

            /*
             |--------------------------------------------------------------------------
             | Annullamento
             |--------------------------------------------------------------------------
             */

            $component->requestDraftBody =
                'Testo da non salvare';

            $eventsBeforeCancel =
                ProductCaseEvent::query()
                    ->count();

            $component
                ->cancelRequestDraftEdit();

            $assertSame(
                'cancellation',
                'editor closed',
                false,
                $component
                    ->isEditingRequestDraft
            );

            $assertSame(
                'cancellation',
                'draft body reset',
                '',
                $component
                    ->requestDraftBody
            );

            $assertSame(
                'cancellation',
                'cancel creates no event',
                $eventsBeforeCancel,
                ProductCaseEvent::query()
                    ->count()
            );

            $assertSame(
                'cancellation',
                'cancel persists no draft',
                null,
                ProductCase::query()
                    ->findOrFail(
                        $manualCase->id
                    )
                    ->request_draft
            );

            /*
             |--------------------------------------------------------------------------
             | Prima bozza manuale
             |--------------------------------------------------------------------------
             */

            $component
                ->startRequestDraftEdit();

            $component->requestDraftBody =
                "  Buongiorno,\r\n\r\n"
                . "richiedo assistenza per il prodotto.  ";

            $normalizedDraft =
                "Buongiorno,\n\n"
                . 'richiedo assistenza per il prodotto.';

            $eventsBeforeFirstSave =
                ProductCaseEvent::query()
                    ->count();

            $component
                ->saveRequestDraft(
                    $editor
                );

            $firstSavedCase =
                ProductCase::query()
                    ->findOrFail(
                        $manualCase->id
                    );

            $assertSame(
                'manual creation',
                'normalized draft persisted',
                $normalizedDraft,
                $firstSavedCase
                    ->request_draft
            );

            $assertSame(
                'manual creation',
                'manual source stored',
                ProductCase
                    ::REQUEST_DRAFT_SOURCE_MANUAL,
                data_get(
                    $firstSavedCase->metadata,
                    ProductCase
                        ::REQUEST_DRAFT_CURRENT_METADATA_KEY
                        . '.source'
                )
            );

            $assertSame(
                'manual creation',
                'current hash stored',
                hash(
                    'sha256',
                    $normalizedDraft
                ),
                data_get(
                    $firstSavedCase->metadata,
                    ProductCase
                        ::REQUEST_DRAFT_CURRENT_METADATA_KEY
                        . '.sha256'
                )
            );

            $assertSame(
                'manual creation',
                'automatic generation timestamp absent',
                null,
                $firstSavedCase
                    ->request_draft_generated_at
            );

            $assertSame(
                'manual creation',
                'one edit event created',
                $eventsBeforeFirstSave + 1,
                ProductCaseEvent::query()
                    ->count()
            );

            $firstEditEvent =
                ProductCaseEvent::query()
                    ->where(
                        'product_case_id',
                        $manualCase->id
                    )
                    ->where(
                        'event_type',
                        ProductCaseEvent
                            ::TYPE_REQUEST_DRAFT_EDITED
                    )
                    ->orderByDesc('id')
                    ->first();

            $assertSame(
                'event',
                'manual edit event available',
                true,
                $firstEditEvent !== null
            );

            $assertSame(
                'event',
                'previous source is empty',
                'empty',
                data_get(
                    $firstEditEvent
                        ?->metadata,
                    'previous_source'
                )
            );

            $assertSame(
                'event',
                'new hash matches draft',
                hash(
                    'sha256',
                    $normalizedDraft
                ),
                data_get(
                    $firstEditEvent
                        ?->metadata,
                    'new_sha256'
                )
            );

            $assertSame(
                'component',
                'saved draft refreshed',
                $normalizedDraft,
                $component
                    ->productCase
                    ->request_draft
            );

            $assertSame(
                'component',
                'manual source label refreshed',
                'Modificata manualmente',
                $component
                    ->requestDraftSourceLabel
            );

            $assertSame(
                'component',
                'editor closed after save',
                false,
                $component
                    ->isEditingRequestDraft
            );

            $assertSame(
                'component',
                'draft body reset after save',
                '',
                $component
                    ->requestDraftBody
            );

            $assertSame(
                'component',
                'manual creation success exposed',
                'Bozza manuale salvata correttamente.',
                $component
                    ->requestDraftSuccessMessage
            );

            $firstTimelineEdit =
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
                            ::TYPE_REQUEST_DRAFT_EDITED
                    )
                    ->last();

            $assertSame(
                'timeline',
                'manual event immediately visible',
                true,
                $firstTimelineEdit !== null
            );

            $assertSame(
                'timeline',
                'manual draft is current',
                'current',
                data_get(
                    $firstTimelineEdit,
                    'reference.state'
                )
            );

            $savedHtml =
                $render(
                    $component
                );

            $assertSame(
                'html',
                'manual draft rendered',
                true,
                str_contains(
                    html_entity_decode(
                        $savedHtml
                    ),
                    'richiedo assistenza per il prodotto.'
                )
            );

            $assertSame(
                'html',
                'manual edit label rendered',
                true,
                str_contains(
                    $savedHtml,
                    'Modifica testo'
                )
            );

            /*
             |--------------------------------------------------------------------------
             | No-op dopo normalizzazione
             |--------------------------------------------------------------------------
             */

            $component
                ->startRequestDraftEdit();

            $assertSame(
                'no-op',
                'current text loaded into editor',
                $normalizedDraft,
                $component
                    ->requestDraftBody
            );

            $component->requestDraftBody =
                " \r\n"
                . $normalizedDraft
                . "\r\n ";

            $eventsBeforeNoOp =
                ProductCaseEvent::query()
                    ->count();

            $metadataBeforeNoOp =
                $firstSavedCase
                    ->metadata;

            $updatedAtBeforeNoOp =
                $firstSavedCase
                    ->updated_at
                    ?->toISOString();

            $component
                ->saveRequestDraft(
                    $editor
                );

            $noOpCase =
                ProductCase::query()
                    ->findOrFail(
                        $manualCase->id
                    );

            $assertSame(
                'no-op',
                'normalized draft unchanged',
                $normalizedDraft,
                $noOpCase
                    ->request_draft
            );

            $assertSame(
                'no-op',
                'no event created',
                $eventsBeforeNoOp,
                ProductCaseEvent::query()
                    ->count()
            );

            $assertSame(
                'no-op',
                'metadata unchanged',
                $metadataBeforeNoOp,
                $noOpCase
                    ->metadata
            );

            $assertSame(
                'no-op',
                'case timestamp unchanged',
                $updatedAtBeforeNoOp,
                $noOpCase
                    ->updated_at
                    ?->toISOString()
            );

            $assertSame(
                'no-op',
                'no-op feedback exposed',
                'La bozza non contiene modifiche.',
                $component
                    ->requestDraftSuccessMessage
            );

            /*
             |--------------------------------------------------------------------------
             | Seconda modifica manuale
             |--------------------------------------------------------------------------
             */

            $component
                ->startRequestDraftEdit();

            $updatedManualDraft =
                $normalizedDraft
                . "\n\n"
                . 'Il problema si presenta a ogni accensione.';

            $component->requestDraftBody =
                $updatedManualDraft;

            $eventsBeforeSecondSave =
                ProductCaseEvent::query()
                    ->count();

            $component
                ->saveRequestDraft(
                    $editor
                );

            $secondSavedCase =
                ProductCase::query()
                    ->findOrFail(
                        $manualCase->id
                    );

            $assertSame(
                'manual update',
                'updated draft persisted',
                $updatedManualDraft,
                $secondSavedCase
                    ->request_draft
            );

            $assertSame(
                'manual update',
                'one new event created',
                $eventsBeforeSecondSave + 1,
                ProductCaseEvent::query()
                    ->count()
            );

            $assertSame(
                'manual update',
                'manual edit count incremented',
                2,
                data_get(
                    $secondSavedCase->metadata,
                    ProductCaseRequestDraftEditor
                        ::METADATA_KEY
                        . '.edit_count'
                )
            );

            $assertSame(
                'manual update',
                'update feedback exposed',
                'Bozza aggiornata manualmente.',
                $component
                    ->requestDraftSuccessMessage
            );

            $manualTimelineEvents =
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
                            ::TYPE_REQUEST_DRAFT_EDITED
                    )
                    ->values();

            $assertSame(
                'timeline',
                'first manual edit superseded',
                'superseded',
                data_get(
                    $manualTimelineEvents
                        ->first(),
                    'reference.state'
                )
            );

            $assertSame(
                'timeline',
                'latest manual edit current',
                'current',
                data_get(
                    $manualTimelineEvents
                        ->last(),
                    'reference.state'
                )
            );

            /*
             |--------------------------------------------------------------------------
             | Modifica di una bozza generata
             |--------------------------------------------------------------------------
             */

            $generatedCase =
                $creator->create(
                    product:
                        $product,

                    openedBy:
                        $user,

                    attributes: [
                        'title' =>
                            'Bozza automatica da personalizzare',

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

            $createdCaseIds[] =
                (int) $generatedCase->id;

            $generatedCase =
                $generator->generate(
                    productCase:
                        $generatedCase,

                    generatedBy:
                        $user,
                );

            $generatedDraft =
                (string) $generatedCase
                    ->request_draft;

            $generatedAtBeforeManualEdit =
                $generatedCase
                    ->request_draft_generated_at
                    ?->toISOString();

            $generatedComponent =
                app(
                    ProductCaseShow::class
                );

            $generatedComponent->mount(
                $generatedCase
            );

            $generatedComponent
                ->startRequestDraftEdit();

            $assertSame(
                'generated edit',
                'generated text prefilled',
                $generatedDraft,
                $generatedComponent
                    ->requestDraftBody
            );

            $manuallyCustomizedDraft =
                $generatedDraft
                . "\n\n"
                . 'Nota aggiunta manualmente prima dell’invio.';

            $generatedComponent
                ->requestDraftBody =
                    $manuallyCustomizedDraft;

            $eventsBeforeGeneratedEdit =
                ProductCaseEvent::query()
                    ->count();

            $generatedComponent
                ->saveRequestDraft(
                    $editor
                );

            $customizedCase =
                ProductCase::query()
                    ->findOrFail(
                        $generatedCase->id
                    );

            $assertSame(
                'generated edit',
                'customized draft persisted',
                $manuallyCustomizedDraft,
                $customizedCase
                    ->request_draft
            );

            $assertSame(
                'generated edit',
                'source changed to manual',
                ProductCase
                    ::REQUEST_DRAFT_SOURCE_MANUAL,
                data_get(
                    $customizedCase->metadata,
                    ProductCase
                        ::REQUEST_DRAFT_CURRENT_METADATA_KEY
                        . '.source'
                )
            );

            $assertSame(
                'generated edit',
                'generation timestamp preserved',
                $generatedAtBeforeManualEdit,
                $customizedCase
                    ->request_draft_generated_at
                    ?->toISOString()
            );

            $assertSame(
                'generated edit',
                'one edit event created',
                $eventsBeforeGeneratedEdit + 1,
                ProductCaseEvent::query()
                    ->count()
            );

            $assertSame(
                'generated edit',
                'manual update feedback exposed',
                'Bozza aggiornata manualmente.',
                $generatedComponent
                    ->requestDraftSuccessMessage
            );

            $generatedTimeline =
                collect(
                    data_get(
                        $generatedComponent
                            ->timeline,
                        'events',
                        []
                    )
                );

            $generationTimelineEvent =
                $generatedTimeline
                    ->where(
                        'type',
                        ProductCaseEvent
                            ::TYPE_REQUEST_DRAFT_GENERATED
                    )
                    ->last();

            $manualTimelineEvent =
                $generatedTimeline
                    ->where(
                        'type',
                        ProductCaseEvent
                            ::TYPE_REQUEST_DRAFT_EDITED
                    )
                    ->last();

            $assertSame(
                'timeline',
                'automatic generation superseded',
                'superseded',
                data_get(
                    $generationTimelineEvent,
                    'reference.state'
                )
            );

            $assertSame(
                'timeline',
                'manual customization current',
                'current',
                data_get(
                    $manualTimelineEvent,
                    'reference.state'
                )
            );

            /*
             |--------------------------------------------------------------------------
             | Protezione dalla rigenerazione automatica
             |--------------------------------------------------------------------------
             */

            $eventsBeforeProtectedGeneration =
                ProductCaseEvent::query()
                    ->count();

            $updatedAtBeforeProtectedGeneration =
                $customizedCase
                    ->updated_at
                    ?->toISOString();

            $generatedComponent
                ->generateRequestDraft(
                    $generator
                );

            $protectedCase =
                ProductCase::query()
                    ->findOrFail(
                        $generatedCase->id
                    );

            $assertSame(
                'protection',
                'manual customization preserved',
                $manuallyCustomizedDraft,
                $protectedCase
                    ->request_draft
            );

            $assertSame(
                'protection',
                'protected generation creates no event',
                $eventsBeforeProtectedGeneration,
                ProductCaseEvent::query()
                    ->count()
            );

            $assertSame(
                'protection',
                'protected generation changes no timestamp',
                $updatedAtBeforeProtectedGeneration,
                $protectedCase
                    ->updated_at
                    ?->toISOString()
            );

            $assertSame(
                'protection',
                'controlled error exposed',
                'La bozza è stata modificata manualmente e non può essere sovrascritta automaticamente.',
                $generatedComponent
                    ->requestDraftErrorMessage
            );

            /*
             |--------------------------------------------------------------------------
             | Scope
             |--------------------------------------------------------------------------
             */

            $manualCaseAfterOperations =
                ProductCase::query()
                    ->findOrFail(
                        $manualCase->id
                    );

            $assertSame(
                'scope',
                'manual case status unchanged',
                $initialStatus,
                $manualCaseAfterOperations
                    ->status
            );

            $assertSame(
                'scope',
                'document links unchanged',
                $documentLinksBeforeOperations,
                DB::table(
                    'product_case_documents'
                )->count()
            );

            $assertSame(
                'scope',
                'media unchanged',
                $mediaBeforeOperations,
                Media::query()->count()
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
                            'Product Case Draft Edit UI '
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
                    ->startRequestDraftEdit();
            } catch (
                AuthorizationException
            ) {
                $crossTeamRejected =
                    true;
            }

            $assertSame(
                'authorization',
                'cross-team editor rejected',
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
             | Stato terminale
             |--------------------------------------------------------------------------
             */

            $cancelledCase =
                $transitionService
                    ->transition(
                        productCase:
                            $protectedCase,

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
                'manual edit action hidden after cancellation',
                false,
                str_contains(
                    $terminalHtml,
                    'start-product-case-request-draft-edit'
                )
            );

            $eventsBeforeTerminalAttempt =
                ProductCaseEvent::query()
                    ->count();

            $terminalRejected =
                false;

            try {
                $terminalComponent
                    ->startRequestDraftEdit();
            } catch (
                RuntimeException
            ) {
                $terminalRejected =
                    true;
            }

            $assertSame(
                'state',
                'terminal editor rejected',
                true,
                $terminalRejected
            );

            $assertSame(
                'state',
                'terminal attempt creates no event',
                $eventsBeforeTerminalAttempt,
                ProductCaseEvent::query()
                    ->count()
            );
        } catch (Throwable $exception) {
            $rows[] = [
                'runtime',
                'request draft edit UI workflow completed',
                'FAIL',
            ];

            $failures[] = [
                'scenario' =>
                    'runtime',

                'assertion' =>
                    'request draft edit UI workflow completed',

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

        foreach (
            $createdCaseIds
            as $createdCaseId
        ) {
            $assertSame(
                'rollback',
                'temporary case '
                    . $createdCaseId
                    . ' removed',
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
            'Product case request draft edit UI checks passed.'
        );

        return self::SUCCESS;
    }
}