<?php

namespace App\Console\Commands\ProductVault;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Document;
use App\Models\Product;
use App\Models\ProductEvent;
use App\Models\ProductIdentificationCandidate;
use App\Models\ProductUnderstandingFeedback;
use App\Models\Warranty;
use App\Services\Documents\ProductFromCandidateCreator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class TestProductConfirmationIdempotencyCommand extends Command
{
    /**
     * Nome e argomenti del comando.
     *
     * @var string
     */
    protected $signature =
        'product-vault:test-product-confirmation-idempotency';

    /**
     * Descrizione del comando.
     *
     * @var string
     */
    protected $description =
        'Verifica con rollback che la conferma Candidate → Product sia idempotente.';

    /**
     * Esegue il test senza lasciare dati persistiti.
     */
    public function handle(
        ProductFromCandidateCreator $creator
    ): int {
        $rows = [];
        $failures = [];

        $candidateId = null;
        $ignoredCandidateId = null;

        $productsBefore = Product::query()->count();

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

            $brand = Brand::query()
                ->where('is_active', true)
                ->where(function ($query) use ($teamId): void {
                    $query
                        ->whereNull('team_id')
                        ->orWhere('team_id', $teamId);
                })
                ->orderBy('id')
                ->first();

            if ($brand === null) {
                throw new RuntimeException(
                    'Nessun brand utilizzabile per il test.'
                );
            }

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

            /*
             * Candidato valido e completamente confermabile.
             */
            $candidate =
                ProductIdentificationCandidate::query()->create([
                    'document_id' => $document->id,
                    'document_line_id' => null,
                    'product_id' => null,
                    'brand_id' => $brand->id,
                    'category_id' => $category->id,
                    'name' => 'Idempotency Test '
                        . Str::uuid(),
                    'model' => 'IDEMPOTENT-100',
                    'serial_number' => null,
                    'ean_code' => null,
                    'price' => 129.90,
                    'source' => 'transactional_test',
                    'confidence_score' => 85,
                    'is_selected' => false,
                    'review_status' => 'pending',
                    'metadata' => [
                        'assisted_review' => [
                            'version' => 'v1',
                            'needs_user_completion' => false,
                            'completion_fields' => [],
                            'fields' => [
                                'brand' => [
                                    'state' => 'present',
                                    'required' => false,
                                ],
                                'category' => [
                                    'state' => 'present',
                                    'required' => false,
                                ],
                                'model' => [
                                    'state' => 'present',
                                    'required' => false,
                                ],
                            ],
                        ],
                    ],
                ]);

            $candidateId = (int) $candidate->id;

            /*
             * Prima conferma: crea prodotto ed effetti collaterali.
             */
            $firstProduct = $creator->create(
                candidate: $candidate,
                userId: $userId,
            );

            $candidate->refresh();

            $productCountAfterFirst =
                Product::query()->count();

            $eventCountAfterFirst =
                ProductEvent::query()
                    ->where('product_id', $firstProduct->id)
                    ->count();

            $warrantyCountAfterFirst =
                Warranty::query()
                    ->where('product_id', $firstProduct->id)
                    ->count();

            $feedbackCountAfterFirst =
                ProductUnderstandingFeedback::query()
                    ->where('candidate_id', $candidate->id)
                    ->count();

            $documentLinkCountAfterFirst =
                $firstProduct->documents()
                    ->whereKey($document->id)
                    ->count();

            $reviewedAtAfterFirst =
                $candidate->reviewed_at?->toISOString();

            $assertSame(
                'first_confirmation',
                'one product created',
                $productsBefore + 1,
                $productCountAfterFirst
            );

            $assertSame(
                'first_confirmation',
                'candidate linked',
                (int) $firstProduct->id,
                (int) $candidate->product_id
            );

            $assertSame(
                'first_confirmation',
                'candidate confirmed',
                'confirmed',
                $candidate->review_status
            );

            $assertSame(
                'first_confirmation',
                'one document link',
                1,
                $documentLinkCountAfterFirst
            );

            $assertSame(
                'first_confirmation',
                'one feedback record',
                1,
                $feedbackCountAfterFirst
            );

            /*
             * Seconda conferma dello stesso candidato.
             *
             * Deve restituire il prodotto esistente senza rieseguire gli
             * effetti collaterali.
             */
            $retryProduct = $creator->create(
                candidate: $candidate,
                userId: $userId,
            );

            $candidate->refresh();

            $assertSame(
                'retry',
                'same product returned',
                (int) $firstProduct->id,
                (int) $retryProduct->id
            );

            $assertSame(
                'retry',
                'product count unchanged',
                $productCountAfterFirst,
                Product::query()->count()
            );

            $assertSame(
                'retry',
                'event count unchanged',
                $eventCountAfterFirst,
                ProductEvent::query()
                    ->where('product_id', $firstProduct->id)
                    ->count()
            );

            $assertSame(
                'retry',
                'warranty count unchanged',
                $warrantyCountAfterFirst,
                Warranty::query()
                    ->where('product_id', $firstProduct->id)
                    ->count()
            );

            $assertSame(
                'retry',
                'feedback count unchanged',
                $feedbackCountAfterFirst,
                ProductUnderstandingFeedback::query()
                    ->where('candidate_id', $candidate->id)
                    ->count()
            );

            $assertSame(
                'retry',
                'document link count unchanged',
                $documentLinkCountAfterFirst,
                $retryProduct->documents()
                    ->whereKey($document->id)
                    ->count()
            );

            $assertSame(
                'retry',
                'review timestamp unchanged',
                $reviewedAtAfterFirst,
                $candidate->reviewed_at?->toISOString()
            );

            /*
             * Un candidato ignorato non deve essere confermabile.
             */
            $ignoredCandidate =
                ProductIdentificationCandidate::query()->create([
                    'document_id' => $document->id,
                    'document_line_id' => null,
                    'product_id' => null,
                    'brand_id' => null,
                    'category_id' => null,
                    'name' => 'Ignored Idempotency Test '
                        . Str::uuid(),
                    'model' => null,
                    'serial_number' => null,
                    'ean_code' => null,
                    'price' => 10.00,
                    'source' => 'transactional_test',
                    'confidence_score' => 60,
                    'is_selected' => false,
                    'review_status' => 'ignored',
                    'metadata' => [
                        'assisted_review' => [
                            'version' => 'v1',
                            'needs_user_completion' => true,
                            'completion_fields' => [
                                'brand',
                                'category',
                                'model',
                            ],
                            'fields' => [
                                'brand' => [
                                    'state' => 'missing',
                                ],
                                'category' => [
                                    'state' => 'missing',
                                ],
                                'model' => [
                                    'state' => 'missing',
                                ],
                            ],
                        ],
                    ],
                ]);

            $ignoredCandidateId =
                (int) $ignoredCandidate->id;

            $ignoredExceptionMessage = null;

            try {
                $creator->create(
                    candidate: $ignoredCandidate,
                    userId: $userId,
                );
            } catch (Throwable $exception) {
                $ignoredExceptionMessage =
                    $exception->getMessage();
            }

            $assertSame(
                'ignored_candidate',
                'confirmation rejected',
                'Il candidato non è disponibile per la conferma.',
                $ignoredExceptionMessage
            );

            $assertSame(
                'ignored_candidate',
                'no additional product',
                $productCountAfterFirst,
                Product::query()->count()
            );
        } catch (Throwable $exception) {
            $rows[] = [
                'runtime',
                'idempotency test completed',
                'FAIL',
            ];

            $failures[] = [
                'scenario' => 'runtime',
                'assertion' => 'idempotency test completed',
                'expected' => 'no exception',
                'actual' => $exception::class
                    . ': '
                    . $exception->getMessage(),
            ];
        } finally {
            DB::rollBack();
        }

        $assertSame(
            'rollback',
            'product count restored',
            $productsBefore,
            Product::query()->count()
        );

        if ($candidateId !== null) {
            $assertSame(
                'rollback',
                'candidate removed',
                false,
                ProductIdentificationCandidate::query()
                    ->whereKey($candidateId)
                    ->exists()
            );
        }

        if ($ignoredCandidateId !== null) {
            $assertSame(
                'rollback',
                'ignored candidate removed',
                false,
                ProductIdentificationCandidate::query()
                    ->whereKey($ignoredCandidateId)
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
            'Product confirmation idempotency checks passed.'
        );

        return self::SUCCESS;
    }
}