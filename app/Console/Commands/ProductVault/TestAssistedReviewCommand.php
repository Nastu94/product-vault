<?php

namespace App\Console\Commands\ProductVault;

use App\Models\Brand;
use App\Models\Category;
use App\Models\ProductIdentificationCandidate;
use App\Services\Documents\AssistedReview\AssistedReviewMetadataBuilder;
use App\Services\Documents\AssistedReview\AssistedReviewPresenter;
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
        AssistedReviewMetadataBuilder $builder,
        AssistedReviewPresenter $presenter
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
            'current category suppresses suggestion',
            null,
            data_get(
                $completeMetadata,
                'assisted_review.fields.category.suggestion'
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
                    'suggested_category' => 'console',
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
        * Un modello mancante può essere suggerito da un token alfanumerico.
        */
        $missingModelCandidate = new ProductIdentificationCandidate([
            'name' => 'SSD Kingston NV3 1TB',
            'metadata' => [],
        ]);

        $missingModelMetadata = $builder->mergeIntoMetadata(
            $missingModelCandidate
        );

        $assertSame(
            'model_missing_alphanumeric',
            'model state',
            'suggested',
            data_get(
                $missingModelMetadata,
                'assisted_review.fields.model.state'
            )
        );

        $assertSame(
            'model_missing_alphanumeric',
            'model value',
            'NV3',
            data_get(
                $missingModelMetadata,
                'assisted_review.fields.model.suggestion.value'
            )
        );

        $assertSame(
            'model_missing_alphanumeric',
            'model source',
            'name_structure',
            data_get(
                $missingModelMetadata,
                'assisted_review.fields.model.suggestion.source'
            )
        );

        /*
        * Una sequenza commerciale può essere proposta conservando più token.
        */
        $mouseModelCandidate = new ProductIdentificationCandidate([
            'name' => 'Mouse Logitech MX Master 3S',
            'metadata' => [],
        ]);

        $mouseModelMetadata = $builder->mergeIntoMetadata(
            $mouseModelCandidate
        );

        $assertSame(
            'model_multi_token',
            'model value',
            'MX Master 3S',
            data_get(
                $mouseModelMetadata,
                'assisted_review.fields.model.suggestion.value'
            )
        );

        /*
        * Un placeholder corrente non deve essere considerato un modello valido.
        */
        $lenovoBrand = new Brand([
            'name' => 'Lenovo',
            'normalized_name' => 'lenovo',
        ]);
        $lenovoBrand->id = 13;

        $placeholderModelCandidate = new ProductIdentificationCandidate([
            'brand_id' => 13,
            'name' => 'Notebook Lenovo ThinkPad X1 Carbon Gen 11',
            'model' => 'UNIT',
            'metadata' => [],
        ]);

        $placeholderModelCandidate->setRelation(
            'brand',
            $lenovoBrand
        );

        $placeholderModelMetadata = $builder->mergeIntoMetadata(
            $placeholderModelCandidate
        );

        $assertSame(
            'model_placeholder_recovery',
            'model state',
            'suggested',
            data_get(
                $placeholderModelMetadata,
                'assisted_review.fields.model.state'
            )
        );

        $assertSame(
            'model_placeholder_recovery',
            'current placeholder preserved',
            'UNIT',
            data_get(
                $placeholderModelMetadata,
                'assisted_review.fields.model.current.value'
            )
        );

        $assertSame(
            'model_placeholder_recovery',
            'model value',
            'ThinkPad X1 Carbon Gen 11',
            data_get(
                $placeholderModelMetadata,
                'assisted_review.fields.model.suggestion.value'
            )
        );

        $assertSame(
            'model_placeholder_recovery',
            'model issue',
            ['generic_current_model'],
            data_get(
                $placeholderModelMetadata,
                'assisted_review.fields.model.issues'
            )
        );

        $assertSame(
            'model_placeholder_recovery',
            'candidate model not modified',
            'UNIT',
            $placeholderModelCandidate->model
        );

        /*
        * Una sequenza modello distribuita su più token viene preservata.
        */
        $spacedModelCandidate = new ProductIdentificationCandidate([
            'name' => 'Sony WH 1000 XM5 cuffie wireless nero',
            'model' => 'UNIT',
            'metadata' => [],
        ]);

        $spacedModelMetadata = $builder->mergeIntoMetadata(
            $spacedModelCandidate
        );

        $assertSame(
            'model_spaced_code',
            'model value',
            'WH 1000 XM5',
            data_get(
                $spacedModelMetadata,
                'assisted_review.fields.model.suggestion.value'
            )
        );

        /*
        * Una specifica tecnica non deve diventare modello.
        */
        $technicalModelCandidate = new ProductIdentificationCandidate([
            'name' => 'Lampada Smart LuxHome E27 WiFi',
            'metadata' => [],
        ]);

        $technicalModelMetadata = $builder->mergeIntoMetadata(
            $technicalModelCandidate
        );

        $assertSame(
            'model_technical_spec_guard',
            'model remains missing',
            'missing',
            data_get(
                $technicalModelMetadata,
                'assisted_review.fields.model.state'
            )
        );

        /*
        * Una classe Wi-Fi come AX3000 non è necessariamente un modello preciso.
        * Il valore corrente viene conservato, ma richiede revisione manuale.
        */
        $wifiClassModelCandidate = new ProductIdentificationCandidate([
            'name' => 'Router NetWave AX3000 WiFi 6',
            'model' => 'AX3000',
            'metadata' => [
                'product_understanding' => [
                    'model_candidate' => 'AX3000',
                ],
            ],
        ]);

        $wifiClassModelMetadata = $builder->mergeIntoMetadata(
            $wifiClassModelCandidate
        );

        $assertSame(
            'model_wifi_class_guard',
            'model state',
            'missing',
            data_get(
                $wifiClassModelMetadata,
                'assisted_review.fields.model.state'
            )
        );

        $assertSame(
            'model_wifi_class_guard',
            'current value preserved',
            'AX3000',
            data_get(
                $wifiClassModelMetadata,
                'assisted_review.fields.model.current.value'
            )
        );

        $assertSame(
            'model_wifi_class_guard',
            'technical issue present',
            true,
            in_array(
                'technical_specification_used_as_model',
                data_get(
                    $wifiClassModelMetadata,
                    'assisted_review.fields.model.issues',
                    []
                ),
                true
            )
        );

        $assertSame(
            'model_wifi_class_guard',
            'no automatic replacement',
            null,
            data_get(
                $wifiClassModelMetadata,
                'assisted_review.fields.model.suggestion'
            )
        );

        /*
        * HDMI corrente viene conservato come evidenza ma non accettato.
        */
        $hdmiModelCandidate = new ProductIdentificationCandidate([
            'name' => 'Docking Station USB-C Dual HDMI 4K',
            'model' => 'HDMI',
            'metadata' => [
                'product_understanding' => [
                    'model_candidate' => 'HDMI',
                ],
            ],
        ]);

        $hdmiModelMetadata = $builder->mergeIntoMetadata(
            $hdmiModelCandidate
        );

        $assertSame(
            'model_hdmi_guard',
            'model state',
            'missing',
            data_get(
                $hdmiModelMetadata,
                'assisted_review.fields.model.state'
            )
        );

        $assertSame(
            'model_hdmi_guard',
            'current value preserved',
            'HDMI',
            data_get(
                $hdmiModelMetadata,
                'assisted_review.fields.model.current.value'
            )
        );

        $assertSame(
            'model_hdmi_guard',
            'technical issue present',
            true,
            in_array(
                'technical_specification_used_as_model',
                data_get(
                    $hdmiModelMetadata,
                    'assisted_review.fields.model.issues',
                    []
                ),
                true
            )
        );

        /*
        * Il brand non deve essere riproposto come modello.
        */
        $brandAsModelCandidate = new ProductIdentificationCandidate([
            'name' => 'SSD FLASHCORE 1TB NVME',
            'model' => 'FLASHCORE',
            'metadata' => [],
        ]);

        $brandAsModelMetadata = $builder->mergeIntoMetadata(
            $brandAsModelCandidate
        );

        $assertSame(
            'model_brand_guard',
            'model state',
            'missing',
            data_get(
                $brandAsModelMetadata,
                'assisted_review.fields.model.state'
            )
        );

        $assertSame(
            'model_brand_guard',
            'brand issue present',
            true,
            in_array(
                'brand_used_as_model',
                data_get(
                    $brandAsModelMetadata,
                    'assisted_review.fields.model.issues',
                    []
                ),
                true
            )
        );

        /*
        * Un model candidate valido dell'analyzer può essere suggerito.
        */
        $analysisModelCandidate = new ProductIdentificationCandidate([
            'name' => 'Cuffie Sony serie professionale',
            'metadata' => [
                'product_understanding' => [
                    'model_candidate' => 'WH-1000XM5',
                ],
            ],
        ]);

        $analysisModelMetadata = $builder->mergeIntoMetadata(
            $analysisModelCandidate
        );

        $assertSame(
            'model_analysis_suggestion',
            'model value',
            'WH-1000XM5',
            data_get(
                $analysisModelMetadata,
                'assisted_review.fields.model.suggestion.value'
            )
        );

        $assertSame(
            'model_analysis_suggestion',
            'model source',
            'product_line_analysis',
            data_get(
                $analysisModelMetadata,
                'assisted_review.fields.model.suggestion.source'
            )
        );

        /*
        * Il suggerimento modello deve restare idempotente.
        */
        $idempotentModelCandidate = clone $placeholderModelCandidate;
        $idempotentModelCandidate->metadata = $placeholderModelMetadata;

        $secondModelBuild = $builder->mergeIntoMetadata(
            $idempotentModelCandidate
        );

        $assertSame(
            'model_suggestion_idempotence',
            'second build equals first build',
            $placeholderModelMetadata,
            $secondModelBuild
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
        * Categoria proveniente da uno snapshot della knowledge iniziale.
        */
        $knowledgeCategoryCandidate = new ProductIdentificationCandidate([
            'metadata' => [
                'product_understanding_category' => [
                    'matched' => true,
                    'match_type' => 'initial_line_pattern_summary',
                    'category_id' => 7,
                    'category_name' => 'Computer',
                    'category_slug' => 'computers',
                ],
            ],
        ]);

        $knowledgeCategoryMetadata = $builder->mergeIntoMetadata(
            $knowledgeCategoryCandidate
        );

        $assertSame(
            'category_knowledge_suggestion',
            'category state',
            'suggested',
            data_get(
                $knowledgeCategoryMetadata,
                'assisted_review.fields.category.state'
            )
        );

        $assertSame(
            'category_knowledge_suggestion',
            'category value',
            'Computer',
            data_get(
                $knowledgeCategoryMetadata,
                'assisted_review.fields.category.suggestion.value'
            )
        );

        $assertSame(
            'category_knowledge_suggestion',
            'category reference key',
            'computers',
            data_get(
                $knowledgeCategoryMetadata,
                'assisted_review.fields.category.suggestion.ref.key'
            )
        );

        $assertSame(
            'category_knowledge_suggestion',
            'category id not modified',
            null,
            $knowledgeCategoryCandidate->category_id
        );

        /*
        * Mapping diretto di una macro-categoria dal tipo prodotto.
        */
        $dehumidifierCandidate = new ProductIdentificationCandidate([
            'name' => 'Deumidificatore AriaDry 20L',
            'metadata' => [],
        ]);

        $dehumidifierMetadata = $builder->mergeIntoMetadata(
            $dehumidifierCandidate
        );

        $assertSame(
            'category_name_dehumidifier',
            'category value',
            'Climatizzazione',
            data_get(
                $dehumidifierMetadata,
                'assisted_review.fields.category.suggestion.value'
            )
        );

        $assertSame(
            'category_name_dehumidifier',
            'category reference key',
            'climate-control',
            data_get(
                $dehumidifierMetadata,
                'assisted_review.fields.category.suggestion.ref.key'
            )
        );

        $assertSame(
            'category_name_dehumidifier',
            'category source',
            'product_type_mapping',
            data_get(
                $dehumidifierMetadata,
                'assisted_review.fields.category.suggestion.source'
            )
        );

        /*
        * Una categoria errata dell'analyzer non deve prevalere sul tipo reale.
        */
        $smartTvCandidate = new ProductIdentificationCandidate([
            'name' => 'Smart TV ViewPlus 55 4K WiFi HDMI',
            'metadata' => [
                'product_understanding' => [
                    'suggested_category' => 'cable',
                ],
            ],
        ]);

        $smartTvMetadata = $builder->mergeIntoMetadata(
            $smartTvCandidate
        );

        $assertSame(
            'category_smart_tv_analysis_guard',
            'category reference key',
            'tv-audio',
            data_get(
                $smartTvMetadata,
                'assisted_review.fields.category.suggestion.ref.key'
            )
        );

        $assertSame(
            'category_smart_tv_analysis_guard',
            'category source',
            'product_type_mapping',
            data_get(
                $smartTvMetadata,
                'assisted_review.fields.category.suggestion.source'
            )
        );

        /*
        * Il simbolo "+" può separare specifiche e non indica necessariamente
        * la presenza di più prodotti.
        */
        $powerBankPortsCandidate = new ProductIdentificationCandidate([
            'name' => 'PowerBank VoltPro 20000mAh USB-C PD 65W 2 porte USB-C + 1 porta USB-A',
            'metadata' => [
                'product_understanding' => [
                    'suggested_category' => 'cable',
                ],
            ],
        ]);

        $powerBankPortsMetadata = $builder->mergeIntoMetadata(
            $powerBankPortsCandidate
        );

        $assertSame(
            'category_plus_in_specs',
            'category reference key',
            'electronics',
            data_get(
                $powerBankPortsMetadata,
                'assisted_review.fields.category.suggestion.ref.key'
            )
        );

        $assertSame(
            'category_plus_in_specs',
            'category source',
            'product_type_mapping',
            data_get(
                $powerBankPortsMetadata,
                'assisted_review.fields.category.suggestion.source'
            )
        );

        $assertSame(
            'category_plus_in_specs',
            'category id not modified',
            null,
            $powerBankPortsCandidate->category_id
        );

        /*
        * L'analyzer viene usato solo se il nome corrobora il tipo semantico.
        */
        $analysisCategoryCandidate = new ProductIdentificationCandidate([
            'name' => 'Access Point NetWave AX3000',
            'metadata' => [
                'product_understanding' => [
                    'suggested_category' => 'network_device',
                ],
            ],
        ]);

        $analysisCategoryMetadata = $builder->mergeIntoMetadata(
            $analysisCategoryCandidate
        );

        $assertSame(
            'category_analysis_corroborated',
            'category reference key',
            'computers',
            data_get(
                $analysisCategoryMetadata,
                'assisted_review.fields.category.suggestion.ref.key'
            )
        );

        $assertSame(
            'category_analysis_corroborated',
            'category source',
            'product_line_analysis',
            data_get(
                $analysisCategoryMetadata,
                'assisted_review.fields.category.suggestion.source'
            )
        );

        /*
        * Ricambi e consumabili restano da classificare manualmente.
        */
        $replacementCandidate = new ProductIdentificationCandidate([
            'name' => 'Filtro HEPA CleanBot S8 confezione 2 pezzi',
            'metadata' => [],
        ]);

        $replacementMetadata = $builder->mergeIntoMetadata(
            $replacementCandidate
        );

        $assertSame(
            'category_replacement_guard',
            'category remains missing',
            'missing',
            data_get(
                $replacementMetadata,
                'assisted_review.fields.category.state'
            )
        );

        /*
        * Un bundle multi-prodotto non riceve una categoria arbitraria.
        */
        $categoryBundleCandidate = new ProductIdentificationCandidate([
            'name' => 'Bundle Camera VisionCam + microfono CreatorMic',
            'metadata' => [],
        ]);

        $categoryBundleMetadata = $builder->mergeIntoMetadata(
            $categoryBundleCandidate
        );

        $assertSame(
            'category_bundle_guard',
            'category remains missing',
            'missing',
            data_get(
                $categoryBundleMetadata,
                'assisted_review.fields.category.state'
            )
        );

        /*
        * Anche il suggerimento categoria deve essere idempotente.
        */
        $idempotentCategoryCandidate = clone $dehumidifierCandidate;
        $idempotentCategoryCandidate->metadata = $dehumidifierMetadata;

        $secondCategoryBuild = $builder->mergeIntoMetadata(
            $idempotentCategoryCandidate
        );

        $assertSame(
            'category_suggestion_idempotence',
            'second build equals first build',
            $dehumidifierMetadata,
            $secondCategoryBuild
        );

        /*
         * Presenter 1:
         * un candidato completo viene tradotto in dati leggibili per la UI.
         */
        $completePresentationCandidate = clone $completeCandidate;
        $completePresentationCandidate->metadata = $completeMetadata;

        $attributesBeforePresentation = $completePresentationCandidate
            ->getAttributes();

        $completePresentation = $presenter->present(
            $completePresentationCandidate
        );

        $assertSame(
            'presenter_complete_candidate',
            'contract available',
            true,
            data_get($completePresentation, 'available')
        );

        $assertSame(
            'presenter_complete_candidate',
            'contract version',
            'v1',
            data_get($completePresentation, 'version')
        );

        $assertSame(
            'presenter_complete_candidate',
            'brand state label',
            'Estratto dal documento',
            data_get(
                $completePresentation,
                'fields.brand.state_label'
            )
        );

        $assertSame(
            'presenter_complete_candidate',
            'model display value',
            'XS1000',
            data_get(
                $completePresentation,
                'fields.model.display_value'
            )
        );

        $assertSame(
            'presenter_complete_candidate',
            'completion not required',
            false,
            data_get(
                $completePresentation,
                'needs_user_completion'
            )
        );

        $assertSame(
            'presenter_complete_candidate',
            'presenter is read only',
            $attributesBeforePresentation,
            $completePresentationCandidate->getAttributes()
        );

        /*
         * Presenter 2:
         * suggerimenti e campi mancanti richiedono una decisione esplicita.
         */
        $actionCandidate = new ProductIdentificationCandidate([
            'metadata' => [
                'assisted_review' => [
                    'version' => 'v1',
                    'builder' => 'assisted_review_metadata_builder_v1',
                    'needs_user_completion' => true,
                    'completion_fields' => [
                        'brand',
                        'model',
                    ],
                    'fields' => [
                        'brand' => [
                            'state' => 'suggested',
                            'required' => false,
                            'current' => null,
                            'suggestion' => [
                                'value' => 'Kingston',
                                'source' => 'name_structure',
                                'method' => 'name_structure_title_before_model',
                                'confidence' => 78,
                            ],
                        ],
                        'category' => [
                            'state' => 'present',
                            'required' => false,
                            'current' => [
                                'value' => 'Computer',
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

        $actionAttributesBefore = $actionCandidate->getAttributes();

        $actionPresentation = $presenter->present(
            $actionCandidate
        );

        $assertSame(
            'presenter_action_candidate',
            'brand state',
            'suggested',
            data_get(
                $actionPresentation,
                'fields.brand.state'
            )
        );

        $assertSame(
            'presenter_action_candidate',
            'brand state label',
            'Suggerito da Product Vault',
            data_get(
                $actionPresentation,
                'fields.brand.state_label'
            )
        );

        $assertSame(
            'presenter_action_candidate',
            'brand display value',
            'Kingston',
            data_get(
                $actionPresentation,
                'fields.brand.display_value'
            )
        );

        $assertSame(
            'presenter_action_candidate',
            'brand needs action',
            true,
            data_get(
                $actionPresentation,
                'fields.brand.needs_action'
            )
        );

        $assertSame(
            'presenter_action_candidate',
            'brand suggestion can be accepted',
            true,
            data_get(
                $actionPresentation,
                'fields.brand.can_accept_suggestion'
            )
        );

        $assertSame(
            'presenter_action_candidate',
            'category state label',
            'Estratto dal documento',
            data_get(
                $actionPresentation,
                'fields.category.state_label'
            )
        );

        $assertSame(
            'presenter_action_candidate',
            'model state label',
            'Da completare',
            data_get(
                $actionPresentation,
                'fields.model.state_label'
            )
        );

        $assertSame(
            'presenter_action_candidate',
            'unreliable model current preserved',
            'AX3000',
            data_get(
                $actionPresentation,
                'fields.model.current_value'
            )
        );

        $assertSame(
            'presenter_action_candidate',
            'unreliable model not used as display value',
            null,
            data_get(
                $actionPresentation,
                'fields.model.display_value'
            )
        );

        $assertSame(
            'presenter_action_candidate',
            'unreliable model flagged',
            true,
            data_get(
                $actionPresentation,
                'fields.model.has_unreliable_current'
            )
        );

        $assertSame(
            'presenter_action_candidate',
            'missing model needs action',
            true,
            data_get(
                $actionPresentation,
                'fields.model.needs_action'
            )
        );

        $assertSame(
            'presenter_action_candidate',
            'model issues',
            ['technical_specification_used_as_model'],
            data_get(
                $actionPresentation,
                'fields.model.issues'
            )
        );

        $assertSame(
            'presenter_action_candidate',
            'completion fields',
            ['brand', 'model'],
            data_get(
                $actionPresentation,
                'completion_fields'
            )
        );

        $assertSame(
            'presenter_action_candidate',
            'completion count',
            2,
            data_get(
                $actionPresentation,
                'completion_count'
            )
        );

        $assertSame(
            'presenter_action_candidate',
            'presenter does not mutate candidate',
            $actionAttributesBefore,
            $actionCandidate->getAttributes()
        );

        /*
         * Presenter 3:
         * le decisioni esplicite dell'utente non tornano azionabili.
         */
        $protectedCandidate = new ProductIdentificationCandidate([
            'metadata' => [
                'assisted_review' => [
                    'version' => 'v1',
                    'fields' => [
                        'brand' => [
                            'state' => 'confirmed',
                            'required' => false,
                            'current' => [
                                'value' => 'Kingston',
                            ],
                            'suggestion' => null,
                        ],
                        'category' => [
                            'state' => 'modified',
                            'required' => false,
                            'current' => [
                                'value' => 'Archiviazione',
                            ],
                            'suggestion' => null,
                        ],
                        'model' => [
                            'state' => 'declined',
                            'required' => false,
                            'current' => null,
                            'suggestion' => null,
                        ],
                    ],
                ],
            ],
        ]);

        $protectedPresentation = $presenter->present(
            $protectedCandidate
        );

        $assertSame(
            'presenter_protected_states',
            'confirmed label',
            'Confermato da te',
            data_get(
                $protectedPresentation,
                'fields.brand.state_label'
            )
        );

        $assertSame(
            'presenter_protected_states',
            'modified label',
            'Modificato da te',
            data_get(
                $protectedPresentation,
                'fields.category.state_label'
            )
        );

        $assertSame(
            'presenter_protected_states',
            'declined label',
            'Non disponibile',
            data_get(
                $protectedPresentation,
                'fields.model.state_label'
            )
        );

        $assertSame(
            'presenter_protected_states',
            'protected fields need no action',
            false,
            data_get(
                $protectedPresentation,
                'needs_user_completion'
            )
        );

        /*
         * Presenter 4:
         * il fallback legge soltanto valori già presenti sul candidato.
         */
        $fallbackBrand = new Brand([
            'name' => 'Kingston',
            'normalized_name' => 'kingston',
        ]);
        $fallbackBrand->id = 12;

        $fallbackCategory = new Category([
            'name' => 'Computer',
            'slug' => 'computers',
        ]);
        $fallbackCategory->id = 7;

        $fallbackCandidate = new ProductIdentificationCandidate([
            'brand_id' => 12,
            'category_id' => 7,
            'model' => 'NV3',
            'metadata' => [],
        ]);

        $fallbackCandidate->setRelation(
            'brand',
            $fallbackBrand
        );

        $fallbackCandidate->setRelation(
            'category',
            $fallbackCategory
        );

        $fallbackPresentation = $presenter->present(
            $fallbackCandidate
        );

        $assertSame(
            'presenter_metadata_fallback',
            'contract unavailable',
            false,
            data_get($fallbackPresentation, 'available')
        );

        $assertSame(
            'presenter_metadata_fallback',
            'brand fallback value',
            'Kingston',
            data_get(
                $fallbackPresentation,
                'fields.brand.display_value'
            )
        );

        $assertSame(
            'presenter_metadata_fallback',
            'category fallback value',
            'Computer',
            data_get(
                $fallbackPresentation,
                'fields.category.display_value'
            )
        );

        $assertSame(
            'presenter_metadata_fallback',
            'model fallback value',
            'NV3',
            data_get(
                $fallbackPresentation,
                'fields.model.display_value'
            )
        );

        $assertSame(
            'presenter_metadata_fallback',
            'fallback completion not required',
            false,
            data_get(
                $fallbackPresentation,
                'needs_user_completion'
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