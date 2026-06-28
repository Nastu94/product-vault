<?php

namespace App\Console\Commands\ProductVault;

use App\Models\Product;
use App\Services\Warranties\ManualWarrantyCoverageContextBuilder;
use Illuminate\Console\Command;

final class TestManualWarrantyCoverageContextCommand extends Command
{
    /**
     * @var string
     */
    protected $signature =
        'product-vault:test-manual-warranty-coverage-context';

    /**
     * @var string
     */
    protected $description =
        'Run controlled manual warranty coverage context checks';

    public function handle(
        ManualWarrantyCoverageContextBuilder $builder
    ): int {
        $rows = [];
        $failures = [];

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

            if ($passed) {
                return;
            }

            $failures[] = [
                'scenario' => $scenario,
                'assertion' => $assertion,
                'expected' => $expected,
                'actual' => $actual,
            ];
        };

        /*
        |--------------------------------------------------------------------------
        | Scenario 1: nuova copertura creata manualmente
        |--------------------------------------------------------------------------
        */
        $newProduct = $this->makeProduct(
            purchaseDate: '2026-06-10'
        );

        $newMetadata = [
            'creator' => 'manual_warranty_creation_v1',
            'created_from' => 'product_show',
            'created_at' => '2026-06-28T10:00:00+02:00',
            'created_by_user_id' => 42,
        ];

        $newMetadataBefore = $newMetadata;

        $newContext = $builder->build(
            product: $newProduct,
            metadata: $newMetadata,
            userId: 42,
            confirmedAt: '2026-06-28T10:00:00+02:00',
        );

        $assertSame(
            'manual_creation',
            'context version',
            ManualWarrantyCoverageContextBuilder::VERSION,
            data_get($newContext, 'version'),
        );

        $assertSame(
            'manual_creation',
            'coverage state',
            'user_confirmed',
            data_get($newContext, 'state'),
        );

        $assertSame(
            'manual_creation',
            'purchase use starts unknown',
            'unknown',
            data_get($newContext, 'purchase.use'),
        );

        $assertSame(
            'manual_creation',
            'seller type starts unknown',
            'unknown',
            data_get($newContext, 'purchase.seller_type'),
        );

        $assertSame(
            'manual_creation',
            'product condition starts unknown',
            'unknown',
            data_get($newContext, 'product.condition'),
        );

        $assertSame(
            'manual_creation',
            'country starts unknown',
            null,
            data_get(
                $newContext,
                'jurisdiction.country_code'
            ),
        );

        $assertSame(
            'manual_creation',
            'purchase date copied from product',
            '2026-06-10',
            data_get($newContext, 'dates.purchased_at'),
        );

        $assertSame(
            'manual_creation',
            'delivery date starts unknown',
            null,
            data_get($newContext, 'dates.delivered_at'),
        );

        $assertSame(
            'manual_creation',
            'date source is manual input',
            'manual_user_input',
            data_get(
                $newContext,
                'dates.starts_at_source'
            ),
        );

        $assertSame(
            'manual_creation',
            'declared coverage starts unknown',
            null,
            data_get(
                $newContext,
                'declared_coverage.present'
            ),
        );

        $assertSame(
            'manual_creation',
            'confirmation applied',
            true,
            data_get(
                $newContext,
                'confirmation.applied'
            ),
        );

        $assertSame(
            'manual_creation',
            'confirmation timestamp',
            '2026-06-28T10:00:00+02:00',
            data_get(
                $newContext,
                'confirmation.confirmed_at'
            ),
        );

        $assertSame(
            'manual_creation',
            'confirming user',
            42,
            data_get(
                $newContext,
                'confirmation.confirmed_by_user_id'
            ),
        );

        $assertSame(
            'manual_creation',
            'input metadata unchanged',
            $newMetadataBefore,
            $newMetadata,
        );

        $assertSame(
            'manual_creation',
            'product remains clean',
            [],
            $newProduct->getDirty(),
        );

        /*
        |--------------------------------------------------------------------------
        | Scenario 2: contesto precedente preservato
        |--------------------------------------------------------------------------
        */
        $existingProduct = $this->makeProduct(
            purchaseDate: '2026-01-02'
        );

