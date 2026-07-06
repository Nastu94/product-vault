<?php

namespace App\Console\Commands\ProductVault;

use App\Livewire\ProductCases\ProductCaseStopBar;
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
use RuntimeException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\Permission\PermissionRegistrar;
use Throwable;

final class TestProductCaseCancellationUiCommand extends Command
{
    protected $signature =
        'product-vault:test-product-case-cancellation-ui';

    protected $description =
        'Verifica con rollback l’annullamento controllato della pratica.';

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

        $render = function (ProductCaseStopBar $component): string {
            return $component
                ->render()
                ->with([
                    'productCase' => $component->productCase,
                ])
                ->render();
        };

        DB::beginTransaction();

        try {
            $product = Product::query()
                ->with(['team', 'documents', 'warranties'])
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

            $user = User::query()->find($product->team->user_id);

            if ($user === null) {
                throw new RuntimeException('Nessun utente utilizzabile per il test.');
            }

            $document = $product->documents->first();

            if ($document === null) {
                throw new RuntimeException('Documento prodotto non disponibile.');
            }

            User::query()
                ->whereKey($user->id)
                ->update([
                    'current_team_id' => $product->team_id,
                ]);

            $user->refresh();

            $permissionRegistrar->setPermissionsTeamId($product->team_id);
            $user->unsetRelation('roles');
            $user->unsetRelation('permissions');
            Auth::login($user);

            $productCase = $creator->create(
                product: $product,
                openedBy: $user,
                attributes: [
                    'title' => 'Pratica da annullare',
                    'description' =>
                        'Il prodotto non completa correttamente l’avvio.',
                    'occurred_on' => today()->toDateString(),
                    'usability_status' => ProductCase::USABILITY_UNUSABLE,
                    'accidental_damage_declared' => false,
                ],
            );

            $createdCaseId = (int) $productCase->id;

            $draftComponent = app(ProductCaseStopBar::class);
            $draftComponent->mount($productCase);

            $assertSame(
                'draft',
                'stop action visible',
                true,
                str_contains($render($draftComponent), 'stop-product-case')
            );

            $documentSelector->select(
                productCase: $productCase,
                document: $document,
                selectedBy: $user,
            );

            $readyCase = $transitionService->transition(
                productCase: $productCase,
                performedBy: $user,
                targetStatus: ProductCase::STATUS_READY_TO_CONTACT,
            );

            $readyComponent = app(ProductCaseStopBar::class);
            $readyComponent->mount($readyCase);

            $assertSame(
                'ready',
                'stop action visible',
                true,
                str_contains($render($readyComponent), 'stop-product-case')
            );

            $contactedCase = $transitionService->transition(
                productCase: $readyCase,
                performedBy: $user,
                targetStatus: ProductCase::STATUS_CONTACTED,
            );

            $component = app(ProductCaseStopBar::class);
            $component->mount($contactedCase);

            $contactedHtml = $render($component);

            $assertSame(
                'contacted',
                'stop action visible',
                true,
                str_contains($contactedHtml, 'stop-product-case')
            );

            $assertSame(
                'html',
                'preservation warning visible',
                true,
                str_contains(
                    $contactedHtml,
                    'Documenti, fotografie, bozza e storico resteranno conservati.'
                )
            );

            $beforeStop = ProductCase::query()->findOrFail($contactedCase->id);
            $contactedAtBefore = $beforeStop->contacted_at?->toISOString();
            $draftBefore = $beforeStop->request_draft;
            $metadataBefore = $beforeStop->metadata;

            $issueBefore = [
                $beforeStop->title,
                $beforeStop->description,
                $beforeStop->occurred_on?->toDateString(),
                $beforeStop->usability_status,
                $beforeStop->accidental_damage_declared,
                $beforeStop->accidental_damage_notes,
            ];

            $documentIdsBefore = DB::table('product_case_documents')
                ->where('product_case_id', $beforeStop->id)
                ->orderBy('document_id')
                ->pluck('document_id')
                ->map(fn (mixed $id): int => (int) $id)
                ->all();

            $caseMediaBefore = Media::query()
                ->where('model_type', $beforeStop->getMorphClass())
                ->where('model_id', $beforeStop->id)
                ->count();

            $eventsBeforeStop = ProductCaseEvent::query()->count();

            $component->stopWorkflow($transitionService);

            $cancelledCase = ProductCase::query()->findOrFail($contactedCase->id);

            $assertSame(
                'transition',
                'status changed to cancelled',
                ProductCase::STATUS_CANCELLED,
                $cancelledCase->status
            );

            $assertSame(
                'transition',
                'cancelled timestamp recorded',
                true,
                $cancelledCase->cancelled_at !== null
            );

            $assertSame(
                'transition',
                'contact timestamp preserved',
                $contactedAtBefore,
                $cancelledCase->contacted_at?->toISOString()
            );

            $assertSame(
                'transition',
                'resolution and closure remain empty',
                true,
                $cancelledCase->resolved_at === null
                    && $cancelledCase->closed_at === null
            );

            $assertSame(
                'transition',
                'one status event created',
                $eventsBeforeStop + 1,
                ProductCaseEvent::query()->count()
            );

            $statusEvent = ProductCaseEvent::query()
                ->where('product_case_id', $cancelledCase->id)
                ->where('event_type', ProductCaseEvent::TYPE_STATUS_CHANGED)
                ->orderByDesc('id')
                ->first();

            $assertSame(
                'event',
                'previous status stored',
                ProductCase::STATUS_CONTACTED,
                data_get($statusEvent?->metadata, 'from_status')
            );

            $assertSame(
                'event',
                'cancelled status stored',
                ProductCase::STATUS_CANCELLED,
                data_get($statusEvent?->metadata, 'to_status')
            );

            $assertSame(
                'scope',
                'request draft preserved',
                $draftBefore,
                $cancelledCase->request_draft
            );

            $assertSame(
                'scope',
                'metadata preserved',
                $metadataBefore,
                $cancelledCase->metadata
            );

            $issueAfter = [
                $cancelledCase->title,
                $cancelledCase->description,
                $cancelledCase->occurred_on?->toDateString(),
                $cancelledCase->usability_status,
                $cancelledCase->accidental_damage_declared,
                $cancelledCase->accidental_damage_notes,
            ];

            $assertSame(
                'scope',
                'issue content preserved',
                $issueBefore,
                $issueAfter
            );

            $documentIdsAfter = DB::table('product_case_documents')
                ->where('product_case_id', $cancelledCase->id)
                ->orderBy('document_id')
                ->pluck('document_id')
                ->map(fn (mixed $id): int => (int) $id)
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
                    ->where('model_type', $cancelledCase->getMorphClass())
                    ->where('model_id', $cancelledCase->id)
                    ->count()
            );

