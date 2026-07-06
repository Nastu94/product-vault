<?php

namespace App\Console\Commands\ProductVault;

use App\Livewire\Dashboard\DashboardCompletionCenter;
use App\Models\Document;
use App\Models\Product;
use App\Models\ProductIdentificationCandidate;
use App\Models\User;
use App\Models\Warranty;
use App\Services\Warranties\WarrantyCoverageContextResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;
use Throwable;

final class TestDashboardCompletionCenterCommand extends Command
{
    protected $signature =
        'product-vault:test-dashboard-completion-center';

    protected $description =
        'Verifica con rollback le attività da completare mostrate in dashboard.';

    public function handle(): int
    {
        $rows = [];
        $failures = [];

        $productsBefore = Product::query()->count();
        $candidatesBefore =
            ProductIdentificationCandidate::query()->count();
        $warrantiesBefore = Warranty::query()->count();
        $teamsBefore = DB::table('teams')->count();

        $permissionRegistrar = app(PermissionRegistrar::class);
        $coverageResolver = app(
            WarrantyCoverageContextResolver::class
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

        DB::beginTransaction();

        try {
            $document = Document::query()
                ->whereNotNull('team_id')
                ->whereIn(
                    'team_id',
                    Product::query()
                        ->select('team_id')
                        ->whereNotNull('team_id')
                )
                ->orderBy('id')
                ->first();

            if ($document === null) {
                throw new RuntimeException(
                    'Nessun documento con prodotto nello stesso team.'
                );
            }

            $product = Product::query()
                ->with('team')
                ->where('team_id', $document->team_id)
                ->orderBy('id')
                ->first();

            if ($product === null || $product->team === null) {
                throw new RuntimeException(
                    'Nessun prodotto con team utilizzabile per il test.'
                );
            }

            $user = User::query()->find($product->team->user_id);

            if ($user === null) {
                throw new RuntimeException(
                    'Nessun utente utilizzabile per il test.'
                );
            }

            User::query()
                ->whereKey($user->id)
                ->update([
                    'current_team_id' => $product->team_id,
                ]);

            $user->refresh();

            $permissionRegistrar
                ->setPermissionsTeamId($product->team_id);

            $user->unsetRelation('roles');
            $user->unsetRelation('permissions');
            Auth::login($user);

            $baseline = app(DashboardCompletionCenter::class);
            $baseline->mount($coverageResolver);

            $candidateTitle =
                'Dashboard candidato ' . Str::uuid();

            ProductIdentificationCandidate::unguarded(
                fn (): ProductIdentificationCandidate =>
                    ProductIdentificationCandidate::query()->create([
                        'document_id' => $document->id,
                        'product_id' => null,
                        'name' => $candidateTitle,
                        'source' => 'manual_test',
                        'confidence_score' => 65,
                        'is_selected' => false,
                        'review_status' => 'pending',
                        'metadata' => [
                            'assisted_review' => [
                                'version' => 'v1',
                                'needs_user_completion' => true,
                            ],
                        ],
                        'created_at' => now()->addDays(10),
                        'updated_at' => now()->addDays(10),
                    ])
            );

            $incompleteProduct = $product->replicate();
            $incompleteProduct->name =
                'Dashboard prodotto incompleto ' . Str::uuid();
            $incompleteProduct->model = null;
            $incompleteProduct->serial_number = null;
            $incompleteProduct->ean_code = null;
            $incompleteProduct->purchase_date = null;
            $incompleteProduct->created_at = now()->addDays(9);
            $incompleteProduct->updated_at = now()->addDays(9);
            $incompleteProduct->save();

            Warranty::query()->create([
                'product_id' => $product->id,
                'starts_at' => today()->toDateString(),
                'ends_at' => today()->addYear()->toDateString(),
                'duration_months' => 12,
                'source' => 'calculated',
                'confidence_score' => 70,
                'notes' => 'Fixture dashboard da completare.',
                'metadata' => [
                    'calculation' => [
                        'starts_at_source' => 'purchase_date',
                        'duration_months_source' => 'test',
                    ],
                ],
                'created_at' => now()->addDays(8),
                'updated_at' => now()->addDays(8),
            ]);

            $component = app(DashboardCompletionCenter::class);
            $component->mount($coverageResolver);

            $assertSame(
                'counts',
                'pending candidate incremented',
                $baseline->pendingCandidatesCount + 1,
                $component->pendingCandidatesCount
            );

            $assertSame(
                'counts',
                'estimated coverage incremented',
                $baseline->estimatedCoveragesCount + 1,
                $component->estimatedCoveragesCount
            );

            $assertSame(
                'counts',
                'missing purchase date incremented',
                $baseline->missingPurchaseDatesCount + 1,
                $component->missingPurchaseDatesCount
            );

            $assertSame(
                'counts',
                'missing source document incremented',
                $baseline->missingSourceDocumentsCount + 1,
                $component->missingSourceDocumentsCount
            );

            $assertSame(
                'counts',
                'four completion tasks added',
                $baseline->completionTasksCount + 4,
                $component->completionTasksCount
            );

            $assertSame(
                'list',
                'list limited to six items',
                true,
                count($component->completionItems) <= 6
            );

            $assertSame(
                'list',
                'candidate priority item shown',
                true,
                collect($component->completionItems)->contains(
                    fn (array $item): bool =>
                        $item['type'] === 'candidate'
                        && $item['title'] === $candidateTitle
                )
            );

            $assertSame(
                'list',
                'estimated coverage item shown',
                true,
                collect($component->completionItems)->contains(
                    fn (array $item): bool =>
                        $item['type'] === 'coverage'
                        && $item['title'] === $product->name
                )
            );

            $html = $component
                ->render()
                ->with([
                    'completionItems' =>
                        $component->completionItems,
                    'completionTasksCount' =>
                        $component->completionTasksCount,
                    'pendingCandidatesCount' =>
                        $component->pendingCandidatesCount,
                    'estimatedCoveragesCount' =>
                        $component->estimatedCoveragesCount,
                    'missingPurchaseDatesCount' =>
                        $component->missingPurchaseDatesCount,
                    'missingSourceDocumentsCount' =>
                        $component->missingSourceDocumentsCount,
                ])
                ->render();

            $assertSame(
                'html',
                'completion center rendered',
                true,
                str_contains($html, 'dashboard-completion-center')
            );

            $assertSame(
                'html',
                'candidate action rendered',
                true,
                str_contains($html, e($candidateTitle))
            );

            $otherTeamId = DB::table('teams')->insertGetId([
                'user_id' => $user->id,
                'name' => 'Dashboard completion ' . Str::uuid(),
                'personal_team' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $otherTeamProduct = $product->replicate();
            $otherTeamProduct->team_id = $otherTeamId;
            $otherTeamProduct->name =
                'Dashboard altro workspace ' . Str::uuid();
            $otherTeamProduct->purchase_date = null;
            $otherTeamProduct->serial_number = null;
            $otherTeamProduct->ean_code = null;
            $otherTeamProduct->save();

            Warranty::query()->create([
                'product_id' => $otherTeamProduct->id,
                'starts_at' => today()->toDateString(),
                'ends_at' => today()->addYear()->toDateString(),
                'duration_months' => 12,
                'source' => 'calculated',
                'confidence_score' => 70,
                'metadata' => [],
            ]);

            $isolated = app(DashboardCompletionCenter::class);
            $isolated->mount($coverageResolver);

            $assertSame(
                'workspace',
                'other workspace tasks excluded',
                [
                    $component->pendingCandidatesCount,
                    $component->estimatedCoveragesCount,
                    $component->missingPurchaseDatesCount,
                    $component->missingSourceDocumentsCount,
                ],
                [
                    $isolated->pendingCandidatesCount,
                    $isolated->estimatedCoveragesCount,
                    $isolated->missingPurchaseDatesCount,
                    $isolated->missingSourceDocumentsCount,
                ]
            );
        } catch (Throwable $exception) {
            $rows[] = [
                'runtime',
                'dashboard completion center completed',
                'FAIL',
            ];

            $failures[] = [
                'scenario' => 'runtime',
                'assertion' =>
                    'dashboard completion center completed',
                'expected' => 'no exception',
                'actual' =>
                    $exception::class . ': ' . $exception->getMessage(),
            ];
        } finally {
            Auth::logout();
            $permissionRegistrar->setPermissionsTeamId(null);
            DB::rollBack();
        }

        $assertSame(
            'rollback',
            'product count restored',
            $productsBefore,
            Product::query()->count()
        );

        $assertSame(
            'rollback',
            'candidate count restored',
            $candidatesBefore,
            ProductIdentificationCandidate::query()->count()
        );

        $assertSame(
            'rollback',
            'warranty count restored',
            $warrantiesBefore,
            Warranty::query()->count()
        );

        $assertSame(
            'rollback',
            'team count restored',
            $teamsBefore,
            DB::table('teams')->count()
        );

        $this->table(
            ['Scenario', 'Assertion', 'Status'],
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
                    . var_export($failure['expected'], true)
                );

                $this->line(
                    'Actual: '
                    . var_export($failure['actual'], true)
                );
            }

            return self::FAILURE;
        }

        $this->info(
            'Dashboard completion center checks passed.'
        );

        return self::SUCCESS;
    }
}
