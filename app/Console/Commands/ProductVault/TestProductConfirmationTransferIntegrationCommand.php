<?php

namespace App\Console\Commands\ProductVault;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Document;
use App\Models\Product;
use App\Models\ProductIdentificationCandidate;
use App\Services\Documents\ProductFromCandidateCreator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class TestProductConfirmationTransferIntegrationCommand extends Command
{
    /**
     * Nome e argomenti del comando.
     *
     * @var string
     */
    protected $signature =
        'product-vault:test-product-confirmation-transfer-integration';

    /**
     * Descrizione del comando.
     *
     * @var string
     */
    protected $description =
        'Verifica con rollback il trasferimento dei campi Candidate → Product.';

    /**
     * Esegue il test dentro una transazione completamente annullata.
     */
    public function handle(
        ProductFromCandidateCreator $creator
    ): int {
        $rows = [];
        $failures = [];

        $trustedCandidateId = null;
        $excludedCandidateId = null;
        $optionalCandidateId = null;

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
            /*
             * Usiamo un documento reale come contenitore valido di team,
             * valuta, tipo documento e utente. Tutti i record sintetici
             * vengono eliminati dal rollback finale.
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
             * Scenario 1: tutti i campi hanno uno stato autorizzato.
             */
            $trustedCandidate =
                ProductIdentificationCandidate::query()->create([
                    'document_id' => $document->id,
                    'document_line_id' => null,
                    'product_id' => null,
                    'brand_id' => $brand->id,
                    'category_id' => $category->id,
                    'name' => 'Transfer Integration Trusted '
                        . Str::uuid(),
                    'model' => 'TRANSFER-TRUSTED',
                    'serial_number' => null,
                    'ean_code' => null,
                    'price' => 49.90,
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
                                    'state' => 'confirmed',
                                    'required' => false,
                                ],
                                'model' => [
                                    'state' => 'modified',
                                    'required' => false,
                                ],
                            ],
                        ],
                    ],
                ]);

            $trustedCandidateId =
                (int) $trustedCandidate->id;

            $trustedProduct = $creator->create(
                candidate: $trustedCandidate,
                userId: $userId,
            );

            $trustedCandidate->refresh();

            $assertSame(
                'trusted_transfer',
                'brand transferred',
                (int) $brand->id,
                (int) $trustedProduct->brand_id
            );

            $assertSame(
                'trusted_transfer',
                'category transferred',
                (int) $category->id,
                (int) $trustedProduct->category_id
            );

            $assertSame(
                'trusted_transfer',
                'model transferred',
                'TRANSFER-TRUSTED',
                $trustedProduct->model
            );

            $assertSame(
                'trusted_transfer',
                'candidate confirmed',
                'confirmed',
                $trustedCandidate->review_status
            );

            $assertSame(
                'trusted_transfer',
                'candidate linked',
                (int) $trustedProduct->id,
                (int) $trustedCandidate->product_id
            );

            /*
             * Scenario 2: il candidato conserva valori grezzi, ma tutti
             * i campi sono stati dichiarati non disponibili.
             *
             * Il creator deve salvare valori nulli nel prodotto.
             */
            $excludedCandidate =
                ProductIdentificationCandidate::query()->create([
                    'document_id' => $document->id,
                    'document_line_id' => null,
                    'product_id' => null,
                    'brand_id' => $brand->id,
                    'category_id' => $category->id,
                    'name' => 'Transfer Integration Excluded '
                        . Str::uuid(),
                    'model' => 'AX3000',
                    'serial_number' => null,
                    'ean_code' => null,
                    'price' => 49.90,
                    'source' => 'transactional_test',
                    'confidence_score' => 70,
                    'is_selected' => false,
                    'review_status' => 'pending',
                    'metadata' => [
                        'assisted_review' => [
                            'version' => 'v1',
                            'needs_user_completion' => false,
                            'completion_fields' => [],
                            'fields' => [
                                'brand' => [
                                    'state' => 'declined',
                                    'required' => false,
                                ],
                                'category' => [
                                    'state' => 'declined',
                                    'required' => false,
                                ],
                                'model' => [
                                    'state' => 'declined',
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

            $excludedCandidateId =
                (int) $excludedCandidate->id;

            $excludedProduct = $creator->create(
                candidate: $excludedCandidate,
                userId: $userId,
            );

            $excludedCandidate->refresh();

            $assertSame(
                'excluded_transfer',
                'brand excluded',
                null,
                $excludedProduct->brand_id
            );

            $assertSame(
                'excluded_transfer',
                'category excluded',
                null,
                $excludedProduct->category_id
            );

            $assertSame(
                'excluded_transfer',
                'model excluded',
                null,
                $excludedProduct->model
            );

            $assertSame(
                'excluded_transfer',
                'raw candidate brand preserved',
                (int) $brand->id,
                (int) $excludedCandidate->brand_id
            );

            $assertSame(
                'excluded_transfer',
                'raw candidate category preserved',
                (int) $category->id,
                (int) $excludedCandidate->category_id
            );

            $assertSame(
                'excluded_transfer',
                'raw candidate model preserved',
                'AX3000',
                $excludedCandidate->model
            );

            $assertSame(
                'excluded_transfer',
                'candidate confirmed',
                'confirmed',
                $excludedCandidate->review_status
            );

            /*
            * Scenario 3: campi opzionali ancora incompleti.
            *
            * Brand e modello non devono bloccare la conferma e non devono
            * essere trasferiti. La categoria presente resta invece valida.
            */
            $optionalCandidate =
                ProductIdentificationCandidate::query()->create([
                    'document_id' => $document->id,
                    'document_line_id' => null,
                    'product_id' => null,
                    'brand_id' => $brand->id,
                    'category_id' => $category->id,
                    'name' => 'Transfer Integration Optional '
                        . Str::uuid(),
                    'model' => 'AX3000',
                    'serial_number' => null,
                    'ean_code' => null,
                    'price' => 79.90,
                    'source' => 'transactional_test',
                    'confidence_score' => 75,
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
                                        'ref' => [
                                            'type' => 'brand',
                                            'id' => $brand->id,
                                        ],
                                    ],
                                    'suggestion' => [
                                        'value' => $brand->name,
                                    ],
                                ],
                                'category' => [
                                    'state' => 'present',
                                    'required' => false,
                                    'current' => [
                                        'value' => $category->name,
                                        'ref' => [
                                            'type' => 'category',
                                            'id' => $category->id,
                                        ],
                                    ],
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

            $optionalCandidateId =
                (int) $optionalCandidate->id;

            $optionalProduct = $creator->create(
                candidate: $optionalCandidate,
                userId: $userId,
            );

            $optionalCandidate->refresh();

            $assertSame(
                'optional_completion',
                'product created',
                true,
                $optionalProduct->exists
            );

            $assertSame(
                'optional_completion',
                'brand suggestion excluded',
                null,
                $optionalProduct->brand_id
            );

            $assertSame(
                'optional_completion',
                'present category transferred',
                (int) $category->id,
                (int) $optionalProduct->category_id
            );

            $assertSame(
                'optional_completion',
                'missing model excluded',
                null,
                $optionalProduct->model
            );

            $assertSame(
                'optional_completion',
                'raw candidate brand preserved',
                (int) $brand->id,
                (int) $optionalCandidate->brand_id
            );

            $assertSame(
                'optional_completion',
                'raw candidate model preserved',
                'AX3000',
                $optionalCandidate->model
            );

            $assertSame(
                'optional_completion',
                'candidate confirmed',
                'confirmed',
                $optionalCandidate->review_status
            );

            $assertSame(
                'transaction',
                'three products created',
                3,
                Product::query()->count() - $productsBefore
            );
        } catch (Throwable $exception) {
            $rows[] = [
                'runtime',
                'integration completed',
                'FAIL',
            ];

            $failures[] = [
                'scenario' => 'runtime',
                'assertion' => 'integration completed',
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

        if ($trustedCandidateId !== null) {
            $assertSame(
                'rollback',
                'trusted candidate removed',
                false,
                ProductIdentificationCandidate::query()
                    ->whereKey($trustedCandidateId)
                    ->exists()
            );
        }

        if ($excludedCandidateId !== null) {
            $assertSame(
                'rollback',
                'excluded candidate removed',
                false,
                ProductIdentificationCandidate::query()
                    ->whereKey($excludedCandidateId)
                    ->exists()
            );
        }

        if ($optionalCandidateId !== null) {
            $assertSame(
                'rollback',
                'optional candidate removed',
                false,
                ProductIdentificationCandidate::query()
                    ->whereKey($optionalCandidateId)
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
            'Product confirmation transfer integration checks passed.'
        );

        return self::SUCCESS;
    }
}