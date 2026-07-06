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
use RuntimeException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\Permission\PermissionRegistrar;
use Throwable;

final class TestProductCaseContactedUiCommand extends Command
{
    protected $signature =
        'product-vault:test-product-case-contacted-ui';

    protected $description =
        'Verifica con rollback la registrazione UI del contatto effettivo.';

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
                    'productCase' =>
                        $component->productCase,
                    'successMessage' =>
                        $component->successMessage,
                    'errorMessage' =>
                        $component->errorMessage,
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
                        'Contatto effettivo da registrare',
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

            $component = app(
                ProductCaseWorkflowBar::class
            );

            $component->mount($readyCase);

            $readyHtml = $render($component);

            $assertSame(
                'html',
                'contact action visible when ready',
                true,
                str_contains(
                    $readyHtml,
                    'mark-product-case-contacted'
                )
            );

            $assertSame(
                'html',
                'return-to-draft action remains visible',
                true,
                str_contains(
                    $readyHtml,
                    'return-product-case-to-draft'
                )
            );

            $assertSame(
                'html',
                'no automatic sending disclosed',
                true,
                str_contains(
                    $readyHtml,
                    'non invierà messaggi'
                )
            );

            /*
             * Il service deve ricalcolare la readiness anche se la pagina
             * mostrava ancora la pratica come pronta.
             */
            $documentSelector->deselect(
                productCase: $readyCase,
                document: $document,
                deselectedBy: $user,
            );

            $eventsBeforeRejectedAttempt =
                ProductCaseEvent::query()->count();

            $component->markContacted(
                $transitionService
            );

            $rejectedCase = ProductCase::query()
                ->findOrFail($productCase->id);

            $assertSame(
                'stale readiness',
                'status remains ready',
                ProductCase::STATUS_READY_TO_CONTACT,
                $rejectedCase->status
            );

            $assertSame(
                'stale readiness',
                'contact timestamp remains empty',
                null,
                $rejectedCase->contacted_at
            );

            $assertSame(
                'stale readiness',
                'no transition event created',
                $eventsBeforeRejectedAttempt,
                ProductCaseEvent::query()->count()
            );

            $assertSame(
                'stale readiness',
                'controlled error flashed',
                'La pratica non è più completa. Verifica le informazioni bloccanti prima di registrare il contatto.',
                session()->get(
                    'product_case_workflow_error'
                )
            );

            session()->forget(
                'product_case_workflow_error'
            );

            $documentSelector->select(
                productCase: $rejectedCase,
                document: $document,
                selectedBy: $user,
            );

            $readyCase = $rejectedCase->fresh();

            if ($readyCase === null) {
                throw new RuntimeException(
                    'Pratica pronta non disponibile.'
                );
            }

            $component = app(
                ProductCaseWorkflowBar::class
            );

            $component->mount($readyCase);

            $caseBeforeContact = $readyCase->replicate();
            $metadataBefore = $readyCase->metadata;
            $draftBefore = $readyCase->request_draft;

            $documentIdsBefore = DB::table(
                'product_case_documents'
            )
                ->where(
                    'product_case_id',
                    $readyCase->id
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
                    $readyCase->getMorphClass()
                )
                ->where(
                    'model_id',
                    $readyCase->id
                )
                ->count();

            $eventsBeforeContact =
                ProductCaseEvent::query()->count();

            $component->markContacted(
                $transitionService
            );

            $contactedCase = ProductCase::query()
                ->findOrFail($readyCase->id);

            $assertSame(
                'transition',
                'status changed to contacted',
                ProductCase::STATUS_CONTACTED,
                $contactedCase->status
            );

            $assertSame(
                'transition',
                'contact timestamp recorded',
                true,
                $contactedCase->contacted_at !== null
            );

            $assertSame(
                'transition',
                'later timestamps remain empty',
                true,
                $contactedCase->resolved_at === null
                    && $contactedCase->closed_at === null
                    && $contactedCase->cancelled_at === null
            );

            $assertSame(
                'transition',
                'one status event created',
                $eventsBeforeContact + 1,
                ProductCaseEvent::query()->count()
            );

            $statusEvent = ProductCaseEvent::query()
                ->where(
                    'product_case_id',
                    $contactedCase->id
                )
                ->where(
                    'event_type',
                    ProductCaseEvent::TYPE_STATUS_CHANGED
                )
                ->orderByDesc('id')
                ->first();

            $assertSame(
                'event',
                'previous status stored',
                ProductCase::STATUS_READY_TO_CONTACT,
                data_get(
                    $statusEvent?->metadata,
                    'from_status'
                )
            );

            $assertSame(
                'event',
                'contacted status stored',
                ProductCase::STATUS_CONTACTED,
                data_get(
                    $statusEvent?->metadata,
                    'to_status'
                )
            );

            $assertSame(
                'scope',
                'request draft unchanged',
                $draftBefore,
                $contactedCase->request_draft
            );

            $assertSame(
                'scope',
                'metadata unchanged',
                $metadataBefore,
                $contactedCase->metadata
            );

            $assertSame(
                'scope',
                'issue content unchanged',
                [
                    $caseBeforeContact->title,
                    $caseBeforeContact->description,
                    $caseBeforeContact->occurred_on?->toDateString(),
                    $caseBeforeContact->usability_status,
                    $caseBeforeContact->accidental_damage_declared,
                    $caseBeforeContact->accidental_damage_notes,
                ],
                [
                    $contactedCase->title,
                    $contactedCase->description,
                    $contactedCase->occurred_on?->toDateString(),
                    $contactedCase->usability_status,
                    $contactedCase->accidental_damage_declared,
                    $contactedCase->accidental_damage_notes,
                ]
            );

            $documentIdsAfter = DB::table(
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
                        $contactedCase->getMorphClass()
                    )
                    ->where(
                        'model_id',
                        $contactedCase->id
                    )
                    ->count()
            );

            $assertSame(
                'component',
                'component refreshed to contacted',
                ProductCase::STATUS_CONTACTED,
                $component->productCase->status
            );

            $assertSame(
                'component',
                'success flashed',
                'Il contatto è stato registrato correttamente.',
                session()->get(
                    'product_case_workflow_success'
                )
            );

            $timeline = $timelineResolver->resolve(
                $contactedCase
            );

            $assertSame(
                'timeline',
                'current status contacted',
                ProductCase::STATUS_CONTACTED,
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
                'contact transition visible',
                ProductCase::STATUS_CONTACTED,
                data_get(
                    $timelineStatusEvent,
                    'details.to_status'
                )
            );

            $postContactComponent = app(
                ProductCaseWorkflowBar::class
            );

            $postContactComponent->mount(
                $contactedCase
            );

            $postContactHtml = $render(
                $postContactComponent
            );

            $assertSame(
                'html',
                'success rendered after redirect',
                true,
                str_contains(
                    $postContactHtml,
                    'product-case-workflow-bar-success'
                )
            );

            $assertSame(
                'html',
                'contact action hidden after transition',
                false,
                str_contains(
                    $postContactHtml,
                    'mark-product-case-contacted'
                )
            );
        } catch (Throwable $exception) {
            $rows[] = [
                'runtime',
                'contacted UI workflow completed',
                'FAIL',
            ];

            $failures[] = [
                'scenario' => 'runtime',
                'assertion' =>
                    'contacted UI workflow completed',
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
            'Product case contacted UI checks passed.'
        );

        return self::SUCCESS;
    }
}
