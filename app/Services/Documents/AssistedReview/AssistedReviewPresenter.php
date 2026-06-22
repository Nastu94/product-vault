<?php

namespace App\Services\Documents\AssistedReview;

use App\Models\ProductIdentificationCandidate;

final class AssistedReviewPresenter
{
    /**
     * Etichette leggibili dei campi mostrati durante la revisione.
     *
     * @var array<string, string>
     */
    private const FIELD_LABELS = [
        'brand' => 'Brand',
        'category' => 'Categoria',
        'model' => 'Modello',
    ];

    /**
     * Etichette utente associate agli stati del contratto.
     *
     * @var array<string, string>
     */
    private const STATE_LABELS = [
        'present' => 'Dato disponibile',
        'suggested' => 'Suggerito da Product Vault',
        'missing' => 'Da completare',
        'confirmed' => 'Confermato da te',
        'modified' => 'Modificato da te',
        'declined' => 'Non disponibile',
    ];

    /**
     * Stati che richiedono una decisione dell'utente.
     *
     * @var array<int, string>
     */
    private const ACTION_STATES = [
        'suggested',
        'missing',
    ];

    /**
     * Trasforma il contratto Assisted Review in dati pronti per la UI.
     *
     * Il metodo è completamente read-only:
     * - non modifica il candidato;
     * - non salva metadata;
     * - non applica suggerimenti;
     * - non crea brand, categorie o prodotti.
     *
     * @return array<string, mixed>
     */
    public function present(
        ProductIdentificationCandidate $candidate
    ): array {
        $namespace = $this->assistedReviewNamespace($candidate);

        $contractAvailable = (
            ($namespace['version'] ?? null) === 'v1'
            && is_array($namespace['fields'] ?? null)
        );

        $fields = [];

        foreach (array_keys(self::FIELD_LABELS) as $fieldName) {
            $fields[$fieldName] = $this->presentField(
                candidate: $candidate,
                namespace: $namespace,
                fieldName: $fieldName,
            );
        }

        $completionFields = [];

        foreach ($fields as $fieldName => $field) {
            if (($field['needs_action'] ?? false) === true) {
                $completionFields[] = $fieldName;
            }
        }

        return [
            'available' => $contractAvailable,
            'version' => $this->nullableString(
                $namespace['version'] ?? null
            ),
            'needs_user_completion' => $completionFields !== [],
            'completion_fields' => $completionFields,
            'completion_count' => count($completionFields),
            'fields' => $fields,
        ];
    }

    /**
     * Presenta un singolo campo del contratto.
     *
     * @param  array<string, mixed>  $namespace
     * @return array<string, mixed>
     */
    private function presentField(
        ProductIdentificationCandidate $candidate,
        array $namespace,
        string $fieldName
    ): array {
        $field = data_get(
            $namespace,
            "fields.{$fieldName}",
            []
        );

        $field = is_array($field) ? $field : [];

        $currentValue = $this->nullableString(
            data_get($field, 'current.value')
        ) ?? $this->candidateCurrentValue(
            candidate: $candidate,
            fieldName: $fieldName,
        );

        $suggestedValue = $this->nullableString(
            data_get($field, 'suggestion.value')
        );

        $state = $this->normalizeState(
            state: $this->nullableString($field['state'] ?? null),
            currentValue: $currentValue,
            suggestedValue: $suggestedValue,
        );

        $needsAction = in_array(
            $state,
            self::ACTION_STATES,
            true
        );

        $required = (bool) ($field['required'] ?? false);

        return [
            'field' => $fieldName,
            'label' => self::FIELD_LABELS[$fieldName],
            'state' => $state,
            'state_label' => self::STATE_LABELS[$state],
            'current_value' => $currentValue,
            'suggested_value' => $suggestedValue,
            'display_value' => match ($state) {
                'suggested' => $suggestedValue,
                'present',
                'confirmed',
                'modified' => $currentValue,
                default => null,
            },
            'has_unreliable_current' => (
                $state === 'missing'
                && $currentValue !== null
            ),
            'needs_action' => $needsAction,
            'can_accept_suggestion' => (
                $state === 'suggested'
                && $suggestedValue !== null
            ),
            'required' => $required,
            'is_optional' => ! $required,
            'issues' => $this->stringList(
                $field['issues'] ?? []
            ),
        ];
    }

    /**
     * Recupera il namespace Assisted Review persistito.
     *
     * @return array<string, mixed>
     */
    private function assistedReviewNamespace(
        ProductIdentificationCandidate $candidate
    ): array {
        $metadata = is_array($candidate->metadata)
            ? $candidate->metadata
            : [];

        $namespace = data_get(
            $metadata,
            'assisted_review',
            []
        );

        return is_array($namespace) ? $namespace : [];
    }

/**
 * Normalizza lo stato senza trasformare metadata incompleti in certezze.
 */
private function normalizeState(
    ?string $state,
    ?string $currentValue,
    ?string $suggestedValue
): string {
    /*
     * Gli stati missing e declined sono decisioni esplicite del contratto.
     *
     * Un campo missing può conservare un valore corrente come evidenza
     * non affidabile, ma tale valore non deve diventare automaticamente
     * un dato presente e confermabile.
     */
    if (in_array($state, ['missing', 'declined'], true)) {
        return $state;
    }

    if (
        in_array(
            $state,
            ['present', 'confirmed', 'modified'],
            true
        )
        && $currentValue !== null
    ) {
        return $state;
    }

    if (
        $state === 'suggested'
        && $suggestedValue !== null
    ) {
        return 'suggested';
    }

    /*
     * Fallback prudente per candidati storici o metadata incompleti.
     * Non vengono generati nuovi suggerimenti dal presenter.
     */
    if ($currentValue !== null) {
        return 'present';
    }

    if ($suggestedValue !== null) {
        return 'suggested';
    }

    return 'missing';
}

    /**
     * Recupera un valore corrente direttamente dal candidato.
     *
     * Brand e categoria vengono letti soltanto se le relazioni sono già
     * caricate, evitando query nascoste e problemi N+1 nella futura UI.
     */
    private function candidateCurrentValue(
        ProductIdentificationCandidate $candidate,
        string $fieldName
    ): ?string {
        if ($fieldName === 'model') {
            return $this->nullableString($candidate->model);
        }

        if (
            $fieldName === 'brand'
            && $candidate->relationLoaded('brand')
        ) {
            $brand = $candidate->getRelation('brand');

            return $this->nullableString($brand?->name);
        }

        if (
            $fieldName === 'category'
            && $candidate->relationLoaded('category')
        ) {
            $category = $candidate->getRelation('category');

            return $this->nullableString($category?->name);
        }

        return null;
    }

    /**
     * Normalizza una lista di segnalazioni tecniche.
     *
     * @return array<int, string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            $value = [$value];
        }

        $items = [];

        foreach ($value as $item) {
            $normalized = $this->nullableString($item);

            if ($normalized !== null) {
                $items[] = $normalized;
            }
        }

        return array_values(array_unique($items));
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