        $existingMetadata = [
            'country_code' => 'DE',

            'coverage_context' => [
                'version' => 'legacy-v0',
                'state' => 'estimated',

                'purchase' => [
                    'use' => 'personal',
                    'seller_type' => 'professional',
                ],

                'product' => [
                    'condition' => 'refurbished',
                ],

                'jurisdiction' => [
                    'country_code' => ' it ',
                ],

                'dates' => [
                    'purchased_at' => '2025-12-20',
                    'delivered_at' => '2026-01-04',
                    'starts_at_source' =>
                        'product.purchase_date',
                ],

                'declared_coverage' => [
                    'present' => true,
                ],

                'confirmation' => [
                    'applied' => false,
                    'confirmed_at' => null,
                    'confirmed_by_user_id' => null,
                ],

                'custom_provenance' => [
                    'source' => 'legacy_import',
                ],
            ],
        ];

        $existingMetadataBefore = $existingMetadata;

        $preservedContext = $builder->build(
            product: $existingProduct,
            metadata: $existingMetadata,
            userId: 77,
            confirmedAt: '2026-06-28T11:30:00+02:00',
        );

        $assertSame(
            'existing_context',
            'version upgraded',
            'v1',
            data_get($preservedContext, 'version'),
        );

        $assertSame(
            'existing_context',
            'state becomes user confirmed',
            'user_confirmed',
            data_get($preservedContext, 'state'),
        );

        $assertSame(
            'existing_context',
            'purchase use preserved',
            'personal',
            data_get($preservedContext, 'purchase.use'),
        );

        $assertSame(
            'existing_context',
            'seller type preserved',
            'professional',
            data_get(
                $preservedContext,
                'purchase.seller_type'
            ),
        );

        $assertSame(
            'existing_context',
            'condition preserved',
            'refurbished',
            data_get(
                $preservedContext,
                'product.condition'
            ),
        );

        $assertSame(
            'existing_context',
            'context country preferred and normalized',
            'IT',
            data_get(
                $preservedContext,
                'jurisdiction.country_code'
            ),
        );

        $assertSame(
            'existing_context',
            'stored purchase date preserved',
            '2025-12-20',
            data_get(
                $preservedContext,
                'dates.purchased_at'
            ),
        );

        $assertSame(
            'existing_context',
            'delivery date preserved',
            '2026-01-04',
            data_get(
                $preservedContext,
                'dates.delivered_at'
            ),
        );

        $assertSame(
            'existing_context',
            'date source replaced by manual input',
            'manual_user_input',
            data_get(
                $preservedContext,
                'dates.starts_at_source'
            ),
        );

        $assertSame(
            'existing_context',
            'declared coverage preserved',
            true,
            data_get(
                $preservedContext,
                'declared_coverage.present'
            ),
        );

        $assertSame(
            'existing_context',
            'custom provenance preserved',
            'legacy_import',
            data_get(
                $preservedContext,
                'custom_provenance.source'
            ),
        );

        $assertSame(
            'existing_context',
            'confirmation replaced',
            [
                'applied' => true,
                'confirmed_at' =>
                    '2026-06-28T11:30:00+02:00',
                'confirmed_by_user_id' => 77,
            ],
            data_get(
                $preservedContext,
                'confirmation'
            ),
        );

        $assertSame(
            'existing_context',
            'input metadata unchanged',
            $existingMetadataBefore,
            $existingMetadata,
        );

        $assertSame(
            'existing_context',
            'product remains clean',
            [],
            $existingProduct->getDirty(),
        );

        /*
        |--------------------------------------------------------------------------
        | Scenario 3: metadata legacy o malformati
        |--------------------------------------------------------------------------
        */
        $legacyProduct = $this->makeProduct(
            purchaseDate: '2026-03-15'
        );

        $legacyMetadata = [
            'country_code' => ' it ',

            'coverage_context' => [
                'version' => 123,
                'state' => null,
                'purchase' => 'invalid',
                'product' => null,
                'jurisdiction' => 'invalid',

                'dates' => [
                    'purchased_at' => '   ',
                    'delivered_at' => null,
                ],

                'declared_coverage' => 'invalid',
                'confirmation' => 'invalid',
            ],
        ];

        $legacyMetadataBefore = $legacyMetadata;

        $legacyContext = $builder->build(
            product: $legacyProduct,
            metadata: $legacyMetadata,
            userId: 19,
            confirmedAt: '2026-06-28T12:00:00+02:00',
        );

