<?php

namespace App\Services\Documents\ProductConfirmation;

use App\Models\DocumentLine;
use App\Models\ProductIdentificationCandidate;

class ProductConfirmationProvenanceSnapshotBuilder
{
    /**
     * Versione del contratto di provenienza Candidate → Product.
     */
    private const VERSION =
        'product_confirmation_provenance_v1';

    /**
     * Costruisce uno snapshot immutabile dei dati disponibili al momento
     * della conferma.
     *
     * Il metodo non modifica né salva candidato, documento o riga.
     *
     * @param  array<string, mixed>  $fieldTransfer
     * @return array<string, mixed>
     */
    public function build(
        ProductIdentificationCandidate $candidate,
        array $fieldTransfer
    ): array {
        $document = $candidate->document;
        $documentLine = $candidate->documentLine;

        $metadata = is_array($candidate->metadata)
            ? $candidate->metadata
            : [];

        $assistedReview = is_array(
            $metadata['assisted_review'] ?? null
        )
            ? $metadata['assisted_review']
            : null;

        return [
            'version' => self::VERSION,
            'source' => 'candidate_confirmation',

            /*
             * Identificativi che consentono di risalire alle entità
             * operative ancora presenti nel database.
             */
            'references' => [
                'candidate_id' => $this->nullablePositiveInteger(
                    $candidate->getKey()
                ),
                'document_id' => $this->nullablePositiveInteger(
                    $candidate->document_id
                ),
                'document_line_id' =>
                    $this->nullablePositiveInteger(
                        $candidate->document_line_id
                    ),
            ],

            /*
             * Contesto documentale esistente al momento della conferma.
             */
            'document' => $document === null
                ? null
                : [
                    'id' => $this->nullablePositiveInteger(
                        $document->getKey()
                    ),
                    'team_id' => $this->nullablePositiveInteger(
                        $document->team_id
                    ),
                    'merchant_id' =>
                        $this->nullablePositiveInteger(
                            $document->merchant_id
                        ),
                    'currency_id' =>
                        $this->nullablePositiveInteger(
                            $document->currency_id
                        ),
                    'document_type_id' =>
                        $this->nullablePositiveInteger(
                            $document->document_type_id
                        ),
                    'document_type_code' =>
                        $this->nullableString(
                            $document->documentType?->code
                        ),
                    'purchase_date' =>
                        $document->purchase_date?->format(
                            'Y-m-d'
                        ),
                    'original_filename' =>
                        $this->nullableString(
                            $document->original_filename
                        ),
                ],

            /*
             * Valori grezzi del candidato prima della trasformazione.
             *
             * Anche un valore escluso, come un falso modello AX3000,
             * rimane qui come evidenza storica.
             */
            'candidate' => [
                'id' => $this->nullablePositiveInteger(
                    $candidate->getKey()
                ),
                'name' => $this->nullableString(
                    $candidate->name
                ),
                'brand_id' =>
                    $this->nullablePositiveInteger(
                        $candidate->brand_id
                    ),
                'category_id' =>
                    $this->nullablePositiveInteger(
                        $candidate->category_id
                    ),
                'model' => $this->nullableString(
                    $candidate->model
                ),
                'serial_number' => $this->nullableString(
                    $candidate->serial_number
                ),
                'ean_code' => $this->nullableString(
                    $candidate->ean_code
                ),
                'price' => $this->nullableDecimalString(
                    $candidate->price
                ),
                'source' => $this->nullableString(
                    $candidate->source
                ),
                'confidence_score' =>
                    $this->nullableInteger(
                        $candidate->confidence_score
                    ),
                'review_status' => $this->nullableString(
                    $candidate->review_status
                ),
            ],

            /*
             * Contratto Assisted Review esistente al momento della
             * conferma. Non viene ricostruito in seguito.
             */
            'assisted_review' => $assistedReview,

            /*
             * Decisione della Transfer Policy: valori inclusi, valori
             * esclusi e relativa motivazione.
             */
            'field_transfer' => $fieldTransfer,

            /*
             * Valori che il creator è autorizzato a salvare nel prodotto.
             */
            'resolved_product_values' => [
                'name' => $this->nullableString(
                    $candidate->name
                ),
                'brand_id' =>
                    $this->nullablePositiveInteger(
                        data_get(
                            $fieldTransfer,
                            'values.brand_id'
                        )
                    ),
                'category_id' =>
                    $this->nullablePositiveInteger(
                        data_get(
                            $fieldTransfer,
                            'values.category_id'
                        )
                    ),
                'model' => $this->nullableString(
                    data_get(
                        $fieldTransfer,
                        'values.model'
                    )
                ),
                'serial_number' => $this->nullableString(
                    $candidate->serial_number
                ),
                'ean_code' => $this->nullableString(
                    $candidate->ean_code
                ),
                'purchase_price' =>
                    $this->nullableDecimalString(
                        $candidate->price
                    ),
            ],

            /*
             * Evidenza strutturale della riga da cui nasce il candidato.
             */
            'document_line' => $documentLine instanceof DocumentLine
                ? $this->documentLineSnapshot(
                    $documentLine
                )
                : null,
        ];
    }

