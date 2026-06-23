<?php

namespace App\Console\Commands\ProductVault;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Document;
use App\Models\Product;
use App\Models\ProductIdentificationCandidate;
use App\Services\Documents\AssistedReview\AssistedReviewDecisionService;
use App\Services\Documents\AssistedReview\AssistedReviewPresenter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

#[Signature('product-vault:test-assisted-review-decisions')]
#[Description('Verifica con rollback le decisioni Assisted Review')]
class TestAssistedReviewDecisionsCommand extends Command
{
    /**
     * Verifica le decisioni utente senza lasciare dati persistiti.
     */
    public function handle(
        AssistedReviewDecisionService $decisionService,
        AssistedReviewPresenter $presenter
    ): int {
        $rows = [];
        $failures = [];

        $candidateId = null;
        $teamId = null;
        $manualCandidateId = null;

        $brandName = 'Assisted Review Test ' . Str::uuid();
        $manualBrandName = 'Assisted Review Manual ' . Str::uuid();

        /**
         * Registra un'asserzione di uguaglianza stretta.
         */
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

        /**
         * Registra un'asserzione booleana.
         */
        $assertTrue = function (
            string $scenario,
            string $assertion,
            bool $actual
        ) use ($assertSame): void {
            $assertSame(
                $scenario,
                $assertion,
                true,
                $actual
            );
        };

        DB::beginTransaction();

        try {
            /*
             * Usiamo un documento reale soltanto come contenitore valido
             * di team e utente. Tutti i nuovi dati vengono poi annullati.
             */
            $document = Document::query()
                ->whereNotNull('team_id')
                ->whereNotNull('uploaded_by_user_id')
                ->orderBy('id')
                ->first();

            if ($document === null) {
                throw new RuntimeException(
                    'Nessun documento utilizzabile per il test.'
                );
            }

            $teamId = (int) $document->team_id;
            $userId = (int) $document->uploaded_by_user_id;

            $category = Category::query()
                ->where('is_active', true)
                ->where(function ($query) use ($teamId): void {
                    $query
                        ->whereNull('team_id')
                        ->orWhere('team_id', $teamId);
                })
                ->orderBy('id')
                ->first();

            if ($category === null) {
                throw new RuntimeException(
                    'Nessuna categoria utilizzabile per il test.'
                );
            }

            $productsBefore = Product::query()->count();
            $categoriesBefore = Category::query()->count();

            $candidate = ProductIdentificationCandidate::query()
                ->create([
                    'document_id' => $document->id,
                    'document_line_id' => null,
                    'product_id' => null,
                    'brand_id' => null,
                    'category_id' => null,
                    'name' => 'Prodotto Assisted Review Test',
                    'model' => null,
                    'serial_number' => null,
                    'ean_code' => null,
                    'price' => 49.90,
                    'source' => 'test',
                    'confidence_score' => 80,
                    'is_selected' => false,
                    'review_status' => 'pending',
                    'metadata' => [
                        'test_namespace' => [
                            'preserve_me' => true,
                        ],
                        'assisted_review' => [
                            'version' => 'v1',
                            'builder' => 'assisted_review_metadata_builder_v1',
                            'needs_user_completion' => true,
                            'completion_fields' => [
                                'brand',
                                'category',
                                'model',
                            ],
                            'fields' => [
                                'brand' => [
                                    'state' => 'suggested',
                                    'required' => false,
                                    'current' => null,
                                    'suggestion' => [
                                        'value' => $brandName,
                                        'ref' => null,
                                        'origin' => 'automatic',
                                        'source' => 'transactional_test',
                                        'method' => 'synthetic_brand_suggestion',
                                        'confidence' => 80,
                                    ],
                                ],
                                'category' => [
                                    'state' => 'suggested',
                                    'required' => false,
                                    'current' => null,
                                    'suggestion' => [
                                        'value' => $category->name,
                                        'ref' => [
                                            'type' => 'category',
                                            'id' => $category->id,
                                            'key' => $category->slug,
                                        ],
                                        'origin' => 'automatic',
                                        'source' => 'transactional_test',
                                        'method' => 'synthetic_category_suggestion',
                                        'confidence' => 90,
                                    ],
                                ],
                                'model' => [
                                    'state' => 'suggested',
                                    'required' => false,
                                    'current' => null,
                                    'suggestion' => [
                                        'value' => 'AR-MODEL-TEST',
                                        'ref' => null,
                                        'origin' => 'automatic',
                                        'source' => 'transactional_test',
                                        'method' => 'synthetic_model_suggestion',
                                        'confidence' => 85,
                                    ],
                                ],
                            ],
                        ],
                    ],
                ]);

            $candidateId = (int) $candidate->id;

            $assertSame(
                'initial_candidate',
                'completion fields',
                ['brand', 'category', 'model'],
                data_get(
                    $candidate->metadata,
                    'assisted_review.completion_fields'
                )
            );

            $assertSame(
                'initial_candidate',
                'product absent',
                null,
                $candidate->product_id
            );

            /*
             * Accettazione modello.
             */
            $candidate = $decisionService->acceptSuggestion(
                candidate: $candidate,
                fieldName: 'model',
                userId: $userId,
            );

            $assertSame(
                'accept_model',
                'candidate model updated',
                'AR-MODEL-TEST',
                $candidate->model
            );

            $assertSame(
                'accept_model',
                'field state confirmed',
                'confirmed',
                data_get(
                    $candidate->metadata,
                    'assisted_review.fields.model.state'
                )
            );

            $assertSame(
                'accept_model',
                'decision action',
                'accepted_suggestion',
                data_get(
                    $candidate->metadata,
                    'assisted_review.fields.model.decision.action'
                )
            );

            $assertSame(
                'accept_model',
                'completion fields recalculated',
                ['brand', 'category'],
                data_get(
                    $candidate->metadata,
                    'assisted_review.completion_fields'
                )
            );

            /*
             * Accettazione categoria.
             */
            $candidate = $decisionService->acceptSuggestion(
                candidate: $candidate,
                fieldName: 'category',
                userId: $userId,
            );

            $assertSame(
                'accept_category',
                'candidate category updated',
                (int) $category->id,
                (int) $candidate->category_id
            );

            $assertSame(
                'accept_category',
                'field state confirmed',
                'confirmed',
                data_get(
                    $candidate->metadata,
                    'assisted_review.fields.category.state'
                )
            );

            $assertSame(
                'accept_category',
                'current reference id',
                (int) $category->id,
                (int) data_get(
                    $candidate->metadata,
                    'assisted_review.fields.category.current.ref.id'
                )
            );

            $assertSame(
                'accept_category',
                'completion fields recalculated',
                ['brand'],
                data_get(
                    $candidate->metadata,
                    'assisted_review.completion_fields'
                )
            );

            $assertSame(
                'accept_category',
                'category count unchanged',
                $categoriesBefore,
                Category::query()->count()
            );

            /*
             * Accettazione brand testuale.
             */
            $candidate = $decisionService->acceptSuggestion(
                candidate: $candidate,
                fieldName: 'brand',
                userId: $userId,
            );

            $brand = Brand::query()->find($candidate->brand_id);

            $assertTrue(
                'accept_brand',
                'brand created',
                $brand !== null
            );

            $assertSame(
                'accept_brand',
                'private team brand',
                $teamId,
                (int) $brand?->team_id
            );

            $assertSame(
                'accept_brand',
                'brand name',
                $brandName,
                $brand?->name
            );

            $assertSame(
                'accept_brand',
                'brand not verified',
                false,
                (bool) $brand?->is_verified
            );

            $assertSame(
                'accept_brand',
                'field state confirmed',
                'confirmed',
                data_get(
                    $candidate->metadata,
                    'assisted_review.fields.brand.state'
                )
            );

            $assertSame(
                'accept_brand',
                'completion fields empty',
                [],
                data_get(
                    $candidate->metadata,
                    'assisted_review.completion_fields'
                )
            );

            $assertSame(
                'accept_brand',
                'completion no longer required',
                false,
                data_get(
                    $candidate->metadata,
                    'assisted_review.needs_user_completion'
                )
            );

            $assertSame(
                'accept_brand',
                'foreign metadata preserved',
                true,
                data_get(
                    $candidate->metadata,
                    'test_namespace.preserve_me'
                )
            );

            /*
             * Retry della stessa decisione.
             */
            $brandIdBeforeRetry = (int) $candidate->brand_id;

            $matchingBrandsBeforeRetry = Brand::query()
                ->where('team_id', $teamId)
                ->where('name', $brandName)
                ->count();

            $candidate = $decisionService->acceptSuggestion(
                candidate: $candidate,
                fieldName: 'brand',
                userId: $userId,
            );

            $matchingBrandsAfterRetry = Brand::query()
                ->where('team_id', $teamId)
                ->where('name', $brandName)
                ->count();

            $assertSame(
                'brand_idempotence',
                'same brand id',
                $brandIdBeforeRetry,
                (int) $candidate->brand_id
            );

            $assertSame(
                'brand_idempotence',
                'no duplicate brand',
                $matchingBrandsBeforeRetry,
                $matchingBrandsAfterRetry
            );

            $assertSame(
                'brand_idempotence',
                'single private brand',
                1,
                $matchingBrandsAfterRetry
            );

            /*
             * Presentazione finale.
             */
            $presentation = $presenter->present(
                $candidate->loadMissing([
                    'brand',
                    'category',
                ])
            );

            $assertSame(
                'final_presentation',
                'completion not required',
                false,
                $presentation['needs_user_completion']
            );

            $assertSame(
                'final_presentation',
                'brand label',
                'Confermato da te',
                data_get(
                    $presentation,
                    'fields.brand.state_label'
                )
            );

            $assertSame(
                'final_presentation',
                'category label',
                'Confermato da te',
                data_get(
                    $presentation,
                    'fields.category.state_label'
                )
            );

            $assertSame(
                'final_presentation',
                'model label',
                'Confermato da te',
                data_get(
                    $presentation,
                    'fields.model.state_label'
                )
            );

            /*
             * L'accettazione dei campi non deve confermare il candidato
             * né creare la scheda prodotto.
             */
            $assertSame(
                'candidate_lifecycle',
                'candidate remains pending',
                'pending',
                $candidate->review_status
            );

            $assertSame(
                'candidate_lifecycle',
                'candidate product remains null',
                null,
                $candidate->product_id
            );

            $assertSame(
                'candidate_lifecycle',
                'product count unchanged',
                $productsBefore,
                Product::query()->count()
            );

            /*
            * Valorizzazione manuale di campi mancanti o non affidabili.
            */
            $manualCandidate = ProductIdentificationCandidate::query()
                ->create([
                    'document_id' => $document->id,
                    'document_line_id' => null,
                    'product_id' => null,
                    'brand_id' => null,
                    'category_id' => null,
                    'name' => 'Prodotto Manual Assisted Review Test',
                    'model' => 'AX3000',
                    'serial_number' => null,
                    'ean_code' => null,
                    'price' => 59.90,
                    'source' => 'test',
                    'confidence_score' => 80,
                    'is_selected' => false,
                    'review_status' => 'pending',
                    'metadata' => [
                        'manual_test_namespace' => [
                            'preserve_me' => true,
                        ],
                        'assisted_review' => [
                            'version' => 'v1',
                            'builder' => 'assisted_review_metadata_builder_v1',
                            'needs_user_completion' => true,
                            'completion_fields' => [
                                'brand',
                                'category',
                                'model',
                            ],
                            'fields' => [
                                'brand' => [
                                    'state' => 'suggested',
                                    'required' => false,
                                    'current' => null,
                                    'suggestion' => [
                                        'value' => 'Automatic Brand',
                                        'ref' => null,
                                        'source' => 'transactional_test',
                                        'method' => 'automatic_test',
                                    ],
                                ],
                                'category' => [
                                    'state' => 'missing',
                                    'required' => false,
                                    'current' => null,
                                    'suggestion' => null,
                                ],
                                'model' => [
                                    'state' => 'missing',
                                    'required' => false,
                                    'current' => [
                                        'value' => 'AX3000',
                                    ],
                                    'suggestion' => null,
                                    'issues' => [
                                        'technical_specification_used_as_model',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ]);

            $manualCandidateId = (int) $manualCandidate->id;

            /*
            * Modello manuale.
            */
            $manualCandidate = $decisionService->setManualValue(
                candidate: $manualCandidate,
                fieldName: 'model',
                value: 'NX-500',
                userId: $userId,
            );

            $assertSame(
                'manual_model',
                'candidate model updated',
                'NX-500',
                $manualCandidate->model
            );

            $assertSame(
                'manual_model',
                'field state modified',
                'modified',
                data_get(
                    $manualCandidate->metadata,
                    'assisted_review.fields.model.state'
                )
            );

            $assertSame(
                'manual_model',
                'decision action',
                'manual_value',
                data_get(
                    $manualCandidate->metadata,
                    'assisted_review.fields.model.decision.action'
                )
            );

            $assertSame(
                'manual_model',
                'previous unreliable value preserved',
                'AX3000',
                data_get(
                    $manualCandidate->metadata,
                    'assisted_review.fields.model.decision.previous_current_value'
                )
            );

            $assertSame(
                'manual_model',
                'completion fields recalculated',
                ['brand', 'category'],
                data_get(
                    $manualCandidate->metadata,
                    'assisted_review.completion_fields'
                )
            );

            /*
            * Categoria manuale.
            */
            $manualCandidate = $decisionService->setManualValue(
                candidate: $manualCandidate,
                fieldName: 'category',
                value: $category->id,
                userId: $userId,
            );

            $assertSame(
                'manual_category',
                'candidate category updated',
                (int) $category->id,
                (int) $manualCandidate->category_id
            );

            $assertSame(
                'manual_category',
                'field state modified',
                'modified',
                data_get(
                    $manualCandidate->metadata,
                    'assisted_review.fields.category.state'
                )
            );

            $assertSame(
                'manual_category',
                'category count unchanged',
                $categoriesBefore,
                Category::query()->count()
            );

            $assertSame(
                'manual_category',
                'completion fields recalculated',
                ['brand'],
                data_get(
                    $manualCandidate->metadata,
                    'assisted_review.completion_fields'
                )
            );

            /*
            * Brand manuale.
            */
            $manualCandidate = $decisionService->setManualValue(
                candidate: $manualCandidate,
                fieldName: 'brand',
                value: $manualBrandName,
                userId: $userId,
            );

            $manualBrand = Brand::query()->find(
                $manualCandidate->brand_id
            );

            $assertTrue(
                'manual_brand',
                'brand created',
                $manualBrand !== null
            );

            $assertSame(
                'manual_brand',
                'private team brand',
                $teamId,
                (int) $manualBrand?->team_id
            );

            $assertSame(
                'manual_brand',
                'field state modified',
                'modified',
                data_get(
                    $manualCandidate->metadata,
                    'assisted_review.fields.brand.state'
                )
            );

            $assertSame(
                'manual_brand',
                'completion fields empty',
                [],
                data_get(
                    $manualCandidate->metadata,
                    'assisted_review.completion_fields'
                )
            );

            $assertSame(
                'manual_brand',
                'completion no longer required',
                false,
                data_get(
                    $manualCandidate->metadata,
                    'assisted_review.needs_user_completion'
                )
            );

            $assertSame(
                'manual_brand',
                'foreign metadata preserved',
                true,
                data_get(
                    $manualCandidate->metadata,
                    'manual_test_namespace.preserve_me'
                )
            );

            /*
            * Idempotenza del valore manuale.
            */
            $manualDecisionTimestamp = data_get(
                $manualCandidate->metadata,
                'assisted_review.fields.brand.decision.decided_at'
            );

            $manualBrandCountBeforeRetry = Brand::query()
                ->where('team_id', $teamId)
                ->where('name', $manualBrandName)
                ->count();

            $manualCandidate = $decisionService->setManualValue(
                candidate: $manualCandidate,
                fieldName: 'brand',
                value: $manualBrandName,
                userId: $userId,
            );

            $assertSame(
                'manual_brand_idempotence',
                'no duplicate brand',
                $manualBrandCountBeforeRetry,
                Brand::query()
                    ->where('team_id', $teamId)
                    ->where('name', $manualBrandName)
                    ->count()
            );

            $assertSame(
                'manual_brand_idempotence',
                'decision timestamp unchanged',
                $manualDecisionTimestamp,
                data_get(
                    $manualCandidate->metadata,
                    'assisted_review.fields.brand.decision.decided_at'
                )
            );

            /*
            * Presentazione finale delle modifiche manuali.
            */
            $manualPresentation = $presenter->present(
                $manualCandidate->loadMissing([
                    'brand',
                    'category',
                ])
            );

            $assertSame(
                'manual_presentation',
                'brand label',
                'Modificato da te',
                data_get(
                    $manualPresentation,
                    'fields.brand.state_label'
                )
            );

            $assertSame(
                'manual_presentation',
                'category label',
                'Modificato da te',
                data_get(
                    $manualPresentation,
                    'fields.category.state_label'
                )
            );

            $assertSame(
                'manual_presentation',
                'model label',
                'Modificato da te',
                data_get(
                    $manualPresentation,
                    'fields.model.state_label'
                )
            );

            $assertSame(
                'manual_candidate_lifecycle',
                'candidate remains pending',
                'pending',
                $manualCandidate->review_status
            );

            $assertSame(
                'manual_candidate_lifecycle',
                'candidate product remains null',
                null,
                $manualCandidate->product_id
            );

            $assertSame(
                'manual_candidate_lifecycle',
                'product count unchanged',
                $productsBefore,
                Product::query()->count()
            );
        } catch (Throwable $exception) {
            $rows[] = [
                'unexpected_exception',
                $exception->getMessage(),
                'FAIL',
            ];

            $failures[] = [
                'scenario' => 'unexpected_exception',
                'assertion' => $exception->getMessage(),
                'expected' => 'nessuna eccezione',
                'actual' => get_class($exception),
            ];
        } finally {
            /*
             * Annulla sia i dati di test sia le transazioni annidate
             * eventualmente ancora aperte.
             */
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        }

        /*
        * Verifica il rollback fuori dalla transazione.
        */
        if ($candidateId !== null) {
            $assertSame(
                'transaction_rollback',
                'test candidate removed',
                null,
                ProductIdentificationCandidate::query()->find(
                    $candidateId
                )
            );
        }

        if ($manualCandidateId !== null) {
            $assertSame(
                'transaction_rollback',
                'manual test candidate removed',
                null,
                ProductIdentificationCandidate::query()->find(
                    $manualCandidateId
                )
            );
        }

        if ($teamId !== null) {
            $assertSame(
                'transaction_rollback',
                'test brand removed',
                0,
                Brand::query()
                    ->where('team_id', $teamId)
                    ->where('name', $brandName)
                    ->count()
            );

            $assertSame(
                'transaction_rollback',
                'manual test brand removed',
                0,
                Brand::query()
                    ->where('team_id', $teamId)
                    ->where('name', $manualBrandName)
                    ->count()
            );
        }

        $this->table(
            ['Scenario', 'Assertion', 'Status'],
            $rows
        );

        if ($failures !== []) {
            $this->newLine();
            $this->error(
                'Assisted Review decision checks failed.'
            );

            foreach ($failures as $failure) {
                $this->newLine();

                $this->line(
                    $failure['scenario']
                    . ' / '
                    . $failure['assertion']
                );

                $this->line(
                    'Expected: '
                    . $this->renderValue(
                        $failure['expected']
                    )
                );

                $this->line(
                    'Actual:   '
                    . $this->renderValue(
                        $failure['actual']
                    )
                );
            }

            return self::FAILURE;
        }

        $this->newLine();

        $this->info(
            'Assisted Review decision checks passed. '
            . 'All test data was rolled back.'
        );

        return self::SUCCESS;
    }

    /**
     * Converte un valore in una rappresentazione leggibile.
     */
    private function renderValue(mixed $value): string
    {
        if ($value instanceof ProductIdentificationCandidate) {
            return 'candidate#' . $value->id;
        }

        $encoded = json_encode(
            $value,
            JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
        );

        return $encoded !== false
            ? $encoded
            : get_debug_type($value);
    }
}