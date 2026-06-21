<?php

namespace App\Services\Documents\AssistedReview;

use App\Models\Brand;
use App\Models\Category;
use App\Models\ProductIdentificationCandidate;

class AssistedReviewMetadataBuilder
{
    /**
     * Versione del contratto metadata.
     */
    private const VERSION = 'v1';

    /**
     * Identificativo del builder che ha prodotto il payload.
     */
    private const BUILDER = 'assisted_review_metadata_builder_v1';

    /**
     * Stati che rappresentano una decisione esplicita dell'utente.
     *
     * Questi stati non devono essere sovrascritti durante una successiva
     * rigenerazione automatica del metadata assistito.
     *
     * @var array<int, string>
     */
    private const USER_PROTECTED_STATES = [
        'confirmed',
        'modified',
        'declined',
    ];

    /**
     * Costruisce il namespace assisted_review del candidato.
     *
     * In questa prima versione vengono rappresentati soltanto:
     * - valori già presenti sul candidato;
     * - valori mancanti;
     * - eventuali decisioni utente già registrate.
     *
     * Non vengono ancora generate proposte automatiche.
     *
     * @return array<string, mixed>
     */
    public function build(
        ProductIdentificationCandidate $candidate
    ): array {
        $existingNamespace = $this->existingNamespace($candidate);
        $existingFields = is_array($existingNamespace['fields'] ?? null)
            ? $existingNamespace['fields']
            : [];

        /*
         * Manteniamo eventuali campi futuri non ancora conosciuti da questa
         * versione del builder, aggiornando solamente quelli gestiti.
         */
        $fields = $existingFields;

        $fields['brand'] = $this->buildField(
            current: $this->currentBrand($candidate),
            existingField: $this->existingField($existingFields, 'brand')
        );

        $fields['category'] = $this->buildField(
            current: $this->currentCategory($candidate),
            existingField: $this->existingField($existingFields, 'category')
        );

        $fields['model'] = $this->buildField(
            current: $this->currentModel($candidate),
            existingField: $this->existingField($existingFields, 'model')
        );

        $completionFields = [];

        foreach (['brand', 'category', 'model'] as $fieldName) {
            $state = $fields[$fieldName]['state'] ?? null;

            if (in_array($state, ['missing', 'suggested'], true)) {
                $completionFields[] = $fieldName;
            }
        }

        /*
         * array_replace preserva eventuali chiavi aggiuntive già presenti nel
         * namespace, mentre il contratto corrente mantiene autorità sulle
         * proprie chiavi strutturali.
         */
        return array_replace($existingNamespace, [
            'version' => self::VERSION,
            'builder' => self::BUILDER,
            'needs_user_completion' => $completionFields !== [],
            'completion_fields' => $completionFields,
            'fields' => $fields,
        ]);
    }

    /**
     * Restituisce l'intero metadata del candidato con il namespace
     * assisted_review aggiornato.
     *
     * Il metodo non modifica il model e non esegue alcun salvataggio.
     *
     * @return array<string, mixed>
     */
    public function mergeIntoMetadata(
        ProductIdentificationCandidate $candidate
    ): array {
        $metadata = is_array($candidate->metadata)
            ? $candidate->metadata
            : [];

        $metadata['assisted_review'] = $this->build($candidate);

        return $metadata;
    }

    /**
     * Costruisce lo stato di un singolo campo.
     *
     * @param  array<string, mixed>|null  $current
     * @param  array<string, mixed>  $existingField
     * @return array<string, mixed>
     */
    private function buildField(
        ?array $current,
        array $existingField
    ): array {
        $existingState = $existingField['state'] ?? null;

        /*
         * Una decisione esplicita dell'utente ha precedenza su qualsiasi
         * successiva elaborazione automatica.
         */
        if (
            is_string($existingState)
            && in_array(
                $existingState,
                self::USER_PROTECTED_STATES,
                true
            )
        ) {
            return array_replace([
                'state' => $existingState,
                'required' => false,
                'current' => $current,
                'suggestion' => null,
            ], $existingField);
        }

        return [
            'state' => $current === null ? 'missing' : 'present',
            'required' => false,
            'current' => $current,
            'suggestion' => null,
        ];
    }

