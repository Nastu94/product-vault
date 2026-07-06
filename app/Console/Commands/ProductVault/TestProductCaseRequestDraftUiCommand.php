<?php

namespace App\Console\Commands\ProductVault;

use App\Livewire\ProductCases\ProductCaseShow;
use App\Models\Product;
use App\Models\ProductCase;
use App\Models\ProductCaseEvent;
use App\Models\User;
use App\Services\ProductCases\ProductCaseCreator;
use App\Services\ProductCases\ProductCaseDetailsUpdater;
use App\Services\ProductCases\ProductCaseRequestDraftEditor;
use App\Services\ProductCases\ProductCaseRequestDraftGenerator;
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

final class TestProductCaseRequestDraftUiCommand
    extends Command
{
    protected $signature =
        'product-vault:test-product-case-request-draft-ui';

    protected $description =
        'Verifica con rollback la generazione UI della bozza di richiesta.';

    public function handle(
        ProductCaseCreator $creator,
        ProductCaseDetailsUpdater $detailsUpdater,
        ProductCaseRequestDraftGenerator $generator,
        ProductCaseRequestDraftEditor $editor,
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

            $productCase =
                $creator->create(
                    product:
                        $product,

                    openedBy:
                        $user,

                    attributes: [
                        'title' =>
                            'Richiesta assistenza da generare',

                        'description' =>
                            'Il prodotto presenta un malfunzionamento intermittente.',

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

            $createdCaseId =
                (int) $productCase->id;

            $component =
                app(
                    ProductCaseShow::class
                );

            $component->mount(
                $productCase
            );

            $initialStatus =
                $productCase->status;

            $initialDocumentLinks =
                DB::table(
                    'product_case_documents'
                )->count();

            $initialMediaCount =
                Media::query()->count();

            /*
             |--------------------------------------------------------------------------
             | Stato iniziale
             |--------------------------------------------------------------------------
             */

            $assertSame(
                'initial',
                'request draft empty',
                null,
                $component
                    ->productCase
                    ->request_draft
            );

            $assertSame(
                'initial',
                'success message empty',
                null,
                $component
                    ->requestDraftSuccessMessage
            );

            $assertSame(
                'initial',
                'error message empty',
                null,
                $component
                    ->requestDraftErrorMessage
            );

            $initialHtml =
                $render(
                    $component
                );

            $assertSame(
                'html',
                'generation action visible',
                true,
                str_contains(
                    $initialHtml,
                    'generate-product-case-request-draft'
                )
            );

            $assertSame(
                'html',
                'generate label visible',
                true,
                str_contains(
                    $initialHtml,
                    'Genera bozza'
                )
            );

            $assertSame(
                'html',
                'no send action introduced',
                false,
                str_contains(
                    $initialHtml,
                    'Invia richiesta'
                )
            );

            /*
             |--------------------------------------------------------------------------
             | Prima generazione
             |--------------------------------------------------------------------------
             */

            $eventsBeforeGeneration =
                ProductCaseEvent::query()
                    ->count();

            $component
                ->generateRequestDraft(
                    $generator
                );

            $generatedCase =
                ProductCase::query()
                    ->findOrFail(
                        $productCase->id
                    );

            $generatedDraft =
                $generatedCase
                    ->request_draft;

            $assertSame(
                'generation',
                'draft persisted',
                true,
                is_string($generatedDraft)
                    && trim($generatedDraft) !== ''
            );

            $assertSame(
                'generation',
                'generation timestamp persisted',
                true,
                $generatedCase
                    ->request_draft_generated_at
                    !== null
            );

            $assertSame(
                'generation',
                'generated source stored',
                ProductCase
                    ::REQUEST_DRAFT_SOURCE_GENERATED,
                data_get(
                    $generatedCase->metadata,
                    ProductCase
                        ::REQUEST_DRAFT_CURRENT_METADATA_KEY
                        . '.source'
                )
            );

            $assertSame(
                'generation',
                'one generation event created',
                $eventsBeforeGeneration + 1,
                ProductCaseEvent::query()
                    ->count()
            );

            $generationEvent =
                ProductCaseEvent::query()
                    ->where(
                        'product_case_id',
                        $productCase->id
                    )
                    ->where(
                        'event_type',
                        ProductCaseEvent
                            ::TYPE_REQUEST_DRAFT_GENERATED
                    )
                    ->orderByDesc('id')
                    ->first();

            $assertSame(
                'generation',
                'generation event available',
                true,
                $generationEvent !== null
            );

            $assertSame(
                'component',
                'draft refreshed immediately',
                $generatedDraft,
                $component
                    ->productCase
                    ->request_draft
            );

            $assertSame(
                'component',
                'generated source label refreshed',
                'Generata automaticamente',
                $component
                    ->requestDraftSourceLabel
            );

            $assertSame(
                'component',
                'generation success exposed',
                'Bozza generata correttamente.',
                $component
                    ->requestDraftSuccessMessage
            );

            $assertSame(
                'component',
                'generation error absent',
                null,
                $component
                    ->requestDraftErrorMessage
            );

            $generatedTimelineEvent =
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
                            ::TYPE_REQUEST_DRAFT_GENERATED
                    )
                    ->last();

            $assertSame(
                'timeline',
                'generation visible immediately',
                true,
                $generatedTimelineEvent !== null
            );

            $assertSame(
                'timeline',
                'generated draft is current',
                'current',
                data_get(
                    $generatedTimelineEvent,
                    'reference.state'
                )
            );

            $generatedHtml =
                $render(
                    $component
                );

            $assertSame(
                'html',
                'generated draft visible',
                true,
                str_contains(
                    html_entity_decode(
                        $generatedHtml
                    ),
                    $generatedDraft
                )
            );

            $assertSame(
                'html',
                'regenerate label visible',
                true,
                str_contains(
                    $generatedHtml,
                    'Rigenera bozza'
                )
            );

            $assertSame(
                'html',
                'success feedback rendered',
                true,
                str_contains(
                    $generatedHtml,
                    'product-case-request-draft-success'
                )
            );

            /*
             |--------------------------------------------------------------------------
             | Secondo tentativo idempotente
             |--------------------------------------------------------------------------
             */

            $eventsBeforeNoOp =
                ProductCaseEvent::query()
                    ->count();

            $timestampBeforeNoOp =
                $generatedCase
                    ->request_draft_generated_at
                    ?->toISOString();

            $updatedAtBeforeNoOp =
                $generatedCase
                    ->updated_at
                    ?->toISOString();

            $component
                ->generateRequestDraft(
                    $generator
                );

            $noOpCase =
                ProductCase::query()
                    ->findOrFail(
                        $productCase->id
                    );

            $assertSame(
                'idempotence',
                'draft unchanged',
                $generatedDraft,
                $noOpCase
                    ->request_draft
            );

            $assertSame(
                'idempotence',
                'no event created',
                $eventsBeforeNoOp,
                ProductCaseEvent::query()
                    ->count()
            );

            $assertSame(
                'idempotence',
                'generation timestamp unchanged',
                $timestampBeforeNoOp,
                $noOpCase
                    ->request_draft_generated_at
                    ?->toISOString()
            );

            $assertSame(
                'idempotence',
                'case timestamp unchanged',
                $updatedAtBeforeNoOp,
                $noOpCase
                    ->updated_at
                    ?->toISOString()
            );

            $assertSame(
                'idempotence',
                'no-op feedback exposed',
                'La bozza era già aggiornata.',
                $component
                    ->requestDraftSuccessMessage
            );

            /*
             |--------------------------------------------------------------------------
             | Rigenerazione dopo modifica reale delle sorgenti
             |--------------------------------------------------------------------------
             */

            $detailsUpdater->update(
                productCase:
                    $noOpCase,

                updatedBy:
                    $user,

                attributes: [
                    'title' =>
                        $noOpCase->title,

                    'description' =>
                        'Il malfunzionamento è ora continuo e impedisce il normale utilizzo.',

                    'occurred_on' =>
                        $noOpCase
                            ->occurred_on
                            ?->toDateString(),

                    'usability_status' =>
                        ProductCase
                            ::USABILITY_UNUSABLE,

                    'accidental_damage_declared' =>
                        false,

                    'accidental_damage_notes' =>
                        null,
                ],
            );

            $eventsBeforeRegeneration =
                ProductCaseEvent::query()
                    ->count();

            $component
                ->generateRequestDraft(
                    $generator
                );

            $regeneratedCase =
                ProductCase::query()
                    ->findOrFail(
                        $productCase->id
                    );

            $regeneratedDraft =
                $regeneratedCase
                    ->request_draft;

            $assertSame(
                'regeneration',
                'draft changed',
                true,
                is_string($regeneratedDraft)
                    && $regeneratedDraft
                        !== $generatedDraft
            );

            $assertSame(
                'regeneration',
                'one regeneration event created',
                $eventsBeforeRegeneration + 1,
                ProductCaseEvent::query()
                    ->count()
            );

            $regenerationEvent =
                ProductCaseEvent::query()
                    ->where(
                        'product_case_id',
                        $productCase->id
                    )
                    ->where(
                        'event_type',
                        ProductCaseEvent
                            ::TYPE_REQUEST_DRAFT_REGENERATED
                    )
                    ->orderByDesc('id')
                    ->first();

            $assertSame(
                'regeneration',
                'regeneration event available',
                true,
                $regenerationEvent !== null
            );

            $assertSame(
                'regeneration',
                'regeneration feedback exposed',
                'Bozza rigenerata correttamente.',
                $component
                    ->requestDraftSuccessMessage
            );

            $assertSame(
                'regeneration',
                'new draft refreshed',
                $regeneratedDraft,
                $component
                    ->productCase
                    ->request_draft
            );

            $timelineEventsAfterRegeneration =
                collect(
                    data_get(
                        $component->timeline,
                        'events',
                        []
                    )
                );

            $oldGenerationTimeline =
                $timelineEventsAfterRegeneration
                    ->where(
                        'type',
                        ProductCaseEvent
                            ::TYPE_REQUEST_DRAFT_GENERATED
                    )
                    ->last();

            $currentRegenerationTimeline =
                $timelineEventsAfterRegeneration
                    ->where(
                        'type',
                        ProductCaseEvent
                            ::TYPE_REQUEST_DRAFT_REGENERATED
                    )
                    ->last();

            $assertSame(
                'timeline',
                'old generation superseded',
                'superseded',
                data_get(
                    $oldGenerationTimeline,
                    'reference.state'
                )
            );

            $assertSame(
                'timeline',
                'regeneration is current',
                'current',
                data_get(
                    $currentRegenerationTimeline,
                    'reference.state'
                )
            );

            /*
             |--------------------------------------------------------------------------
             | Protezione della modifica manuale
             |--------------------------------------------------------------------------
             */

            $manualDraft =
                trim(
                    (string) $regeneratedDraft
                )
                . "\n\nNota manuale dell’utente.";

            $manuallyEditedCase =
                $editor->saveManualDraft(
                    productCase:
                        $regeneratedCase,

                    editedBy:
                        $user,

                    draft:
                        $manualDraft,
                );

            $eventsBeforeProtectedAttempt =
                ProductCaseEvent::query()
                    ->count();

            $updatedAtBeforeProtectedAttempt =
                $manuallyEditedCase
                    ->updated_at
                    ?->toISOString();

            $component
                ->generateRequestDraft(
                    $generator
                );

            $protectedCase =
                ProductCase::query()
                    ->findOrFail(
                        $productCase->id
                    );

            $assertSame(
                'protection',
                'manual draft preserved',
                $manualDraft,
                $protectedCase
                    ->request_draft
            );

            $assertSame(
                'protection',
                'protected attempt creates no event',
                $eventsBeforeProtectedAttempt,
                ProductCaseEvent::query()
                    ->count()
            );

            $assertSame(
                'protection',
                'protected attempt changes no timestamp',
                $updatedAtBeforeProtectedAttempt,
                $protectedCase
                    ->updated_at
                    ?->toISOString()
            );

            $assertSame(
                'protection',
                'success cleared',
                null,
                $component
                    ->requestDraftSuccessMessage
            );

            $assertSame(
                'protection',
                'controlled error exposed',
                'La bozza è stata modificata manualmente e non può essere sovrascritta automaticamente.',
                $component
                    ->requestDraftErrorMessage
            );

            $assertSame(
                'protection',
                'manual source label refreshed',
                'Modificata manualmente',
                $component
                    ->requestDraftSourceLabel
            );

            $protectedHtml =
                $render(
                    $component
                );

            $assertSame(
                'html',
                'protected error rendered',
                true,
                str_contains(
                    $protectedHtml,
                    'product-case-request-draft-error'
                )
            );

            $assertSame(
                'html',
                'manual content remains visible',
                true,
                str_contains(
                    html_entity_decode(
                        $protectedHtml
                    ),
                    'Nota manuale dell’utente.'
                )
            );

            /*
             |--------------------------------------------------------------------------
             | Scope della patch
             |--------------------------------------------------------------------------
             */

            $assertSame(
                'scope',
                'case status unchanged during generation',
                $initialStatus,
                $protectedCase->status
            );

            $assertSame(
                'scope',
                'document links unchanged',
                $initialDocumentLinks,
                DB::table(
                    'product_case_documents'
                )->count()
            );

            $assertSame(
                'scope',
                'media unchanged',
                $initialMediaCount,
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
                            'Product Case Draft UI '
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
                    ->generateRequestDraft(
                        $generator
                    );
            } catch (
                AuthorizationException
            ) {
                $crossTeamRejected =
                    true;
            }

            $assertSame(
                'authorization',
                'cross-team generation rejected',
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
                'generation action hidden after cancellation',
                false,
                str_contains(
                    $terminalHtml,
                    'generate-product-case-request-draft'
                )
            );

            $eventsBeforeTerminalAttempt =
                ProductCaseEvent::query()
                    ->count();

            $terminalRejected =
                false;

            try {
                $terminalComponent
                    ->generateRequestDraft(
                        $generator
                    );
            } catch (
                RuntimeException
            ) {
                $terminalRejected =
                    true;
            }

            $assertSame(
                'state',
                'terminal generation rejected',
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
                'request draft UI workflow completed',
                'FAIL',
            ];

            $failures[] = [
                'scenario' =>
                    'runtime',

                'assertion' =>
                    'request draft UI workflow completed',

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
            'Product case request draft UI checks passed.'
        );

        return self::SUCCESS;
    }
}