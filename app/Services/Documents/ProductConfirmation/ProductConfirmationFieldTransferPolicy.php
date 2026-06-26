<?php

namespace App\Services\Documents\ProductConfirmation;

use App\Models\ProductIdentificationCandidate;

class ProductConfirmationFieldTransferPolicy
{
    /**
     * Versione della policy di trasferimento Candidate → Product.
     */
    private const VERSION =
        'product_confirmation_field_transfer_policy_v1';

    /**
     * Versione Assisted Review supportata dalla policy.
     */
    private const SUPPORTED_ASSISTED_REVIEW_VERSION = 'v1';

    /**
     * Stati che autorizzano il trasferimento del valore operativo
     * già presente sul candidato.
     *
     * @var array<int, string>
     */
    private const TRANSFERABLE_STATES = [
        'present',
        'confirmed',
        'modified',
    ];

    /**
     * Determina quali campi Assisted Review possono essere trasferiti
     * dal candidato al prodotto.
     *
     * Il metodo è read-only e non modifica né salva il candidato.
     *
     * @return array{
     *     version: string,
     *     mode: string,
     *     values: array{
     *         brand_id: int|null,
     *         category_id: int|null,
     *         model: string|null
     *     },
     *     fields: array<string, array{
     *         candidate_value: int|string|null,
     *         value: int|string|null,
     *         included: bool,
     *         state: string|null,
     *         reason: string
     *     }>,
     *     excluded_fields: array<int, string>
     * }
     */
    public function resolve(
        ProductIdentificationCandidate $candidate
    ): array {
        $metadata = is_array($candidate->metadata)
            ? $candidate->metadata
            : [];

        /*
         * Un candidato senza namespace Assisted Review è considerato storico.
         *
         * Manteniamo temporaneamente il comportamento precedente per evitare
         * di svuotare dati validi appartenenti a candidati creati prima
         * dell'introduzione del nuovo contratto.
         */
        if (! array_key_exists('assisted_review', $metadata)) {
            return $this->buildResult(
                mode: 'legacy_passthrough',
                fields: [
                    'brand' => $this->resolveLegacyField(
                        $this->candidateValue(
                            candidate: $candidate,
                            fieldName: 'brand',
                        )
                    ),
                    'category' => $this->resolveLegacyField(
                        $this->candidateValue(
                            candidate: $candidate,
                            fieldName: 'category',
                        )
                    ),
                    'model' => $this->resolveLegacyField(
                        $this->candidateValue(
                            candidate: $candidate,
                            fieldName: 'model',
                        )
                    ),
                ],
            );
        }

        $assistedReview = $metadata['assisted_review'];

        /*
         * Un namespace presente ma non valido non può autorizzare il
         * trasferimento di valori potenzialmente ambigui.
         */
        if (! is_array($assistedReview)) {
            return $this->invalidContractResult(
                candidate: $candidate,
                reason: 'invalid_assisted_review_contract',
            );
        }

        if (
            ($assistedReview['version'] ?? null)
                !== self::SUPPORTED_ASSISTED_REVIEW_VERSION
        ) {
            return $this->invalidContractResult(
                candidate: $candidate,
                reason: 'unsupported_assisted_review_version',
            );
        }

        $fields = is_array($assistedReview['fields'] ?? null)
            ? $assistedReview['fields']
            : [];

        return $this->buildResult(
            mode: 'assisted_review',
            fields: [
                'brand' => $this->resolveAssistedField(
                    fieldName: 'brand',
                    candidateValue: $this->candidateValue(
                        candidate: $candidate,
                        fieldName: 'brand',
                    ),
                    fields: $fields,
                ),
                'category' => $this->resolveAssistedField(
                    fieldName: 'category',
                    candidateValue: $this->candidateValue(
                        candidate: $candidate,
                        fieldName: 'category',
                    ),
                    fields: $fields,
                ),
                'model' => $this->resolveAssistedField(
                    fieldName: 'model',
                    candidateValue: $this->candidateValue(
                        candidate: $candidate,
                        fieldName: 'model',
                    ),
                    fields: $fields,
                ),
            ],
        );
    }

    /**
     * Valuta un campo appartenente a un candidato storico.
     *
     * @return array{
     *     candidate_value: int|string|null,
     *     value: int|string|null,
     *     included: bool,
     *     state: null,
     *     reason: string
     * }
     */
    private function resolveLegacyField(
        int|string|null $candidateValue
    ): array {
        $included = $candidateValue !== null;

        return [
            'candidate_value' => $candidateValue,
            'value' => $included ? $candidateValue : null,
            'included' => $included,
            'state' => null,
            'reason' => $included
                ? 'legacy_current_value'
                : 'legacy_value_missing',
        ];
    }

