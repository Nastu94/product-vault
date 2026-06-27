<?php

namespace App\Services\Documents\AssistedReview;

use App\Models\ProductIdentificationCandidate;

class AssistedReviewConfirmationGuard
{
    /**
     * Stati che indicano campi opzionali ancora incompleti.
     *
     * Questi stati devono essere comunicati all'utente, ma non impediscono
     * la conferma perché la Transfer Policy escluderà i valori non approvati.
     *
     * @var array<int, string>
     */
    private const UNRESOLVED_STATES = [
        'missing',
        'suggested',
    ];

    /**
     * Valuta se un candidato può essere trasformato in prodotto.
     *
     * I candidati storici privi del contratto Assisted Review restano
     * confermabili per compatibilità con il flusso esistente.
     *
     * @return array{
     *     allowed: bool,
     *     reason: string,
     *     unresolved_fields: array<int, string>,
     *     message: string|null
     * }
     */
    public function evaluate(
        ProductIdentificationCandidate $candidate
    ): array {
        $assistedReview = data_get(
            $candidate->metadata,
            'assisted_review'
        );

        /*
         * Compatibilità con candidati creati prima dell'introduzione
         * dell'Assisted Review.
         */
        if (! is_array($assistedReview)) {
            return $this->allowedResult(
                reason: 'legacy_without_assisted_review',
            );
        }

        $fields = is_array($assistedReview['fields'] ?? null)
            ? $assistedReview['fields']
            : [];

        $unresolvedFields = $this->resolveUnresolvedFields(
            assistedReview: $assistedReview,
            fields: $fields,
        );

        /*
        * Brand, categoria e modello sono campi di completamento opzionali.
        *
        * Il candidato può essere confermato anche se uno di questi campi
        * è mancante o contiene un suggerimento non accettato. La policy
        * Candidate → Product impedirà che il valore incerto venga trasferito.
        */
        if ($unresolvedFields !== []) {
            return [
                'allowed' => true,
                'reason' => 'assisted_review_optional_completion',
                'unresolved_fields' => $unresolvedFields,
                'message' => $this->buildOptionalCompletionMessage(
                    $unresolvedFields
                ),
            ];
        }

        return $this->allowedResult(
            reason: 'assisted_review_complete',
        );
    }

    /**
     * Indica direttamente se il candidato è confermabile.
     */
    public function allows(
        ProductIdentificationCandidate $candidate
    ): bool {
        return $this->evaluate($candidate)['allowed'];
    }

    /**
     * Impedisce la conferma soltanto in presenza di condizioni realmente
     * bloccanti.
     *
     * I campi opzionali mancanti o suggeriti non rappresentano più un
     * blocco: verranno esclusi dal prodotto dalla Transfer Policy.
     *
     * @throws AssistedReviewConfirmationBlockedException
     */
    public function ensureCanConfirm(
        ProductIdentificationCandidate $candidate
    ): void {
        $result = $this->evaluate($candidate);

        if ($result['allowed'] === true) {
            return;
        }

        throw new AssistedReviewConfirmationBlockedException(
            $result['message']
                ?? 'Completa i dati prodotto prima di confermare il candidato.'
        );
    }

    /**
     * Restituisce i campi ancora da completare.
     *
     * @param  array<string, mixed>  $assistedReview
     * @param  array<string, mixed>  $fields
     * @return array<int, string>
     */
    private function resolveUnresolvedFields(
        array $assistedReview,
        array $fields
    ): array {
        $unresolvedFields = [];

        /*
         * Gli stati effettivi dei campi sono la sorgente principale.
         */
        foreach ([
            'brand',
            'category',
            'model',
        ] as $fieldName) {
            $state = data_get(
                $fields,
                "{$fieldName}.state"
            );

            if (in_array(
                $state,
                self::UNRESOLVED_STATES,
                true
            )) {
                $unresolvedFields[] = $fieldName;
            }
        }

        /*
         * Usiamo completion_fields come fallback per snapshot incompleti
         * o metadata creati con una versione precedente del contratto.
         */
        $completionFields = $assistedReview[
            'completion_fields'
        ] ?? [];

        if (is_array($completionFields)) {
            foreach ($completionFields as $fieldName) {
                if (
                    is_string($fieldName)
                    && in_array(
                        $fieldName,
                        [
                            'brand',
                            'category',
                            'model',
                        ],
                        true
                    )
                ) {
                    $unresolvedFields[] = $fieldName;
                }
            }
        }

        /*
         * Se il flag dichiara che serve completamento ma non espone
         * alcun campo valido, impediamo comunque la conferma.
         */
        if (
            ($assistedReview['needs_user_completion'] ?? false)
                === true
            && $unresolvedFields === []
        ) {
            $unresolvedFields[] = 'unknown';
        }

        return array_values(array_unique(
            $unresolvedFields
        ));
    }

    /**
     * Costruisce il messaggio mostrabile dalle interfacce.
     *
     * @param  array<int, string>  $unresolvedFields
     */
    private function buildOptionalCompletionMessage(
        array $unresolvedFields
    ): string {
        $labels = array_map(
            fn (string $fieldName): string => match (
                $fieldName
            ) {
                'brand' => 'brand',
                'category' => 'categoria',
                'model' => 'modello',
                default => 'dati prodotto',
            },
            $unresolvedFields
        );

        return 'Puoi confermare il prodotto anche senza completare i seguenti campi: '
            . implode(', ', $labels)
            . '. I valori non confermati non verranno salvati nel prodotto.';
    }

    /**
     * Risultato standard per un candidato confermabile.
     *
     * @return array{
     *     allowed: bool,
     *     reason: string,
     *     unresolved_fields: array<int, string>,
     *     message: null
     * }
     */
    private function allowedResult(
        string $reason
    ): array {
        return [
            'allowed' => true,
            'reason' => $reason,
            'unresolved_fields' => [],
            'message' => null,
        ];
    }
}