            $assertSame(
                'component',
                'component refreshed to cancelled',
                ProductCase::STATUS_CANCELLED,
                $component->productCase->status
            );

            $assertSame(
                'component',
                'success flashed',
                'La pratica è stata annullata.',
                session()->get('product_case_workflow_success')
            );

            $timeline = $timelineResolver->resolve($cancelledCase);

            $assertSame(
                'timeline',
                'current status cancelled',
                ProductCase::STATUS_CANCELLED,
                data_get($timeline, 'current_status')
            );

            $timelineStatusEvent = collect(data_get($timeline, 'events', []))
                ->where('type', ProductCaseEvent::TYPE_STATUS_CHANGED)
                ->last();

            $assertSame(
                'timeline',
                'cancellation transition visible',
                ProductCase::STATUS_CANCELLED,
                data_get($timelineStatusEvent, 'details.to_status')
            );

            $cancelledComponent = app(ProductCaseStopBar::class);
            $cancelledComponent->mount($cancelledCase);

            $assertSame(
                'html',
                'stop action hidden after transition',
                false,
                str_contains(
                    $render($cancelledComponent),
                    'stop-product-case'
                )
            );

            session()->forget('product_case_workflow_success');
            $eventsBeforeSecondAttempt = ProductCaseEvent::query()->count();

            $cancelledComponent->stopWorkflow($transitionService);

            $assertSame(
                'guard',
                'second attempt creates no event',
                $eventsBeforeSecondAttempt,
                ProductCaseEvent::query()->count()
            );

            $assertSame(
                'guard',
                'controlled error flashed',
                'La pratica non può essere interrotta nello stato corrente.',
                session()->get('product_case_workflow_error')
            );
        } catch (Throwable $exception) {
            $rows[] = [
                'runtime',
                'cancellation UI workflow completed',
                'FAIL',
            ];

            $failures[] = [
                'scenario' => 'runtime',
                'assertion' => 'cancellation UI workflow completed',
                'expected' => 'no exception',
                'actual' => $exception::class . ': ' . $exception->getMessage(),
            ];
        } finally {
            Auth::logout();
            $permissionRegistrar->setPermissionsTeamId(null);
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
            DB::table('product_case_documents')->count()
        );

        if ($createdCaseId !== null) {
            $assertSame(
                'rollback',
                'temporary case removed',
                false,
                ProductCase::query()->whereKey($createdCaseId)->exists()
            );
        }

        $this->table(['Scenario', 'Assertion', 'Status'], $rows);

        if ($failures !== []) {
            foreach ($failures as $failure) {
                $this->error(
                    $failure['scenario'] . ' / ' . $failure['assertion']
                );

                $this->line(
                    'Expected: ' . var_export($failure['expected'], true)
                );

                $this->line(
                    'Actual: ' . var_export($failure['actual'], true)
                );
            }

            return self::FAILURE;
        }

        $this->info('Product case cancellation UI checks passed.');

        return self::SUCCESS;
    }
}
