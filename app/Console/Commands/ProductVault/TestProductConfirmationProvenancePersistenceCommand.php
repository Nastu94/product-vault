<?php

namespace App\Console\Commands\ProductVault;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Document;
use App\Models\DocumentLine;
use App\Models\DocumentLineType;
use App\Models\Product;
use App\Models\ProductEvent;
use App\Models\ProductIdentificationCandidate;
use App\Services\Documents\ProductFromCandidateCreator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class TestProductConfirmationProvenancePersistenceCommand extends Command
{
    /**
     * Nome e argomenti del comando.
     *
     * @var string
     */
    protected $signature =
        'product-vault:test-product-confirmation-provenance-persistence';

    /**
     * Descrizione del comando.
     *
     * @var string
     */
    protected $description =
        'Verifica con rollback la persistenza immutabile della provenienza Candidate → Product.';

    /**
     * Esegue il test senza lasciare dati persistiti.
     */
    public function handle(
        ProductFromCandidateCreator $creator
    ): int {
        $rows = [];
        $failures = [];

        $candidateId = null;
        $lineId = null;

        $productsBefore = Product::query()->count();
        $eventsBefore = ProductEvent::query()->count();

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
            /*
             * Usiamo un documento reale come contenitore valido.
             * Riga, candidato, prodotto ed eventi sintetici vengono
             * rimossi dal rollback finale.
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
            $userId =
                (int) $document->uploaded_by_user_id;

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

            $lineType = DocumentLineType::query()
                ->orderBy('id')
                ->first();

            if ($lineType === null) {
                throw new RuntimeException(
                    'Nessun tipo riga utilizzabile per il test.'
                );
            }

            $nextLineNumber = (
                (int) DocumentLine::query()
                    ->where('document_id', $document->id)
                    ->max('line_number')
            ) + 1000;

            $originalRawText =
                'Router NetworkPro AX3000 1 x 129,90';

            $line = DocumentLine::query()->create([
                'document_id' => $document->id,
                'document_line_type_id' => $lineType->id,
                'line_number' => $nextLineNumber,
                'raw_text' => $originalRawText,
                'description' =>
                    'Router NetworkPro AX3000',
                'quantity' => 1,
                'unit_price' => 129.90,
                'total_price' => 129.90,
                'confidence_score' => 91,
                'metadata' => [
                    'product_code_candidate' =>
                        'NP-ROUTER-01',
                    'serial_number_candidate' => null,
                    'document_line_amount_consistency' => [
                        'checked' => true,
                        'is_consistent' => true,
                    ],
                ],
            ]);

            $lineId = (int) $line->id;

            /*
             * Brand e modello sono valori grezzi non confermati.
             * La categoria è invece trasferibile.
             */
            $candidate =
                ProductIdentificationCandidate::query()->create([
                    'document_id' => $document->id,
                    'document_line_id' => $line->id,
                    'product_id' => null,
                    'brand_id' => $brand->id,
                    'category_id' => $category->id,
                    'name' => 'Provenance Persistence '
                        . Str::uuid(),
                    'model' => 'AX3000',
                    'serial_number' => null,
                    'ean_code' => '8050000000701',
                    'price' => 129.90,
                    'source' => 'transactional_test',
                    'confidence_score' => 84,
                    'is_selected' => false,
                    'review_status' => 'pending',
                    'metadata' => [
                        'assisted_review' => [
                            'version' => 'v1',
                            'needs_user_completion' => true,
                            'completion_fields' => [
                                'brand',
                                'model',
                            ],
                            'fields' => [
                                'brand' => [
                                    'state' => 'suggested',
                                    'required' => false,
                                    'current' => [
                                        'value' => $brand->name,
                                    ],
                                    'suggestion' => [
                                        'value' => $brand->name,
                                    ],
                                ],
                                'category' => [
                                    'state' => 'present',
                                    'required' => false,
                                    'current' => [
                                        'value' =>
                                            $category->name,
                                        'ref' => [
                                            'id' =>
                                                $category->id,
                                        ],
                                    ],
                                ],
                                'model' => [
                                    'state' => 'missing',
                                    'required' => false,
                                    'current' => [
                                        'value' => 'AX3000',
                                    ],
                                    'issues' => [
                                        'technical_specification_used_as_model',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ]);

            $candidateId = (int) $candidate->id;

            $originalCandidateName =
                (string) $candidate->name;

            $product = $creator->create(
                candidate: $candidate,
                userId: $userId,
            );

            $event = ProductEvent::query()
                ->where('product_id', $product->id)
                ->where('source', 'candidate_confirmation')
                ->firstOrFail();

            $snapshotBeforeMutation = data_get(
                $event->metadata,
                'confirmation_provenance'
            );

            $assertSame(
                'persistence',
                'snapshot version',
                'product_confirmation_provenance_v1',
                data_get(
                    $snapshotBeforeMutation,
                    'version'
                )
            );

            $assertSame(
                'persistence',
                'candidate reference',
                $candidateId,
                data_get(
                    $snapshotBeforeMutation,
                    'references.candidate_id'
                )
            );

            $assertSame(
                'persistence',
                'line reference',
                $lineId,
                data_get(
                    $snapshotBeforeMutation,
                    'references.document_line_id'
                )
            );

            $assertSame(
                'candidate_evidence',
                'original name preserved',
                $originalCandidateName,
                data_get(
                    $snapshotBeforeMutation,
                    'candidate.name'
                )
            );

            $assertSame(
                'candidate_evidence',
                'raw model preserved',
                'AX3000',
                data_get(
                    $snapshotBeforeMutation,
                    'candidate.model'
                )
            );

            $assertSame(
                'resolved_values',
                'brand excluded',
                null,
                data_get(
                    $snapshotBeforeMutation,
                    'resolved_product_values.brand_id'
                )
            );

            $assertSame(
                'resolved_values',
                'category transferred',
                (int) $category->id,
                data_get(
                    $snapshotBeforeMutation,
                    'resolved_product_values.category_id'
                )
            );

            $assertSame(
                'resolved_values',
                'model excluded',
                null,
                data_get(
                    $snapshotBeforeMutation,
                    'resolved_product_values.model'
                )
            );

            $assertSame(
                'line_evidence',
                'original raw text',
                $originalRawText,
                data_get(
                    $snapshotBeforeMutation,
                    'document_line.raw_text'
                )
            );

            $assertSame(
                'product',
                'brand not transferred',
                null,
                $product->brand_id
            );

            $assertSame(
                'product',
                'category transferred',
                (int) $category->id,
                (int) $product->category_id
            );

            $assertSame(
                'product',
                'model not transferred',
                null,
                $product->model
            );

            /*
             * Modifichiamo deliberatamente le sorgenti dopo la conferma.
             *
             * Lo snapshot già persistito nell'evento non deve cambiare.
             */
            $candidate->refresh();

            $candidate->update([
                'name' => 'Candidate Mutated After Confirmation',
                'brand_id' => null,
                'category_id' => null,
                'model' => 'MUTATED-MODEL',
                'metadata' => [
                    'mutated_after_confirmation' => true,
                ],
            ]);

            $line->update([
                'raw_text' =>
                    'MUTATED RAW TEXT AFTER CONFIRMATION',
                'description' =>
                    'Mutated line after confirmation',
                'quantity' => 2,
                'unit_price' => 1,
                'total_price' => 2,
                'metadata' => [
                    'mutated_after_confirmation' => true,
                ],
            ]);

            /*
             * Anche un retry successivo alle modifiche deve restituire
             * lo stesso prodotto senza ricostruire lo snapshot.
             */
            $retryProduct = $creator->create(
                candidate: $candidate->fresh(),
                userId: $userId,
            );

            $event->refresh();

            $snapshotAfterMutation = data_get(
                $event->metadata,
                'confirmation_provenance'
            );

            $assertSame(
                'immutability',
                'stored snapshot unchanged',
                $snapshotBeforeMutation,
                $snapshotAfterMutation
            );

            $assertSame(
                'immutability',
                'original candidate name retained',
                $originalCandidateName,
                data_get(
                    $snapshotAfterMutation,
                    'candidate.name'
                )
            );

            $assertSame(
                'immutability',
                'original raw text retained',
                $originalRawText,
                data_get(
                    $snapshotAfterMutation,
                    'document_line.raw_text'
                )
            );

            $assertSame(
                'retry',
                'same product returned',
                (int) $product->id,
                (int) $retryProduct->id
            );

            $assertSame(
                'retry',
                'one confirmation event',
                1,
                ProductEvent::query()
                    ->where('product_id', $product->id)
                    ->where(
                        'source',
                        'candidate_confirmation'
                    )
                    ->count()
            );

            $assertSame(
                'retry',
                'product values unchanged',
                [
                    'name' => $originalCandidateName,
                    'brand_id' => null,
                    'category_id' =>
                        (int) $category->id,
                    'model' => null,
                ],
                [
                    'name' => $retryProduct->name,
                    'brand_id' =>
                        $retryProduct->brand_id,
                    'category_id' =>
                        (int) $retryProduct->category_id,
                    'model' => $retryProduct->model,
                ]
            );
        } catch (Throwable $exception) {
            $rows[] = [
                'runtime',
                'provenance persistence completed',
                'FAIL',
            ];

            $failures[] = [
                'scenario' => 'runtime',
                'assertion' =>
                    'provenance persistence completed',
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

        $assertSame(
            'rollback',
            'event count restored',
            $eventsBefore,
            ProductEvent::query()->count()
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

        if ($lineId !== null) {
            $assertSame(
                'rollback',
                'line removed',
                false,
                DocumentLine::query()
                    ->whereKey($lineId)
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
            'Product confirmation provenance persistence checks passed.'
        );

        return self::SUCCESS;
    }
}