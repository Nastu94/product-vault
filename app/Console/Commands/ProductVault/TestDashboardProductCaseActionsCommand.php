<?php

namespace App\Console\Commands\ProductVault;

use App\Livewire\Dashboard\DashboardActionCenter;
use App\Models\Product;
use App\Models\ProductCase;
use App\Models\ProductCaseEvent;
use App\Models\User;
use App\Services\ProductCases\ProductCaseCreator;
use App\Services\ProductCases\ProductCaseDocumentSelector;
use App\Services\ProductCases\ProductCaseStatusTransitionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;
use Throwable;

final class TestDashboardProductCaseActionsCommand extends Command
{
    protected $signature =
        'product-vault:test-dashboard-product-case-actions';

    protected $description =
        'Verifica con rollback le pratiche operative mostrate nella dashboard.';

    public function handle(
        ProductCaseCreator $creator,
        ProductCaseDocumentSelector $documentSelector,
        ProductCaseStatusTransitionService $transitionService
    ): int {
        $rows = [];
        $failures = [];
        $createdCaseIds = [];

        $casesBefore = ProductCase::query()->count();
        $eventsBefore = ProductCaseEvent::query()->count();
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

            $openStatuses = [
                ProductCase::STATUS_DRAFT,
                ProductCase::STATUS_READY_TO_CONTACT,
                ProductCase::STATUS_CONTACTED,
                ProductCase::STATUS_RESOLVED,
            ];

            $openCountBefore = ProductCase::query()
                ->where('team_id', $product->team_id)
                ->whereIn('status', $openStatuses)
                ->count();

            $createCase = function (string $title) use (
                $creator,
                $product,
                $user,
                &$createdCaseIds
            ): ProductCase {
                $productCase = $creator->create(
                    product: $product,
                    openedBy: $user,
                    attributes: [
                        'title' => $title,
                        'description' =>
                            'Pratica temporanea per la dashboard orientata alle azioni.',
                        'occurred_on' => today()->toDateString(),
                        'usability_status' =>
                            ProductCase::USABILITY_UNUSABLE,
                        'accidental_damage_declared' => false,
                    ],
                );

                $createdCaseIds[] = (int) $productCase->id;

                return $productCase;
            };

            $selectDocument = function (ProductCase $productCase) use (
                $documentSelector,
                $document,
                $user
            ): void {
                $documentSelector->select(
                    productCase: $productCase,
                    document: $document,
                    selectedBy: $user,
                );
            };

            $draftCase = $createCase('Dashboard: pratica da completare');

            $readyCase = $createCase('Dashboard: contatto da registrare');
            $selectDocument($readyCase);
            $readyCase = $transitionService->transition(
                productCase: $readyCase,
                performedBy: $user,
                targetStatus: ProductCase::STATUS_READY_TO_CONTACT,
            );

            $contactedCase = $createCase('Dashboard: esito da registrare');
            $selectDocument($contactedCase);
            $contactedCase = $transitionService->transition(
                productCase: $contactedCase,
                performedBy: $user,
                targetStatus: ProductCase::STATUS_READY_TO_CONTACT,
            );
            $contactedCase = $transitionService->transition(
                productCase: $contactedCase,
                performedBy: $user,
                targetStatus: ProductCase::STATUS_CONTACTED,
            );

            $resolvedCase = $createCase('Dashboard: pratica da chiudere');
            $selectDocument($resolvedCase);
            $resolvedCase = $transitionService->transition(
                productCase: $resolvedCase,
                performedBy: $user,
                targetStatus: ProductCase::STATUS_READY_TO_CONTACT,
            );
            $resolvedCase = $transitionService->transition(
                productCase: $resolvedCase,
                performedBy: $user,
                targetStatus: ProductCase::STATUS_CONTACTED,
            );
            $resolvedCase = $transitionService->transition(
                productCase: $resolvedCase,
                performedBy: $user,
                targetStatus: ProductCase::STATUS_RESOLVED,
                attributes: [
                    'outcome' => ProductCase::OUTCOME_REPAIRED,
                    'resolution_notes' => 'Riparazione completata.',
                ],
            );

            ProductCase::query()
                ->whereKey($resolvedCase->id)
                ->update([
                    'updated_at' => now()->addYear(),
                ]);

            $closedCase = $createCase('Dashboard: pratica chiusa da escludere');
            $selectDocument($closedCase);
            $closedCase = $transitionService->transition(
                productCase: $closedCase,
                performedBy: $user,
                targetStatus: ProductCase::STATUS_READY_TO_CONTACT,
            );
            $closedCase = $transitionService->transition(
                productCase: $closedCase,
                performedBy: $user,
                targetStatus: ProductCase::STATUS_CONTACTED,
            );
            $closedCase = $transitionService->transition(
                productCase: $closedCase,
                performedBy: $user,
                targetStatus: ProductCase::STATUS_RESOLVED,
                attributes: [
                    'outcome' => ProductCase::OUTCOME_REPLACED,
                ],
            );
            $closedCase = $transitionService->transition(
                productCase: $closedCase,
                performedBy: $user,
                targetStatus: ProductCase::STATUS_CLOSED,
            );

            $cancelledCase = $createCase('Dashboard: pratica annullata da escludere');
            $cancelledCase = $transitionService->transition(
                productCase: $cancelledCase,
                performedBy: $user,
                targetStatus: ProductCase::STATUS_CANCELLED,
            );

            $component = app(DashboardActionCenter::class);
            $component->mount();

            $assertSame(
                'count',
                'only four new open cases counted',
                $openCountBefore + 4,
                $component->openProductCasesCount
            );

            $assertSame(
                'list',
                'list limited to four actions',
                min(4, $openCountBefore + 4),
                count($component->openProductCases)
            );

            $listedStatuses = collect($component->openProductCases)
                ->pluck('status')
                ->all();

            $assertSame(
                'list',
                'only actionable statuses listed',
                true,
                collect($listedStatuses)->every(
                    fn (string $status): bool =>
                        in_array($status, $openStatuses, true)
                )
            );

            $assertSame(
                'priority',
                'resolved fixture shown first',
                (int) $resolvedCase->id,
                (int) data_get($component->openProductCases, '0.id')
            );

            $assertSame(
                'priority',
                'resolved action is closure',
                'Chiudi la pratica',
                data_get($component->openProductCases, '0.action_label')
            );

            $listedIds = collect($component->openProductCases)
                ->pluck('id')
                ->map(fn (mixed $id): int => (int) $id)
                ->all();

            $assertSame(
                'terminal states',
                'closed case excluded',
                false,
                in_array((int) $closedCase->id, $listedIds, true)
            );

            $assertSame(
                'terminal states',
                'cancelled case excluded',
                false,
                in_array((int) $cancelledCase->id, $listedIds, true)
            );

            $html = $component
                ->render()
                ->with([
                    'openProductCases' => $component->openProductCases,
                    'openProductCasesCount' => $component->openProductCasesCount,
                ])
                ->render();

            $assertSame(
                'html',
                'action center rendered',
                true,
                str_contains($html, 'dashboard-product-case-actions')
            );

            $assertSame(
                'html',
                'resolved action rendered',
                true,
                str_contains($html, 'Chiudi la pratica')
            );

            $assertSame(
                'html',
                'case link rendered',
                true,
                str_contains(
                    $html,
                    route('product-cases.show', $resolvedCase->id)
                )
            );
        } catch (Throwable $exception) {
            $rows[] = [
                'runtime',
                'dashboard product case actions completed',
                'FAIL',
            ];

            $failures[] = [
                'scenario' => 'runtime',
                'assertion' => 'dashboard product case actions completed',
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
            'document links restored',
            $linksBefore,
            DB::table('product_case_documents')->count()
        );

        foreach ($createdCaseIds as $createdCaseId) {
            $assertSame(
                'rollback',
                'temporary case ' . $createdCaseId . ' removed',
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

        $this->info('Dashboard product case action checks passed.');

        return self::SUCCESS;
    }
}
