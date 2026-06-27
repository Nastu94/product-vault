<?php

namespace App\Console\Commands\ProductVault;

use App\Models\ProductIdentificationCandidate;
use App\Services\Documents\ProductConfirmation\ProductConfirmationFieldTransferPolicy;
use Illuminate\Console\Command;

class TestProductConfirmationTransferPolicyCommand extends Command
{
    /**
     * Nome e argomenti del comando.
     *
     * @var string
     */
    protected $signature =
        'product-vault:test-product-confirmation-transfer-policy';

    /**
     * Descrizione del comando.
     *
     * @var string
     */
    protected $description =
        'Verifica la policy read-only di trasferimento Candidate → Product.';

    /**
     * Esegue le verifiche senza scrivere nel database.
     */
    public function handle(
        ProductConfirmationFieldTransferPolicy $policy
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

            if (! $passed) {
                $failures[] = [
                    'scenario' => $scenario,
                    'assertion' => $assertion,
                    'expected' => $expected,
                    'actual' => $actual,
                ];
            }
        };

        /*
         * Scenario 1: candidato storico senza Assisted Review.
         */
        $legacyCandidate = $this->candidate(
            brandId: 10,
            categoryId: 20,
            model: 'LEGACY-100',
            metadata: [
                'existing_namespace' => [
                    'preserve_me' => true,
                ],
            ],
        );

        $legacyResult = $policy->resolve(
            $legacyCandidate
        );

        $assertSame(
            'legacy_candidate',
            'mode',
            'legacy_passthrough',
            $legacyResult['mode']
        );

        $assertSame(
            'legacy_candidate',
            'values preserved',
            [
                'brand_id' => 10,
                'category_id' => 20,
                'model' => 'LEGACY-100',
            ],
            $legacyResult['values']
        );

        $assertSame(
            'legacy_candidate',
            'excluded fields',
            [],
            $legacyResult['excluded_fields']
        );

        /*
         * Scenario 2: stati present, confirmed e modified.
         */
        $trustedCandidate = $this->candidate(
            brandId: 11,
            categoryId: 21,
            model: 'MANUAL-200',
            metadata: [
                'assisted_review' => [
                    'version' => 'v1',
                    'fields' => [
                        'brand' => [
                            'state' => 'present',
                        ],
                        'category' => [
                            'state' => 'confirmed',
                        ],
                        'model' => [
                            'state' => 'modified',
                        ],
                    ],
                ],
            ],
        );

        $trustedResult = $policy->resolve(
            $trustedCandidate
        );

        $assertSame(
            'trusted_states',
            'mode',
            'assisted_review',
            $trustedResult['mode']
        );

        $assertSame(
            'trusted_states',
            'values transferred',
            [
                'brand_id' => 11,
                'category_id' => 21,
                'model' => 'MANUAL-200',
            ],
            $trustedResult['values']
        );

        $assertSame(
            'trusted_states',
            'brand included',
            true,
            $trustedResult['fields']['brand']['included']
        );

        $assertSame(
            'trusted_states',
            'category included',
            true,
            $trustedResult['fields']['category']['included']
        );

        $assertSame(
            'trusted_states',
            'model included',
            true,
            $trustedResult['fields']['model']['included']
        );