    /**
     * Rappresenta il brand già assegnato al candidato.
     *
     * @return array<string, mixed>|null
     */
    private function currentBrand(
        ProductIdentificationCandidate $candidate
    ): ?array {
        if ($candidate->brand_id === null) {
            return null;
        }

        $brand = $this->resolveBrand($candidate);

        if ($brand === null) {
            return null;
        }

        return [
            'value' => $brand->name,
            'ref' => [
                'type' => 'brand',
                'id' => $brand->getKey(),
                'key' => $this->nullableString(
                    $brand->normalized_name
                ),
            ],
            'origin' => 'existing',
            'source' => 'candidate_field',
            'method' => 'candidate_brand_id',
            'confidence' => null,
        ];
    }

    /**
     * Rappresenta la categoria già assegnata al candidato.
     *
     * @return array<string, mixed>|null
     */
    private function currentCategory(
        ProductIdentificationCandidate $candidate
    ): ?array {
        if ($candidate->category_id === null) {
            return null;
        }

        $category = $this->resolveCategory($candidate);

        if ($category === null) {
            return null;
        }

        return [
            'value' => $category->name,
            'ref' => [
                'type' => 'category',
                'id' => $category->getKey(),
                'key' => $this->nullableString($category->slug),
            ],
            'origin' => 'existing',
            'source' => 'candidate_field',
            'method' => 'candidate_category_id',
            'confidence' => null,
        ];
    }

    /**
     * Rappresenta il modello già presente sul candidato.
     *
     * @return array<string, mixed>|null
     */
    private function currentModel(
        ProductIdentificationCandidate $candidate
    ): ?array {
        $model = $this->nullableString($candidate->model);

        if ($model === null) {
            return null;
        }

        return [
            'value' => $model,
            'ref' => null,
            'origin' => 'existing',
            'source' => 'candidate_field',
            'method' => 'candidate_model',
            'confidence' => null,
        ];
    }

    /**
     * Recupera il brand evitando una query quando la relazione è già caricata.
     */
    private function resolveBrand(
        ProductIdentificationCandidate $candidate
    ): ?Brand {
        if ($candidate->relationLoaded('brand')) {
            $brand = $candidate->getRelation('brand');

            return $brand instanceof Brand ? $brand : null;
        }

        return Brand::query()->find($candidate->brand_id);
    }

    /**
     * Recupera la categoria evitando una query quando la relazione è già
     * caricata.
     */
    private function resolveCategory(
        ProductIdentificationCandidate $candidate
    ): ?Category {
        if ($candidate->relationLoaded('category')) {
            $category = $candidate->getRelation('category');

            return $category instanceof Category ? $category : null;
        }

        return Category::query()->find($candidate->category_id);
    }

    /**
     * Restituisce il namespace assisted_review già presente, se valido.
     *
     * @return array<string, mixed>
     */
    private function existingNamespace(
        ProductIdentificationCandidate $candidate
    ): array {
        $metadata = is_array($candidate->metadata)
            ? $candidate->metadata
            : [];

        return is_array($metadata['assisted_review'] ?? null)
            ? $metadata['assisted_review']
            : [];
    }

    /**
     * Recupera un singolo campo dal payload assisted_review esistente.
     *
     * @param  array<string, mixed>  $existingFields
     * @return array<string, mixed>
     */
    private function existingField(
        array $existingFields,
        string $fieldName
    ): array {
        return is_array($existingFields[$fieldName] ?? null)
            ? $existingFields[$fieldName]
            : [];
    }

    /**
     * Normalizza una stringa opzionale.
     */
    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }
}