    /**
     * Valuta un singolo campo secondo il relativo stato Assisted Review.
     *
     * @param  array<string, mixed>  $fields
     * @return array{
     *     candidate_value: int|string|null,
     *     value: int|string|null,
     *     included: bool,
     *     state: string|null,
     *     reason: string
     * }
     */
    private function resolveAssistedField(
        string $fieldName,
        int|string|null $candidateValue,
        array $fields
    ): array {
        $field = $fields[$fieldName] ?? null;

        if (! is_array($field)) {
            return $this->excludedField(
                candidateValue: $candidateValue,
                state: null,
                reason: 'invalid_field_contract',
            );
        }

        $state = is_string($field['state'] ?? null)
            ? $field['state']
            : null;

        if (in_array(
            $state,
            self::TRANSFERABLE_STATES,
            true
        )) {
            if ($candidateValue === null) {
                return $this->excludedField(
                    candidateValue: null,
                    state: $state,
                    reason: 'trusted_state_without_candidate_value',
                );
            }

            return [
                'candidate_value' => $candidateValue,
                'value' => $candidateValue,
                'included' => true,
                'state' => $state,
                'reason' => 'trusted_assisted_review_state',
            ];
        }

        return $this->excludedField(
            candidateValue: $candidateValue,
            state: $state,
            reason: match ($state) {
                'declined' => 'user_declined',
                'missing' => 'optional_field_missing',
                'suggested' => 'suggestion_not_confirmed',
                default => 'unsupported_or_invalid_state',
            },
        );
    }

    /**
     * Costruisce un campo escluso dal trasferimento.
     *
     * @return array{
     *     candidate_value: int|string|null,
     *     value: null,
     *     included: false,
     *     state: string|null,
     *     reason: string
     * }
     */
    private function excludedField(
        int|string|null $candidateValue,
        ?string $state,
        string $reason
    ): array {
        return [
            'candidate_value' => $candidateValue,
            'value' => null,
            'included' => false,
            'state' => $state,
            'reason' => $reason,
        ];
    }

    /**
     * Costruisce un risultato conservativo per un contratto Assisted Review
     * non valido o non supportato.
     */
    private function invalidContractResult(
        ProductIdentificationCandidate $candidate,
        string $reason
    ): array {
        return $this->buildResult(
            mode: 'invalid_assisted_review',
            fields: [
                'brand' => $this->excludedField(
                    candidateValue: $this->candidateValue(
                        candidate: $candidate,
                        fieldName: 'brand',
                    ),
                    state: null,
                    reason: $reason,
                ),
                'category' => $this->excludedField(
                    candidateValue: $this->candidateValue(
                        candidate: $candidate,
                        fieldName: 'category',
                    ),
                    state: null,
                    reason: $reason,
                ),
                'model' => $this->excludedField(
                    candidateValue: $this->candidateValue(
                        candidate: $candidate,
                        fieldName: 'model',
                    ),
                    state: null,
                    reason: $reason,
                ),
            ],
        );
    }

    /**
     * Recupera e normalizza il valore operativo presente sul candidato.
     */
    private function candidateValue(
        ProductIdentificationCandidate $candidate,
        string $fieldName
    ): int|string|null {
        return match ($fieldName) {
            'brand' => $this->nullablePositiveInteger(
                $candidate->brand_id
            ),
            'category' => $this->nullablePositiveInteger(
                $candidate->category_id
            ),
            'model' => $this->nullableString(
                $candidate->model
            ),
            default => null,
        };
    }

    /**
     * Costruisce il risultato finale della policy.
     *
     * @param  array<string, array<string, mixed>>  $fields
     */
    private function buildResult(
        string $mode,
        array $fields
    ): array {
        $excludedFields = [];

        foreach ($fields as $fieldName => $field) {
            if (($field['included'] ?? false) !== true) {
                $excludedFields[] = $fieldName;
            }
        }

        return [
            'version' => self::VERSION,
            'mode' => $mode,
            'values' => [
                'brand_id' => $fields['brand']['value'] ?? null,
                'category_id' => $fields['category']['value'] ?? null,
                'model' => $fields['model']['value'] ?? null,
            ],
            'fields' => $fields,
            'excluded_fields' => $excludedFields,
        ];
    }

    /**
     * Normalizza un identificativo opzionale.
     */
    private function nullablePositiveInteger(
        mixed $value
    ): ?int {
        if (! is_numeric($value)) {
            return null;
        }

        $normalized = (int) $value;

        return $normalized > 0 ? $normalized : null;
    }

    /**
     * Normalizza una stringa opzionale.
     */
    private function nullableString(
        mixed $value
    ): ?string {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }
}