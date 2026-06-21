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
                'product_understanding' => [
                    'brand_candidate' => 'Sony',
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
            'current brand suppresses suggestion',
            null,
            data_get(
                $completeMetadata,
                'assisted_review.fields.brand.suggestion'
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
        * uno snapshot forte della knowledge iniziale diventa suggerimento,
        * senza modificare brand_id.
        */
        $knowledgeBrandCandidate = new ProductIdentificationCandidate([
            'metadata' => [
                'product_understanding_brand' => [
                    'matched' => true,
                    'match_type' => 'initial_brand_alias',
                    'brand_id' => 44,
                    'brand_name' => 'Hewlett Packard',
                    'normalized_name' => 'hp',
                    'alias' => 'HEWLETT PACKARD',
                    'alias_normalized' => 'hewlett packard',
                    'alias_confidence_score' => 94,
                    'is_verified' => true,
                    'source' => 'initial_knowledge_pack_v1',
                ],
            ],
        ]);

        $knowledgeBrandMetadata = $builder->mergeIntoMetadata(
            $knowledgeBrandCandidate
        );

        $assertSame(
            'brand_knowledge_suggestion',
            'brand state',
            'suggested',
            data_get(
                $knowledgeBrandMetadata,
                'assisted_review.fields.brand.state'
            )
        );

        $assertSame(
            'brand_knowledge_suggestion',
            'brand value',
            'Hewlett Packard',
            data_get(
                $knowledgeBrandMetadata,
                'assisted_review.fields.brand.suggestion.value'
            )
        );

        $assertSame(
            'brand_knowledge_suggestion',
            'brand reference id',
            44,
            data_get(
                $knowledgeBrandMetadata,
                'assisted_review.fields.brand.suggestion.ref.id'
            )
        );

        $assertSame(
            'brand_knowledge_suggestion',
            'brand reference key',
            'hp',
            data_get(
                $knowledgeBrandMetadata,
                'assisted_review.fields.brand.suggestion.ref.key'
            )
        );

        $assertSame(
            'brand_knowledge_suggestion',
            'brand source',
            'initial_knowledge',
            data_get(
                $knowledgeBrandMetadata,
                'assisted_review.fields.brand.suggestion.source'
            )
        );

        $assertSame(
            'brand_knowledge_suggestion',
            'brand method',
            'initial_brand_alias',
            data_get(
                $knowledgeBrandMetadata,
                'assisted_review.fields.brand.suggestion.method'
            )
        );

        $assertSame(
            'brand_knowledge_suggestion',
            'brand confidence',
            94,
            data_get(
                $knowledgeBrandMetadata,
                'assisted_review.fields.brand.suggestion.confidence'
            )
        );

        $assertSame(
            'brand_knowledge_suggestion',
            'brand id not modified',
            null,
            $knowledgeBrandCandidate->brand_id
        );

        /*
        * Scenario 4:
        * il brand candidate dell'analizzatore è un fallback testuale debole.
        */
        $analysisBrandCandidate = new ProductIdentificationCandidate([
            'metadata' => [
                'product_understanding' => [
                    'brand_candidate' => 'Sony',
                ],
            ],
        ]);

        $analysisBrandMetadata = $builder->mergeIntoMetadata(
            $analysisBrandCandidate
        );

        $assertSame(
            'brand_analysis_suggestion',
            'brand state',
            'suggested',
            data_get(
                $analysisBrandMetadata,
                'assisted_review.fields.brand.state'
            )
        );

        $assertSame(
            'brand_analysis_suggestion',
            'brand value',
            'Sony',
            data_get(
                $analysisBrandMetadata,
                'assisted_review.fields.brand.suggestion.value'
            )
        );

        $assertSame(
            'brand_analysis_suggestion',
            'brand reference absent',
            null,
            data_get(
                $analysisBrandMetadata,
                'assisted_review.fields.brand.suggestion.ref'
            )
        );

        $assertSame(
            'brand_analysis_suggestion',
            'brand source',
            'product_line_analysis',
            data_get(
                $analysisBrandMetadata,
                'assisted_review.fields.brand.suggestion.source'
            )
        );

        $assertSame(
            'brand_analysis_suggestion',
            'brand confidence not invented',
            null,
            data_get(
                $analysisBrandMetadata,
                'assisted_review.fields.brand.suggestion.confidence'
            )
        );

        /*
        * Scenario 5:
        * un valore esclusivamente numerico non è un brand valido.
        */
        $invalidBrandCandidate = new ProductIdentificationCandidate([
            'metadata' => [
                'product_understanding' => [
                    'brand_candidate' => '123456',
                ],
            ],
        ]);

        $invalidBrandMetadata = $builder->mergeIntoMetadata(
            $invalidBrandCandidate
        );

        $assertSame(
            'invalid_brand_suggestion',
            'numeric brand remains missing',
            'missing',
            data_get(
                $invalidBrandMetadata,
                'assisted_review.fields.brand.state'
            )
        );

        /*
        * Lo stesso suggerimento deve restare stabile dopo una seconda build.
        */
        $idempotentBrandCandidate = clone $knowledgeBrandCandidate;
        $idempotentBrandCandidate->metadata = $knowledgeBrandMetadata;

        $secondBrandBuild = $builder->mergeIntoMetadata(
            $idempotentBrandCandidate
        );

        $assertSame(
            'brand_suggestion_idempotence',
            'second build equals first build',
            $knowledgeBrandMetadata,
            $secondBrandBuild
        );

        /*
        * Brand CamelCase dopo il tipo prodotto.
        */
        $pascalAfterTypeCandidate = new ProductIdentificationCandidate([
            'name' => 'Monitor ViewMax Creator XR27 4K',
            'metadata' => [],
        ]);

        $pascalAfterTypeMetadata = $builder->mergeIntoMetadata(
            $pascalAfterTypeCandidate
        );

        $assertSame(
            'brand_name_pascal_after_type',
            'brand value',
            'ViewMax',
            data_get(
                $pascalAfterTypeMetadata,
                'assisted_review.fields.brand.suggestion.value'
            )
        );

        $assertSame(
            'brand_name_pascal_after_type',
            'brand source',
            'name_structure',
            data_get(
                $pascalAfterTypeMetadata,
                'assisted_review.fields.brand.suggestion.source'
            )
        );

        $assertSame(
            'brand_name_pascal_after_type',
            'brand method',
            'name_structure_pascal_token',
            data_get(
                $pascalAfterTypeMetadata,
                'assisted_review.fields.brand.suggestion.method'
            )
        );

        /*
        * Brand CamelCase prima della descrizione prodotto.
        */
        $pascalBeforeTypeCandidate = new ProductIdentificationCandidate([
            'name' => 'TerraVault Home Duo NAS 8 TB',
            'metadata' => [],
        ]);

        $pascalBeforeTypeMetadata = $builder->mergeIntoMetadata(
            $pascalBeforeTypeCandidate
        );

        $assertSame(
            'brand_name_pascal_before_type',
            'brand value',
            'TerraVault',
            data_get(
                $pascalBeforeTypeMetadata,
                'assisted_review.fields.brand.suggestion.value'
            )
        );

        /*
        * Brand completamente maiuscolo dopo termini prodotto.
        */
        $uppercaseBrandCandidate = new ProductIdentificationCandidate([
            'name' => 'ROUTER AERONET AX1800',
            'metadata' => [],
        ]);

        $uppercaseBrandMetadata = $builder->mergeIntoMetadata(
            $uppercaseBrandCandidate
        );

        $assertSame(
            'brand_name_uppercase',
            'brand value',
            'AERONET',
            data_get(
                $uppercaseBrandMetadata,
                'assisted_review.fields.brand.suggestion.value'
            )
        );

        $assertSame(
            'brand_name_uppercase',
            'brand method',
            'name_structure_uppercase_token',
            data_get(
                $uppercaseBrandMetadata,
                'assisted_review.fields.brand.suggestion.method'
            )
        );

        /*
        * Parola Title Case semplice accettata soltanto tra tipo e modello.
        */
        $titleBeforeModelCandidate = new ProductIdentificationCandidate([
            'name' => 'SSD Kingston NV3 1TB',
            'metadata' => [],
        ]);

        $titleBeforeModelMetadata = $builder->mergeIntoMetadata(
            $titleBeforeModelCandidate
        );

        $assertSame(
            'brand_name_title_before_model',
            'brand value',
            'Kingston',
            data_get(
                $titleBeforeModelMetadata,
                'assisted_review.fields.brand.suggestion.value'
            )
        );

        $assertSame(
            'brand_name_title_before_model',
            'brand method',
            'name_structure_title_before_model',
            data_get(
                $titleBeforeModelMetadata,
                'assisted_review.fields.brand.suggestion.method'
            )
        );

        /*
        * Una riga composta solo da tipo prodotto e specifiche resta senza brand.
        */
        $genericAccessoryCandidate = new ProductIdentificationCandidate([
            'name' => 'Docking Station USB-C Dual HDMI 4K',
            'metadata' => [],
        ]);

        $genericAccessoryMetadata = $builder->mergeIntoMetadata(
            $genericAccessoryCandidate
        );

        $assertSame(
            'brand_name_generic_accessory',
            'brand remains missing',
            'missing',
            data_get(
                $genericAccessoryMetadata,
                'assisted_review.fields.brand.state'
            )
        );

        /*
        * Due parole separate e ambigue non vengono unite artificialmente.
        */
        $splitBrandCandidate = new ProductIdentificationCandidate([
            'name' => 'Monitor View Max Creator XR 27 UHD',
            'metadata' => [],
        ]);

        $splitBrandMetadata = $builder->mergeIntoMetadata(
            $splitBrandCandidate
        );

        $assertSame(
            'brand_name_split_ambiguous',
            'brand remains missing',
            'missing',
            data_get(
                $splitBrandMetadata,
                'assisted_review.fields.brand.state'
            )
        );

        /*
        * Un bundle con più token plausibili non sceglie arbitrariamente un brand.
        */
        $ambiguousBundleCandidate = new ProductIdentificationCandidate([
            'name' => 'Bundle Creator Kit Camera VisionCam C4 + microfono CreatorMic Pro',
            'metadata' => [],
        ]);

        $ambiguousBundleMetadata = $builder->mergeIntoMetadata(
            $ambiguousBundleCandidate
        );

        $assertSame(
            'brand_name_ambiguous_bundle',
            'brand remains missing',
            'missing',
            data_get(
                $ambiguousBundleMetadata,
                'assisted_review.fields.brand.state'
            )
        );

        $assertSame(
            'brand_name_pascal_after_type',
            'brand id not modified',
            null,
            $pascalAfterTypeCandidate->brand_id
        );

        /*
         * Scenario 6:
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
         * Scenario 7:
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