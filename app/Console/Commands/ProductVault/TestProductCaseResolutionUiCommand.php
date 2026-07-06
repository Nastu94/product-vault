<?php

namespace App\Console\Commands\ProductVault;

use App\Livewire\ProductCases\ProductCaseWorkflowBar;
use App\Models\Product;
use App\Models\ProductCase;
use App\Models\ProductCaseEvent;
use App\Models\User;
use App\Services\ProductCases\ProductCaseCreator;
use App\Services\ProductCases\ProductCaseDocumentSelector;
use App\Services\ProductCases\ProductCaseStatusTransitionService;
use App\Services\ProductCases\ProductCaseTimelineResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\Permission\PermissionRegistrar;
use Throwable;

final class TestProductCaseResolutionUiCommand extends Command
{
    protected $signature =
        'product-vault:test-product-case-resolution-ui';

    protected $description =
        'Verifica con rollback la risoluzione controllata della pratica.';

    public function handle(
        ProductCaseCreator $creator,
        ProductCaseDocumentSelector $documentSelector,
        ProductCaseStatusTransitionService $transitionService,
        ProductCaseTimelineResolver $timelineResolver
    ): int {
        $rows = [];
        $failures = [];
        $createdCaseId = null;

        $casesBefore = ProductCase::query()->count();
        $eventsBefore = ProductCaseEvent::query()->count();
        $mediaBefore = Media::query()->count();
        $linksBefore = DB::table('product_case_documents')->count();

        $permissionRegistrar = app(
            PermissionRegistrar::class
        );

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

        $render = function (
            ProductCaseWorkflowBar $component
        ): string {
            return $component
                ->render()
                ->with([
                    'errors' =>
                        new ViewErrorBag(),
                    'productCase' =>
                        $component->productCase,
                    'successMessage' =>
                        $component->successMessage,
                    'errorMessage' =>
                        $component->errorMessage,
                    'isResolving' =>
                        $component->isResolving,
                    'resolutionOutcome' =>
                        $component->resolutionOutcome,
                    'resolutionNotes' =>
                        $component->resolutionNotes,
                ])
                ->render();
        };

        DB::beginTransaction();

        try {
            $product = Product::query()
                ->with([
                    'team',
                    'documents',
                    'warranties',
                ])
                ->whereNotNull('team_id')
                ->whereHas('documents')
                ->whereHas(
                    'warranties',
                    fn ($query) => $query
                        ->whereNotNull('starts_at')
                        ->whereNotNull('ends_at')
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
                ->find($product->team->user_id);

            if ($user === null) {
                throw new RuntimeException(
                    'Nessun utente utilizzabile per il test.'
                );
            }

            $document = $product->documents->first();

            if ($document === null) {
                throw new RuntimeException(
                    'Documento prodotto non disponibile.'
                );
            }

            User::query()
                ->whereKey($user->id)
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

            Auth::login($user);

            $productCase = $creator->create(
                product: $product,
                openedBy: $user,
                attributes: [
                    'title' =>
                        'Pratica da risolvere',
                    'description' =>
                        'Il prodotto non completa correttamente l’avvio.',
                    'occurred_on' =>
                        today()->toDateString(),
                    'usability_status' =>
                        ProductCase::USABILITY_UNUSABLE,
                    'accidental_damage_declared' =>
                        false,
                ],
            );

            $createdCaseId = (int) $productCase->id;

            $documentSelector->select(
                productCase: $productCase,
                document: $document,
                selectedBy: $user,
            );

            $readyCase = $transitionService->transition(
                productCase: $productCase,
                performedBy: $user,
                targetStatus:
                    ProductCase::STATUS_READY_TO_CONTACT,
            );

            $contactedCase = $transitionService->transition(
                productCase: $readyCase,
                performedBy: $user,
                targetStatus:
                    ProductCase::STATUS_CONTACTED,
            );

            $contactedAtBefore =
                $contactedCase->contacted_at?->toISOString();

            $component = app(
                ProductCaseWorkflowBar::class
            );

            $component->mount($contactedCase);

            $initialHtml = $render($component);

            $assertSame(
                'initial',
                'resolution editor starts closed',
                false,
                $component->isResolving
            );

            $assertSame(
                'html',
                'start resolution action visible',
                true,
                str_contains(
                    $initialHtml,
                    'start-product-case-resolution'
                )
            );

            $assertSame(
                'html',
                'resolution form hidden initially',
                false,
                str_contains(
                    $initialHtml,
                    'product-case-resolution-form'
                )
            );

            $component->startResolution();

            $assertSame(
                'editor',
                'resolution editor opened',
                true,
                $component->isResolving
            );

            $openHtml = $render($component);

            $assertSame(
                'html',
                'resolution form rendered',
                true,
                str_contains(
                    $openHtml,
                    'product-case-resolution-form'
                )
            );

            $assertSame(
                'html',
                'all outcomes rendered',
                true,
                collect(ProductCase::OUTCOMES)
                    ->every(
                        fn (string $outcome): bool =>
                            str_contains(
                                $openHtml,
                                'value="' . $outcome . '"'
                            )
                    )
            );

            $eventsBeforeInvalid =
                ProductCaseEvent::query()->count();

            $invalidRejected = false;

            try {
                $component->resolveProductCase(
                    $transitionService
                );
            } catch (ValidationException) {
                $invalidRejected = true;
            }

            $invalidCase = ProductCase::query()
                ->findOrFail($contactedCase->id);

            $assertSame(
                'validation',
                'empty outcome rejected',
                true,
                $invalidRejected
            );

            $assertSame(
                'validation',
                'status remains contacted',
                ProductCase::STATUS_CONTACTED,
                $invalidCase->status
            );

            $assertSame(
                'validation',
                'invalid attempt creates no event',
                $eventsBeforeInvalid,
                ProductCaseEvent::query()->count()
            );

            $component->resolutionOutcome =
                ProductCase::OUTCOME_OTHER;

            $component->resolutionNotes =
                'Testo da non salvare';

            $eventsBeforeCancel =
                ProductCaseEvent::query()->count();

            $component->cancelResolution();

            $assertSame(
                'cancel',
                'editor closed',
                false,
                $component->isResolving
            );

            $assertSame(
                'cancel',
                'outcome reset',
                '',
                $component->resolutionOutcome
            );

            $assertSame(
                'cancel',
                'notes reset',
                null,
                $component->resolutionNotes
            );

            $assertSame(
                'cancel',
                'cancel creates no event',
                $eventsBeforeCancel,
                ProductCaseEvent::query()->count()
            );

            $component->startResolution();

            $component->resolutionOutcome =
                ProductCase::OUTCOME_REPAIRED;

            $component->resolutionNotes =
                '  Riparazione completata dal centro assistenza.  ';

            $caseBeforeResolution = ProductCase::query()
                ->findOrFail($contactedCase->id);

            $metadataBefore =
                $caseBeforeResolution->metadata;

            $draftBefore =
                $caseBeforeResolution->request_draft;

            $documentIdsBefore = DB::table(
                'product_case_documents'
            )
                ->where(
                    'product_case_id',
                    $contactedCase->id
                )
                ->orderBy('document_id')
                ->pluck('document_id')
                ->map(
                    fn (mixed $id): int => (int) $id
                )
                ->all();

            $caseMediaBefore = Media::query()
                ->where(
                    'model_type',
                    $contactedCase->getMorphClass()
                )
                ->where(
                    'model_id',
                    $contactedCase->id
                )
                ->count();

            $eventsBeforeResolution =
                ProductCaseEvent::query()->count();

            $component->resolveProductCase(
                $transitionService
            );

            $resolvedCase = ProductCase::query()
                ->findOrFail($contactedCase->id);

            $assertSame(
                'transition',
                'status changed to resolved',
                ProductCase::STATUS_RESOLVED,
                $resolvedCase->status
            );

            $assertSame(
                'transition',
                'outcome persisted',
                ProductCase::OUTCOME_REPAIRED,
                $resolvedCase->outcome
            );

            $assertSame(
                'transition',
                'notes normalized and persisted',
                'Riparazione completata dal centro assistenza.',
                $resolvedCase->resolution_notes
            );

            $assertSame(
                'transition',
                'resolved timestamp recorded',
                true,
                $resolvedCase->resolved_at !== null
            );

            $assertSame(
                'transition',
                'contact timestamp preserved',
                $contactedAtBefore,
                $resolvedCase->contacted_at?->toISOString()
            );

            $assertSame(
                'transition',
                'later timestamps remain empty',
                true,
                $resolvedCase->closed_at === null
                    && $resolvedCase->cancelled_at === null
            );

            $assertSame(
                'transition',
                'one status event created',
                $eventsBeforeResolution + 1,
                ProductCaseEvent::query()->count()
            );

            $statusEvent = ProductCaseEvent::query()
                ->where(
                    'product_case_id',
                    $resolvedCase->id
                )
                ->where(
                    'event_type',
                    ProductCaseEvent::TYPE_STATUS_CHANGED
                )
                ->orderByDesc('id')
                ->first();

            $assertSame(
                'event',
                'event previous status',
                ProductCase::STATUS_CONTACTED,
                data_get(
                    $statusEvent?->metadata,
                    'from_status'
                )
            );

            $assertSame(
                'event',
                'event target status',
                ProductCase::STATUS_RESOLVED,
                data_get(
                    $statusEvent?->metadata,
                    'to_status'
                )
            );

            $assertSame(
                'scope',
                'request draft unchanged',
                $draftBefore,
                $resolvedCase->request_draft
            );

            $assertSame(
                'scope',
                'metadata unchanged',
                $metadataBefore,
                $resolvedCase->metadata
            );

            $documentIdsAfter = DB::table(
                'product_case_documents'
            )
                ->where(
                    'product_case_id',
                    $resolvedCase->id
                )
                ->orderBy('document_id')
                ->pluck('document_id')
                ->map(
                    fn (mixed $id): int => (int) $id
                )
                ->all();

            $assertSame(
                'scope',
                'selected documents unchanged',
                $documentIdsBefore,
                $documentIdsAfter
            );

            $assertSame(
                'scope',
                'media unchanged',
                $caseMediaBefore,
                Media::query()
                    ->where(
                        'model_type',
                        $resolvedCase->getMorphClass()
                    )
                    ->where(
                        'model_id',
                        $resolvedCase->id
                    )
                    ->count()
            );

            $assertSame(
                'component',
                'component refreshed to resolved',
                ProductCase::STATUS_RESOLVED,
                $component->productCase->status
            );

            $assertSame(
                'component',
                'editor closed after save',
                false,
                $component->isResolving
            );

            $assertSame(
                'component',
                'success flashed',
                'La pratica è stata registrata come risolta.',
                session()->get(
                    'product_case_workflow_success'
                )
            );

            $timeline = $timelineResolver->resolve(
                $resolvedCase
            );

            $assertSame(
                'timeline',
                'current status resolved',
                ProductCase::STATUS_RESOLVED,
                data_get(
                    $timeline,
                    'current_status'
                )
            );

            $timelineStatusEvent = collect(
                data_get($timeline, 'events', [])
            )
                ->where(
                    'type',
                    ProductCaseEvent::TYPE_STATUS_CHANGED
                )
                ->last();

            $assertSame(
                'timeline',
                'resolution transition visible',
                ProductCase::STATUS_RESOLVED,
                data_get(
                    $timelineStatusEvent,
                    'details.to_status'
                )
            );

            $resolvedComponent = app(
                ProductCaseWorkflowBar::class
            );

            $resolvedComponent->mount(
                $resolvedCase
            );

            $resolvedHtml = $render(
                $resolvedComponent
            );

            $assertSame(
                'html',
                'success rendered after redirect',
                true,
                str_contains(
                    $resolvedHtml,
                    'product-case-workflow-bar-success'
                )
            );

            $assertSame(
                'html',
                'resolution action hidden after transition',
                false,
                str_contains(
                    $resolvedHtml,
                    'start-product-case-resolution'
                )
            );
        } catch (Throwable $exception) {
            $rows[] = [
                'runtime',
                'resolution UI workflow completed',
                'FAIL',
            ];

            $failures[] = [
                'scenario' => 'runtime',
                'assertion' =>
                    'resolution UI workflow completed',
                'expected' => 'no exception',
                'actual' =>
                    $exception::class
                    . ': '
                    . $exception->getMessage(),
            ];
        } finally {
            Auth::logout();

            $permissionRegistrar
                ->setPermissionsTeamId(null);

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

        if ($createdCaseId !== null) {
            $assertSame(
                'rollback',
                'temporary case removed',
                false,
                ProductCase::query()
                    ->whereKey($createdCaseId)
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
            foreach ($failures as $failure) {
                $this->error(
                    $failure['scenario']
                    . ' / '
                    . $failure['assertion']
                );

                $this->line(
                    'Expected: '
                    . var_export(
                        $failure['expected'],
                        true
                    )
                );

                $this->line(
                    'Actual: '
                    . var_export(
                        $failure['actual'],
                        true
                    )
                );
            }

            return self::FAILURE;
        }

        $this->info(
            'Product case resolution UI checks passed.'
        );

        return self::SUCCESS;
    }
}
