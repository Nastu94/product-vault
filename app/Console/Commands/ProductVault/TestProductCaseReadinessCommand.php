<?php

namespace App\Console\Commands\ProductVault;

use App\Models\Product;
use App\Models\ProductCase;
use App\Models\User;
use App\Models\Warranty;
use App\Services\ProductCases\ProductCaseCreator;
use App\Services\ProductCases\ProductCaseDocumentSelector;
use App\Services\ProductCases\ProductCaseReadinessResolver;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class TestProductCaseReadinessCommand extends Command
{
    /**
     * @var string
     */
    protected $signature =
        'product-vault:test-product-case-readiness';

    /**
     * @var string
     */
    protected $description =
        'Verifica con rollback la readiness derivata delle pratiche prodotto.';

    public function handle(
        ProductCaseCreator $creator,
        ProductCaseDocumentSelector $documentSelector,
        ProductCaseReadinessResolver $readinessResolver
    ): int {
        $rows = [];
        $failures = [];

        $createdCaseId = null;
        $createdTemporaryCaseId = null;
        $createdProductId = null;
        $createdWarrantyId = null;

        $casesBefore =
            ProductCase::query()->count();

        $productsBefore =
            Product::query()->count();

        $warrantiesBefore =
            Warranty::query()->count();

        $productLinksBefore = DB::table(
            'product_documents'
        )->count();

        $caseDocumentLinksBefore = DB::table(
            'product_case_documents'
        )->count();

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

        $itemCodes = function (
            array $items
        ): array {
            $codes = [];

            foreach ($items as $item) {
                if (
                    is_array($item)
                    && is_string(
                        $item['code'] ?? null
                    )
                ) {
                    $codes[] = $item['code'];
                }
            }

            sort($codes);

            return $codes;
        };

        DB::beginTransaction();

        try {
            $referenceDate =
                CarbonImmutable::today();

            $product = Product::query()
                ->with([
                    'team',
                    'documents',
                    'warranties.warrantyType',
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
                    'Nessun prodotto con documenti e garanzia utilizzabile per il test.'
                );
            }

            $user = User::query()
                ->find($product->team->user_id);

            if ($user === null) {
                throw new RuntimeException(
                    'Nessun utente utilizzabile per il test.'
                );
            }

            User::query()
                ->whereKey($user->id)
                ->update([
                    'current_team_id' =>
                        $product->team_id,
                ]);

            $user->refresh();

            $document =
                $product->documents->first();

            if ($document === null) {
                throw new RuntimeException(
                    'Documento prodotto non disponibile.'
                );
            }

            /*
             |--------------------------------------------------------------------------
             | Pratica inizialmente incompleta
             |--------------------------------------------------------------------------
             */

            $productCase = $creator->create(
                product: $product,
                openedBy: $user,
                attributes: [
                    'title' =>
                        'Monitor con schermo nero',
                    'description' =>
                        'Il monitor si accende ma non mostra immagini.',
                ],
            );

            $createdCaseId =
                (int) $productCase->id;

            $metadataBefore =
                $productCase->metadata;

            $initialResult =
                $readinessResolver->resolve(
                    productCase: $productCase,
                    referenceDate: $referenceDate,
                );

            $assertSame(
                'initial',
                'contract version',
                ProductCaseReadinessResolver::VERSION,
                $initialResult['version']
            );

            $assertSame(
                'initial',
                'case is not ready',
                false,
                $initialResult[
                    'is_ready_to_contact'
                ]
            );

            $assertSame(
                'initial',
                'expected blocking information',
                [
                    'accidental_damage_declared',
                    'occurred_on',
                    'selected_document',
                    'usability_status',
                ],
                $itemCodes(
                    $initialResult[
                        'blocking_information'
                    ]
                )
            );

            $assertSame(
                'initial',
                'four blockers reported',
                4,
                $initialResult[
                    'blocking_count'
                ]
            );

            $assertSame(
                'initial',
                'warranty context available',
                true,
                data_get(
                    $initialResult,
                    'facts.warranty.available'
                )
            );

            $assertSame(
                'initial',
                'resolver preserves metadata',
                $metadataBefore,
                $productCase->metadata
            );

            $assertSame(
                'initial',
                'resolver leaves model clean',
                false,
                $productCase->isDirty()
            );

            /*
             |--------------------------------------------------------------------------
             | Danno accidentale dichiarato senza spiegazione
             |--------------------------------------------------------------------------
             */

            $productCase->fill([
                'occurred_on' =>
                    $referenceDate->toDateString(),

                'usability_status' =>
                    ProductCase::USABILITY_UNUSABLE,

                'accidental_damage_declared' =>
                    true,
            ])->save();

            $damageResult =
                $readinessResolver->resolve(
                    productCase: $productCase,
                    referenceDate: $referenceDate,
                );

            $assertSame(
                'issue_completion',
                'damage notes and document missing',
                [
                    'accidental_damage_notes',
                    'selected_document',
                ],
                $itemCodes(
                    $damageResult[
                        'blocking_information'
                    ]
                )
            );

            /*
             |--------------------------------------------------------------------------
             | Documento selezionato
             |--------------------------------------------------------------------------
             */

            $selected =
                $documentSelector->select(
                    productCase: $productCase,
                    document: $document,
                    selectedBy: $user,
                    notes:
                        'Documento usato come prova di acquisto.',
                );

            $assertSame(
                'evidence',
                'document selected',
                true,
                $selected
            );

            $productCase->unsetRelation(
                'documents'
            );

            $evidenceResult =
                $readinessResolver->resolve(
                    productCase: $productCase,
                    referenceDate: $referenceDate,
                );

            $assertSame(
                'evidence',
                'only damage notes block readiness',
                [
                    'accidental_damage_notes',
                ],
                $itemCodes(
                    $evidenceResult[
                        'blocking_information'
                    ]
                )
            );

            $assertSame(
                'evidence',
                'one valid document reported',
                1,
                data_get(
                    $evidenceResult,
                    'facts.evidence.valid_selected_document_count'
                )
            );

            /*
             |--------------------------------------------------------------------------
             | Pratica completa
             |--------------------------------------------------------------------------
             */

            $productCase->fill([
                'accidental_damage_notes' =>
                    'Il prodotto è caduto accidentalmente dalla scrivania.',
            ])->save();

            $productCase->unsetRelation(
                'documents'
            );

            $readyResult =
                $readinessResolver->resolve(
                    productCase: $productCase,
                    referenceDate: $referenceDate,
                );

            $assertSame(
                'ready',
                'case becomes ready',
                true,
                $readyResult[
                    'is_ready_to_contact'
                ]
            );

            $assertSame(
                'ready',
                'no blocking information',
                [],
                $itemCodes(
                    $readyResult[
                        'blocking_information'
                    ]
                )
            );

            $assertSame(
                'ready',
                'blocking count is zero',
                0,
                $readyResult[
                    'blocking_count'
                ]
            );

            $assertSame(
                'ready',
                'readiness does not change workflow status',
                ProductCase::STATUS_DRAFT,
                $productCase->status
            );

            $assertSame(
                'ready',
                'readiness does not generate request draft',
                null,
                $productCase->request_draft
            );

            /*
             |--------------------------------------------------------------------------
             | La rimozione del documento rende nuovamente incompleta la pratica
             |--------------------------------------------------------------------------
             */

            $removed =
                $documentSelector->deselect(
                    productCase: $productCase,
                    document: $document,
                    deselectedBy: $user,
                );

            $assertSame(
                'derived_state',
                'document removed',
                true,
                $removed
            );

            $productCase->unsetRelation(
                'documents'
            );

            $afterRemovalResult =
                $readinessResolver->resolve(
                    productCase: $productCase,
                    referenceDate: $referenceDate,
                );

            $assertSame(
                'derived_state',
                'readiness reacts to document removal',
                false,
                $afterRemovalResult[
                    'is_ready_to_contact'
                ]
            );

            $assertSame(
                'derived_state',
                'document becomes blocking again',
                [
                    'selected_document',
                ],
                $itemCodes(
                    $afterRemovalResult[
                        'blocking_information'
                    ]
                )
            );

            $documentSelector->select(
                productCase: $productCase,
                document: $document,
                selectedBy: $user,
            );

            /*
             |--------------------------------------------------------------------------
             | Prodotto temporaneo senza contesto garanzia
             |--------------------------------------------------------------------------
             */

            $temporaryProduct =
                Product::query()->create([
                    'team_id' =>
                        $product->team_id,

                    'created_by_user_id' =>
                        $user->id,

                    'name' =>
                        'Readiness Test '
                        . Str::uuid(),
                ]);

            $createdProductId =
                (int) $temporaryProduct->id;

            $temporaryProduct
                ->documents()
                ->attach(
                    $document->id,
                    [
                        'linked_by_user_id' =>
                            $user->id,
                    ]
                );

            $temporaryCase = $creator->create(
                product: $temporaryProduct,
                openedBy: $user,
                attributes: [
                    'title' =>
                        'Problema su prodotto temporaneo',

                    'description' =>
                        'Descrizione completa del problema.',

                    'occurred_on' =>
                        $referenceDate->toDateString(),

                    'usability_status' =>
                        ProductCase::USABILITY_UNUSABLE,

                    'accidental_damage_declared' =>
                        false,
                ],
            );

            $createdTemporaryCaseId =
                (int) $temporaryCase->id;

            $documentSelector->select(
                productCase: $temporaryCase,
                document: $document,
                selectedBy: $user,
            );

            $noWarrantyResult =
                $readinessResolver->resolve(
                    productCase: $temporaryCase,
                    referenceDate: $referenceDate,
                );

            $assertSame(
                'warranty',
                'missing warranty blocks readiness',
                false,
                $noWarrantyResult[
                    'is_ready_to_contact'
                ]
            );

            $assertSame(
                'warranty',
                'warranty context reported missing',
                [
                    'warranty_context',
                ],
                $itemCodes(
                    $noWarrantyResult[
                        'blocking_information'
                    ]
                )
            );

            /*
             |--------------------------------------------------------------------------
             | Garanzia presente ma senza date
             |--------------------------------------------------------------------------
             */

            $temporaryWarranty =
                Warranty::query()->create([
                    'product_id' =>
                        $temporaryProduct->id,

                    'source' => 'manual',
                ]);

            $createdWarrantyId =
                (int) $temporaryWarranty->id;

            $temporaryCase->unsetRelation(
                'product'
            );

            $incompleteWarrantyResult =
                $readinessResolver->resolve(
                    productCase: $temporaryCase,
                    referenceDate: $referenceDate,
                );

            $assertSame(
                'warranty',
                'incomplete warranty dates block readiness',
                [
                    'warranty.ends_at',
                    'warranty.starts_at',
                ],
                $itemCodes(
                    $incompleteWarrantyResult[
                        'blocking_information'
                    ]
                )
            );

            /*
             |--------------------------------------------------------------------------
             | Garanzia con periodo completo
             |--------------------------------------------------------------------------
             */

            $temporaryWarranty->forceFill([
                'starts_at' =>
                    $referenceDate->toDateString(),

                'ends_at' =>
                    $referenceDate
                        ->addYears(2)
                        ->toDateString(),

                'duration_months' => 24,
            ])->save();

            $temporaryCase->unsetRelation(
                'product'
            );

            $completeWarrantyResult =
                $readinessResolver->resolve(
                    productCase: $temporaryCase,
                    referenceDate: $referenceDate,
                );

            $assertSame(
                'warranty',
                'complete warranty removes blockers',
                true,
                $completeWarrantyResult[
                    'is_ready_to_contact'
                ]
            );

            $assertSame(
                'warranty',
                'non-blocking context remains advisory',
                true,
                $completeWarrantyResult[
                    'advisory_count'
                ] > 0
            );

            $assertSame(
                'warranty',
                'resolver does not decide legal coverage',
                true,
                in_array(
                    data_get(
                        $completeWarrantyResult,
                        'facts.warranty.coverage_state'
                    ),
                    [
                        'estimated',
                        'declared',
                        'user_confirmed',
                        'verified',
                        'cancelled',
                        'unknown',
                    ],
                    true
                )
            );
        } catch (Throwable $exception) {
            $rows[] = [
                'runtime',
                'readiness test completed',
                'FAIL',
            ];

            $failures[] = [
                'scenario' => 'runtime',
                'assertion' =>
                    'readiness test completed',
                'expected' => 'no exception',
                'actual' =>
                    $exception::class
                    . ': '
                    . $exception->getMessage(),
            ];
        } finally {
            DB::rollBack();
        }

        /*
         |--------------------------------------------------------------------------
         | Verifica rollback
         |--------------------------------------------------------------------------
         */

        $assertSame(
            'rollback',
            'case count restored',
            $casesBefore,
            ProductCase::query()->count()
        );

        if ($createdCaseId !== null) {
            $assertSame(
                'rollback',
                'created case removed',
                false,
                ProductCase::query()
                    ->whereKey($createdCaseId)
                    ->exists()
            );
        }

        if ($createdTemporaryCaseId !== null) {
            $assertSame(
                'rollback',
                'temporary case removed',
                false,
                ProductCase::query()
                    ->whereKey(
                        $createdTemporaryCaseId
                    )
                    ->exists()
            );
        }

        $assertSame(
            'rollback',
            'product count restored',
            $productsBefore,
            Product::query()->count()
        );

        if ($createdProductId !== null) {
            $assertSame(
                'rollback',
                'temporary product removed',
                false,
                Product::query()
                    ->whereKey($createdProductId)
                    ->exists()
            );
        }

        $assertSame(
            'rollback',
            'warranty count restored',
            $warrantiesBefore,
            Warranty::query()->count()
        );

        if ($createdWarrantyId !== null) {
            $assertSame(
                'rollback',
                'temporary warranty removed',
                false,
                Warranty::query()
                    ->whereKey($createdWarrantyId)
                    ->exists()
            );
        }

        $assertSame(
            'rollback',
            'product document links restored',
            $productLinksBefore,
            DB::table(
                'product_documents'
            )->count()
        );

        $assertSame(
            'rollback',
            'case document links restored',
            $caseDocumentLinksBefore,
            DB::table(
                'product_case_documents'
            )->count()
        );

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
            'Product case readiness checks passed.'
        );

        return self::SUCCESS;
    }
}