        /*
         * Scenario 3: suggerito, non disponibile e mancante.
         *
         * Il modello AX3000 resta evidenza sul candidato, ma non deve essere
         * trasferito nel prodotto.
         */
        $excludedCandidate = $this->candidate(
            brandId: 99,
            categoryId: 88,
            model: 'AX3000',
            metadata: [
                'assisted_review' => [
                    'version' => 'v1',
                    'fields' => [
                        'brand' => [
                            'state' => 'suggested',
                            'suggestion' => [
                                'value' => 'Suggested Brand',
                            ],
                        ],
                        'category' => [
                            'state' => 'declined',
                        ],
                        'model' => [
                            'state' => 'missing',
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
        );

        $excludedResult = $policy->resolve(
            $excludedCandidate
        );

        $assertSame(
            'optional_fields',
            'values excluded',
            [
                'brand_id' => null,
                'category_id' => null,
                'model' => null,
            ],
            $excludedResult['values']
        );

        $assertSame(
            'optional_fields',
            'brand suggestion ignored',
            'suggestion_not_confirmed',
            $excludedResult['fields']['brand']['reason']
        );

        $assertSame(
            'optional_fields',
            'declined category ignored',
            'user_declined',
            $excludedResult['fields']['category']['reason']
        );

        $assertSame(
            'optional_fields',
            'unreliable model ignored',
            'optional_field_missing',
            $excludedResult['fields']['model']['reason']
        );

        $assertSame(
            'optional_fields',
            'candidate model preserved as evidence',
            'AX3000',
            $excludedResult['fields']['model']['candidate_value']
        );

        $assertSame(
            'optional_fields',
            'all fields excluded',
            [
                'brand',
                'category',
                'model',
            ],
            $excludedResult['excluded_fields']
        );

        /*
         * Scenario 4: stato sconosciuto.
         */
        $unknownStateCandidate = $this->candidate(
            brandId: 77,
            categoryId: null,
            model: null,
            metadata: [
                'assisted_review' => [
                    'version' => 'v1',
                    'fields' => [
                        'brand' => [
                            'state' => 'future_state',
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
        );

        $unknownStateResult = $policy->resolve(
            $unknownStateCandidate
        );

        $assertSame(
            'unknown_state',
            'brand excluded',
            null,
            $unknownStateResult['values']['brand_id']
        );

        $assertSame(
            'unknown_state',
            'conservative reason',
            'unsupported_or_invalid_state',
            $unknownStateResult['fields']['brand']['reason']
        );

        /*
         * Scenario 5: campo Assisted Review malformato.
         */
        $invalidFieldCandidate = $this->candidate(
            brandId: null,
            categoryId: 31,
            model: null,
            metadata: [
                'assisted_review' => [
                    'version' => 'v1',
                    'fields' => [
                        'brand' => [
                            'state' => 'missing',
                        ],
                        'category' => 'invalid-field',
                        'model' => [
                            'state' => 'missing',
                        ],
                    ],
                ],
            ],
        );

        $invalidFieldResult = $policy->resolve(
            $invalidFieldCandidate
        );

        $assertSame(
            'invalid_field',
            'category excluded',
            null,
            $invalidFieldResult['values']['category_id']
        );

        $assertSame(
            'invalid_field',
            'reason',
            'invalid_field_contract',
            $invalidFieldResult['fields']['category']['reason']
        );

        /*
         * Scenario 6: namespace Assisted Review malformato.
         */
        $invalidContractCandidate = $this->candidate(
            brandId: 41,
            categoryId: 51,
            model: 'INVALID-600',
            metadata: [
                'assisted_review' => 'invalid-contract',
            ],
        );

        $invalidContractResult = $policy->resolve(
            $invalidContractCandidate
        );

        $assertSame(
            'invalid_contract',
            'mode',
            'invalid_assisted_review',
            $invalidContractResult['mode']
        );

        $assertSame(
            'invalid_contract',
            'all values excluded',
            [
                'brand_id' => null,
                'category_id' => null,
                'model' => null,
            ],
            $invalidContractResult['values']
        );

        $assertSame(
            'invalid_contract',
            'reason',
            'invalid_assisted_review_contract',
            $invalidContractResult['fields']['brand']['reason']
        );

        /*
         * Scenario 7: versione Assisted Review non supportata.
         */
        $unsupportedVersionCandidate = $this->candidate(
            brandId: 61,
            categoryId: 71,
            model: 'FUTURE-700',
            metadata: [
                'assisted_review' => [
                    'version' => 'v2',
                    'fields' => [
                        'brand' => [
                            'state' => 'present',
                        ],
                        'category' => [
                            'state' => 'present',
                        ],
                        'model' => [
                            'state' => 'present',
                        ],
                    ],
                ],
            ],
        );

        $unsupportedVersionResult = $policy->resolve(
            $unsupportedVersionCandidate
        );

        $assertSame(
            'unsupported_version',
            'values excluded',
            [
                'brand_id' => null,
                'category_id' => null,
                'model' => null,
            ],
            $unsupportedVersionResult['values']
        );

        $assertSame(
            'unsupported_version',
            'reason',
            'unsupported_assisted_review_version',
            $unsupportedVersionResult['fields']['model']['reason']
        );

        /*
         * Scenario 8: la valutazione è idempotente e read-only.
         */
        $beforeAttributes = [
            'brand_id' => $excludedCandidate->brand_id,
            'category_id' => $excludedCandidate->category_id,
            'model' => $excludedCandidate->model,
            'metadata' => $excludedCandidate->metadata,
        ];

        $firstEvaluation = $policy->resolve(
            $excludedCandidate
        );

        $secondEvaluation = $policy->resolve(
            $excludedCandidate
        );

        $afterAttributes = [
            'brand_id' => $excludedCandidate->brand_id,
            'category_id' => $excludedCandidate->category_id,
            'model' => $excludedCandidate->model,
            'metadata' => $excludedCandidate->metadata,
        ];

        $assertSame(
            'idempotence',
            'same result',
            $firstEvaluation,
            $secondEvaluation
        );

        $assertSame(
            'idempotence',
            'candidate unchanged',
            $beforeAttributes,
            $afterAttributes
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
            'Product confirmation transfer policy checks passed.'
        );

        return self::SUCCESS;
    }

    /**
     * Crea un candidato esclusivamente in memoria.
     *
     * @param  array<string, mixed>  $metadata
     */
    private function candidate(
        ?int $brandId,
        ?int $categoryId,
        ?string $model,
        array $metadata
    ): ProductIdentificationCandidate {
        $candidate =
            new ProductIdentificationCandidate();

        $candidate->brand_id = $brandId;
        $candidate->category_id = $categoryId;
        $candidate->model = $model;
        $candidate->metadata = $metadata;

        return $candidate;
    }
}