        $assertSame(
            'legacy_metadata',
            'version repaired',
            'v1',
            data_get($legacyContext, 'version'),
        );

        $assertSame(
            'legacy_metadata',
            'state repaired',
            'user_confirmed',
            data_get($legacyContext, 'state'),
        );

        $assertSame(
            'legacy_metadata',
            'purchase section repaired',
            [
                'use' => 'unknown',
                'seller_type' => 'unknown',
            ],
            data_get($legacyContext, 'purchase'),
        );

        $assertSame(
            'legacy_metadata',
            'product section repaired',
            [
                'condition' => 'unknown',
            ],
            data_get($legacyContext, 'product'),
        );

        $assertSame(
            'legacy_metadata',
            'legacy country normalized',
            'IT',
            data_get(
                $legacyContext,
                'jurisdiction.country_code'
            ),
        );

        $assertSame(
            'legacy_metadata',
            'blank purchase date repaired',
            '2026-03-15',
            data_get(
                $legacyContext,
                'dates.purchased_at'
            ),
        );

        $assertSame(
            'legacy_metadata',
            'date source repaired',
            'manual_user_input',
            data_get(
                $legacyContext,
                'dates.starts_at_source'
            ),
        );

        $assertSame(
            'legacy_metadata',
            'declared coverage section repaired',
            [
                'present' => null,
            ],
            data_get(
                $legacyContext,
                'declared_coverage'
            ),
        );

        $assertSame(
            'legacy_metadata',
            'confirmation section repaired',
            [
                'applied' => true,
                'confirmed_at' =>
                    '2026-06-28T12:00:00+02:00',
                'confirmed_by_user_id' => 19,
            ],
            data_get(
                $legacyContext,
                'confirmation'
            ),
        );

        $assertSame(
            'legacy_metadata',
            'input metadata unchanged',
            $legacyMetadataBefore,
            $legacyMetadata,
        );

        $assertSame(
            'legacy_metadata',
            'product remains clean',
            [],
            $legacyProduct->getDirty(),
        );

        /*
        |--------------------------------------------------------------------------
        | Scenario 4: prodotto senza data di acquisto
        |--------------------------------------------------------------------------
        */
        $undatedProduct = $this->makeProduct(
            purchaseDate: null
        );

        $undatedContext = $builder->build(
            product: $undatedProduct,
            metadata: [],
            userId: 5,
            confirmedAt: '2026-06-28T13:00:00+02:00',
        );

        $assertSame(
            'missing_purchase_date',
            'purchase date remains unknown',
            null,
            data_get(
                $undatedContext,
                'dates.purchased_at'
            ),
        );

        $assertSame(
            'missing_purchase_date',
            'coverage still user confirmed',
            'user_confirmed',
            data_get($undatedContext, 'state'),
        );

        $assertSame(
            'missing_purchase_date',
            'confirmation still recorded',
            true,
            data_get(
                $undatedContext,
                'confirmation.applied'
            ),
        );

        /*
        |--------------------------------------------------------------------------
        | Risultato
        |--------------------------------------------------------------------------
        */
        $this->table(
            [
                'Scenario',
                'Assertion',
                'Status',
            ],
            $rows,
        );

        if ($failures !== []) {
            $this->error(
                'Manual warranty coverage context checks failed.'
            );

            foreach ($failures as $failure) {
                $this->newLine();

                $this->warn(
                    $failure['scenario']
                    .' / '
                    .$failure['assertion']
                );

                $this->line(
                    'Expected: '
                    .json_encode(
                        $failure['expected'],
                        JSON_UNESCAPED_UNICODE
                    )
                );

                $this->line(
                    'Actual:   '
                    .json_encode(
                        $failure['actual'],
                        JSON_UNESCAPED_UNICODE
                    )
                );
            }

            return self::FAILURE;
        }

        $this->info(
            'Manual warranty coverage context checks passed.'
        );

        return self::SUCCESS;
    }

    private function makeProduct(
        ?string $purchaseDate
    ): Product {
        $product = new Product();

        $product->forceFill([
            'name' => 'Manual Warranty Coverage Fixture',
            'purchase_date' => $purchaseDate,
        ]);

        /*
         * Il prodotto è soltanto una fixture in memoria.
         * Il builder non deve alterarne gli attributi.
         */
        $product->syncOriginal();

        return $product;
    }
}