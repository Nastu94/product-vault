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
use RuntimeException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\Permission\PermissionRegistrar;
use Throwable;

final class TestProductCaseClosureUiCommand extends Command
{
    protected $signature =
        'product-vault:test-product-case-closure-ui';

    protected $description =
        'Verifica con rollback la chiusura controllata della pratica.';

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
                    'errors' => new ViewErrorBag(),
                    'productCase' => $component->productCase,
                    'successMessage' => $component->successMessage,
                    'errorMessage' => $component->errorMessage,
                    'isResolving' => $component->isResolving,
                    'resolutionOutcome' => $component->resolutionOutcome,
                    'resolutionNotes' => $component->resolutionNotes,
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
                    'title' => 'Pratica da chiudere',
                    'description' =>
                        'Il prodotto non completa correttamente l’avvio.',
                    'occurred_on' =>
                        today()->toDateString(),
                    'usability_status' =>
                        ProductCase::USABILITY_UNUSABLE,
                    'accidental_damage_declared' => false,
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

            $resolvedCase = $transitionService->transition(
                productCase: $contactedCase,
                performedBy: $user,
                targetStatus:
                    ProductCase::STATUS_RESOLVED,
                attributes: [
                    'outcome' =>
                        ProductCase::OUTCOME_REPAIRED,
                    'resolution_notes' =>
                        'Riparazione completata.',
                ],
            );

            $component = app(
                ProductCaseWorkflowBar::class
            );

            $component->mount($resolvedCase);

            $resolvedHtml = $render($component);

            $assertSame(
                'html',
                'closure action visible when resolved',
                true,
                str_contains(
                    $resolvedHtml,
                    'close-product-case'
                )
            );

            $assertSame(
                'html',
                'terminal warning visible',
                true,
                str_contains(
                    $resolvedHtml,
                    'non sono previste altre attività operative'
                )
            );

            $beforeClosure = ProductCase::query()
                ->findOrFail($resolvedCase->id);

            $contactedAtBefore =
                $beforeClosure->contacted_at?->toISOString();

            $resolvedAtBefore =
                $beforeClosure->resolved_at?->toISOString();

            $outcomeBefore = $beforeClosure->outcome;
            $notesBefore = $beforeClosure->resolution_notes;
            $metadataBefore = $beforeClosure->metadata;
            $draftBefore = $beforeClosure->request_draft;

            $documentIdsBefore = DB::table(
                'product_case_documents'
            )
                ->where(
                    'product_case_id',
                    $beforeClosure->id
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
                    $beforeClosure->getMorphClass()
                )
                ->where(
                    'model_id',
                    $beforeClosure->id
                )
                ->count();

            $eventsBeforeClosure =
                ProductCaseEvent::query()->count();

            $component->closeProductCase(
                $transitionService
            );

            $closedCase = ProductCase::query()
                ->findOrFail($resolvedCase->id);

            $assertSame(
                'transition',
                'status changed to closed',
                ProductCase::STATUS_CLOSED,
                $closedCase->status
            );

            $assertSame(
                'transition',
                'closed timestamp recorded',
                true,
                $closedCase->closed_at !== null
            );

            $assertSame(
                'transition',
                'contact timestamp preserved',
                $contactedAtBefore,
                $closedCase->contacted_at?->toISOString()
            );

            $assertSame(
                'transition',
                'resolved timestamp preserved',
                $resolvedAtBefore,
                $closedCase->resolved_at?->toISOString()
            );

            $assertSame(
                'transition',
                'cancel timestamp remains empty',
                null,
                $closedCase->cancelled_at
            );

            $assertSame(
                'transition',
                'one status event created',
                $eventsBeforeClosure + 1,
                ProductCaseEvent::query()->count()
            );

            $statusEvent = ProductCaseEvent::query()
                ->where(
                    'product_case_id',
                    $closedCase->id
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
                ProductCase::STATUS_RESOLVED,
                data_get(
                    $statusEvent?->metadata,
                    'from_status'
                )
            );

            $assertSame(
                'event',
                'event target status',
                ProductCase::STATUS_CLOSED,
                data_get(
                    $statusEvent?->metadata,
                    'to_status'
                )
            );

            $assertSame(
                'scope',
                'outcome preserved',
                $outcomeBefore,
                $closedCase->outcome
            );

            $assertSame(
                'scope',
                'resolution notes preserved',
                $notesBefore,
                $closedCase->resolution_notes
            );

            $assertSame(
                'scope',
                'request draft preserved',
                $draftBefore,
                $closedCase->request_draft
            );

            $assertSame(
                'scope',
                'metadata preserved',
                $metadataBefore,
                $closedCase->metadata
            );

            $documentIdsAfter = DB::table(
                'product_case_documents'
            )
                ->where(
                    'product_case_id',
                    $closedCase->id
                )
                ->orderBy('document_id')
                ->pluck('document_id')
                ->map(
                    fn (mixed $id): int => (int) $id
                )
                ->all();

            $assertSame(
                'scope',
                'selected documents preserved',
                $documentIdsBefore,
                $documentIdsAfter
            );

            $assertSame(
                'scope',
                'media preserved',
                $caseMediaBefore,
                Media::query()
                    ->where(
                        'model_type',
                        $closedCase->getMorphClass()
                    )
                    ->where(
                        'model_id',
                        $closedCase->id
                    )
                    ->count()
            );

            $assertSame(
                'component',
                'component refreshed to closed',
                ProductCase::STATUS_CLOSED,
                $component->productCase->status
            );

            $assertSame(
                'component',
                'success flashed',
                'La pratica è stata chiusa definitivamente.',
                session()->get(
                    'product_case_workflow_success'
                )
            );

            $timeline = $timelineResolver->resolve(
                $closedCase
            );

            $assertSame(
                'timeline',
                'current status closed',
                ProductCase::STATUS_CLOSED,
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
                'closure transition visible',
                ProductCase::STATUS_CLOSED,
                data_get(
                    $timelineStatusEvent,
                    'details.to_status'
                )
            );

            $closedComponent = app(
                ProductCaseWorkflowBar::class
            );

            $closedComponent->mount($closedCase);

            $closedHtml = $render($closedComponent);

            $assertSame(
                'html',
                'success rendered after redirect',
                true,
                str_contains(
                    $closedHtml,
                    'product-case-workflow-bar-success'
                )
            );

            $assertSame(
                'html',
                'closure action hidden after transition',
                false,
                str_contains(
                    $closedHtml,
                    'close-product-case'
                )
            );

            session()->forget(
                'product_case_workflow_success'
            );

            $eventsBeforeSecondAttempt =
                ProductCaseEvent::query()->count();

            $closedComponent->closeProductCase(
                $transitionService
            );

            $assertSame(
                'guard',
                'second closure creates no event',
                $eventsBeforeSecondAttempt,
                ProductCaseEvent::query()->count()
            );

            $assertSame(
                'guard',
                'controlled error flashed',
                'Soltanto una pratica risolta può essere chiusa.',
                session()->get(
                    'product_case_workflow_error'
                )
            );
        } catch (Throwable $exception) {
            $rows[] = [
                'runtime',
                'closure UI workflow completed',
                'FAIL',
            ];

            $failures[] = [
                'scenario' => 'runtime',
                'assertion' =>
                    'closure UI workflow completed',
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
            'Product case closure UI checks passed.'
        );

        return self::SUCCESS;
    }
}