    /**
     * Costruisce lo snapshot della riga sorgente.
     *
     * @return array<string, mixed>
     */
    private function documentLineSnapshot(
        DocumentLine $line
    ): array {
        $metadata = is_array($line->metadata)
            ? $line->metadata
            : [];

        return [
            'id' => $this->nullablePositiveInteger(
                $line->getKey()
            ),
            'document_line_type_id' =>
                $this->nullablePositiveInteger(
                    $line->document_line_type_id
                ),
            'line_number' => $this->nullableInteger(
                $line->line_number
            ),
            'raw_text' => $this->nullableString(
                $line->raw_text
            ),
            'raw_text_hash' => $this->textHash(
                $line->raw_text
            ),
            'description' => $this->nullableString(
                $line->description
            ),
            'quantity' => $this->nullableDecimalString(
                $line->quantity
            ),
            'unit_price' => $this->nullableDecimalString(
                $line->unit_price
            ),
            'total_price' => $this->nullableDecimalString(
                $line->total_price
            ),
            'confidence_score' =>
                $this->nullableInteger(
                    $line->confidence_score
                ),

            /*
             * Manteniamo soltanto le evidenze direttamente utili alla
             * provenienza, evitando di duplicare tutto il metadata tecnico.
             */
            'evidence' => [
                'product_code_candidate' =>
                    $this->nullableString(
                        $metadata[
                            'product_code_candidate'
                        ] ?? null
                    ),
                'serial_number_candidate' =>
                    $this->nullableString(
                        $metadata[
                            'serial_number_candidate'
                        ] ?? null
                    ),
                'manual_review' => is_array(
                    $metadata['manual_review'] ?? null
                )
                    ? $metadata['manual_review']
                    : null,
                'amount_consistency' => is_array(
                    $metadata[
                        'document_line_amount_consistency'
                    ] ?? null
                )
                    ? $metadata[
                        'document_line_amount_consistency'
                    ]
                    : null,
            ],
        ];
    }

    /**
     * Converte un identificativo in intero positivo opzionale.
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
     * Converte un valore in intero opzionale.
     */
    private function nullableInteger(
        mixed $value
    ): ?int {
        return is_numeric($value)
            ? (int) $value
            : null;
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

        return $normalized !== ''
            ? $normalized
            : null;
    }

    /**
     * Normalizza un valore decimale senza convertirlo in float.
     */
    private function nullableDecimalString(
        mixed $value
    ): ?string {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized !== ''
            ? $normalized
            : null;
    }

    /**
     * Produce un hash stabile del testo grezzo della riga.
     */
    private function textHash(
        mixed $value
    ): ?string {
        $text = $this->nullableString($value);

        return $text !== null
            ? hash('sha256', $text)
            : null;
    }
}