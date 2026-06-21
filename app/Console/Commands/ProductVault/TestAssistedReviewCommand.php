<?php

namespace App\Console\Commands\ProductVault;

use App\Models\Brand;
use App\Models\Category;
use App\Models\ProductIdentificationCandidate;
use App\Services\Documents\AssistedReview\AssistedReviewMetadataBuilder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('product-vault:test-assisted-review')]
#[Description('Verifica il contratto base del metadata assisted_review')]
class TestAssistedReviewCommand extends Command
{
    /**
     * Esegue gli scenari isolati del contratto assisted_review.
     */
    public function handle(
        AssistedReviewMetadataBuilder $builder
    ): int {
        $rows = [];
        $failures = [];

        /**
         * Registra un'asserzione e conserva i dettagli in caso di errore.
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

        /*
         * Scenario 1:
         * tutti i valori sono già presenti sul candidato.
         */
        $brand = new Brand([
            'name' => 'Kingston',
            'normalized_name' => 'kingston',
        ]);
        $brand->id = 12;

        $category = new Category([
            'name' => 'Informatica',
            'slug' => 'informatica',
        ]);
        $category->id = 4;

        $completeCandidate = new ProductIdentificationCandidate([
            'brand_id' => 12,
            'category_id' => 4,
            'model' => 'XS1000',
            'metadata' => [
                'raw_line_text' => 'Kingston XS1000 SSD esterno',
                'custom_namespace' => [
                    'preserve_me' => true,
                ],
            ],
        ]);

        $completeCandidate->setRelation('brand', $brand);
        $completeCandidate->setRelation('category', $category);

        $attributesBeforeBuild = $completeCandidate->getAttributes();

        $completeMetadata = $builder->mergeIntoMetadata(
            $completeCandidate
        );

        $assertSame(
            'complete_candidate',
            'version',
            'v1',
            data_get($completeMetadata, 'assisted_review.version')
        );

        $assertSame(
            'complete_candidate',
            'brand state',
            'present',
            data_get(
                $completeMetadata,
                'assisted_review.fields.brand.state'
            )
        );

        $assertSame(
            'complete_candidate',
            'brand value',
            'Kingston',
            data_get(
                $completeMetadata,
                'assisted_review.fields.brand.current.value'
            )
        );

        $assertSame(
            'complete_candidate',
            'category reference key',
            'informatica',
            data_get(
                $completeMetadata,
                'assisted_review.fields.category.current.ref.key'
            )
        );

        $assertSame(
            'complete_candidate',
            'model state',
            'present',
            data_get(
                $completeMetadata,
                'assisted_review.fields.model.state'
            )
        );

        $assertSame(
            'complete_candidate',
            'completion not required',
            false,
            data_get(
                $completeMetadata,
                'assisted_review.needs_user_completion'
            )
        );

        $assertSame(
            'complete_candidate',
            'empty completion fields',
            [],
            data_get(
                $completeMetadata,
                'assisted_review.completion_fields'
            )
        );

        $assertSame(
            'complete_candidate',
            'foreign metadata preserved',
            true,
            data_get(
                $completeMetadata,
                'custom_namespace.preserve_me'
            )
        );

        $assertSame(
            'complete_candidate',
            'candidate not mutated',
            $attributesBeforeBuild,
            $completeCandidate->getAttributes()
        );

        /*
         * Scenario 2:
         * il candidato non contiene brand, categoria o modello.
         */
        $missingCandidate = new ProductIdentificationCandidate([
            'metadata' => [
                'raw_line_text' => 'Prodotto senza dati strutturati',
            ],
        ]);

        $missingMetadata = $builder->mergeIntoMetadata(
            $missingCandidate
        );

        $assertSame(
            'missing_candidate',
            'brand missing',
            'missing',
            data_get(
                $missingMetadata,
                'assisted_review.fields.brand.state'
            )
        );

        $assertSame(
            'missing_candidate',
            'category missing',
            'missing',
            data_get(
                $missingMetadata,
                'assisted_review.fields.category.state'
            )
        );

        $assertSame(
            'missing_candidate',
            'model missing',
            'missing',
            data_get(
                $missingMetadata,
                'assisted_review.fields.model.state'
            )
        );

        $assertSame(
            'missing_candidate',
            'completion required',
            true,
            data_get(
                $missingMetadata,
                'assisted_review.needs_user_completion'
            )
        );

        $assertSame(
            'missing_candidate',
            'completion field order',
            ['brand', 'category', 'model'],
            data_get(
                $missingMetadata,
                'assisted_review.completion_fields'
            )
        );

        /*
         * Scenario 3:
         * decisioni esplicite dell'utente devono essere preservate.
         */
        $reviewedCandidate = new ProductIdentificationCandidate([
            'metadata' => [
                'assisted_review' => [
                    'ui_note' => 'preserve namespace extension',
                    'fields' => [
                        'brand' => [
                            'state' => 'confirmed',
                            'required' => false,
                            'current' => [
                                'value' => 'Brand confermato',
                                'ref' => null,
                                'origin' => 'user',
                                'source' => 'manual_review',
                                'method' => 'confirmed_by_user',
                                'confidence' => 100,
                            ],
                            'suggestion' => null,
                            'review_note' => 'Confermato manualmente',
                        ],
                        'category' => [
                            'state' => 'declined',
                            'required' => false,
                            'current' => null,
                            'suggestion' => null,
                        ],
                    ],
                ],
            ],
        ]);

        $reviewedMetadata = $builder->mergeIntoMetadata(
            $reviewedCandidate
        );

        $assertSame(
            'protected_user_states',
            'confirmed brand preserved',
            'confirmed',
            data_get(
                $reviewedMetadata,
                'assisted_review.fields.brand.state'
            )
        );

        $assertSame(
            'protected_user_states',
            'declined category preserved',
            'declined',
            data_get(
                $reviewedMetadata,
                'assisted_review.fields.category.state'
            )
        );

        $assertSame(
            'protected_user_states',
            'review note preserved',
            'Confermato manualmente',
            data_get(
                $reviewedMetadata,
                'assisted_review.fields.brand.review_note'
            )
        );

        $assertSame(
            'protected_user_states',
            'namespace extension preserved',
            'preserve namespace extension',
            data_get(
                $reviewedMetadata,
                'assisted_review.ui_note'
            )
        );

        $assertSame(
            'protected_user_states',
            'only model requires completion',
            ['model'],
            data_get(
                $reviewedMetadata,
                'assisted_review.completion_fields'
            )
        );

        /*
         * Scenario 4:
         * lo stesso input deve produrre lo stesso output.
         */
        $idempotentCandidate = clone $completeCandidate;
        $idempotentCandidate->metadata = $completeMetadata;

        $secondBuild = $builder->mergeIntoMetadata(
            $idempotentCandidate
        );

        $assertSame(
            'idempotence',
            'second build equals first build',
            $completeMetadata,
            $secondBuild
        );

        $this->table(
            ['Scenario', 'Assertion', 'Status'],
            $rows
        );

        if ($failures !== []) {
            $this->newLine();
            $this->error('Assisted Review contract checks failed.');

            foreach ($failures as $failure) {
                $this->newLine();

                $this->line(
                    $failure['scenario']
                    . ' / '
                    . $failure['assertion']
                );

                $this->line(
                    'Expected: '
                    . $this->renderValue($failure['expected'])
                );

                $this->line(
                    'Actual:   '
                    . $this->renderValue($failure['actual'])
                );
            }

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Assisted Review contract checks passed.');

        return self::SUCCESS;
    }

    /**
     * Converte un valore in una rappresentazione leggibile nel terminale.
     */
    private function renderValue(mixed $value): string
    {
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