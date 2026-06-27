<?php

namespace App\Services\Documents\ReviewSignals;

use Illuminate\Support\Str;

final class ReviewSignalPresenter
{
    /**
     * Versione del contratto di presentazione dei segnali.
     */
    private const VERSION =
        'review_signal_presentation_v1';

    /**
     * Presentazioni conosciute.
     *
     * I codici tecnici restano invariati nei metadata. Questo mapping
     * definisce soltanto il modo in cui vengono spiegati nella UI.
     *
     * @var array<string, array<string, mixed>>
     */
    private const PRESENTATIONS = [
        'unusable_similarity_match' => [
            'group' => 'attention',
            'severity' => 'warning',
            'field' => 'identity',
            'deduplication_key' =>
                'identity_reference_not_reliable',
            'title' => 'Nessun confronto affidabile',
            'message' =>
                'Il confronto con i prodotti conosciuti non è abbastanza affidabile per proporre un dato.',
            'action' =>
                'Controlla nome, brand o modello soltanto se riconosci un errore evidente.',
            'show_in_primary_ui' => true,
        ],

        'similarity_below_min_score' => [
            'group' => 'attention',
            'severity' => 'warning',
            'field' => 'identity',
            'deduplication_key' =>
                'identity_reference_not_reliable',
            'title' =>
                'Riferimento non abbastanza affidabile',
            'message' =>
                'Il nome trovato non corrisponde con sufficiente sicurezza ai dati conosciuti.',
            'action' =>
                'Verifica nome, brand o modello solo se disponi dell’informazione corretta.',
            'show_in_primary_ui' => true,
        ],

        'insufficient_informative_token_overlap' => [
            'group' => 'attention',
            'severity' => 'info',
            'field' => 'identity',
            'deduplication_key' =>
                'identity_name_not_distinctive',
            'title' => 'Nome poco distintivo',
            'message' =>
                'Il nome contiene pochi elementi utili per un confronto affidabile.',
            'action' =>
                'Verifica brand o modello soltanto se sono chiaramente indicati nel documento.',
            'show_in_primary_ui' => true,
        ],

        'low_informative_token_overlap_ratio' => [
            'group' => 'attention',
            'severity' => 'info',
            'field' => 'identity',
            'deduplication_key' =>
                'identity_name_not_distinctive',
            'title' => 'Corrispondenza solo parziale',
            'message' =>
                'Solo una parte limitata del nome coincide con il riferimento disponibile.',
            'action' =>
                'Controlla il nome soltanto se riconosci un errore evidente.',
            'show_in_primary_ui' => true,
        ],

        'low_similarity_to_global_canonical_name' => [
            'group' => 'attention',
            'severity' => 'warning',
            'field' => 'name',
            'deduplication_key' =>
                'canonical_name_difference',
            'title' =>
                'Nome diverso dal riferimento conosciuto',
            'message' =>
                'Il nome letto dal documento differisce dal nome prodotto già conosciuto.',
            'action' =>
                'Confronta i due nomi prima di applicare un suggerimento.',
            'show_in_primary_ui' => true,
        ],

        'quantity_x_unit_price_matches_total_price' => [
            'group' => 'positive',
            'severity' => 'success',
            'field' => 'amounts',
            'deduplication_key' =>
                'amounts_are_consistent',
            'title' => 'Importi coerenti',
            'message' =>
                'Quantità, prezzo unitario e totale della riga risultano coerenti.',
            'action' => null,
            'show_in_primary_ui' => true,
        ],

        'quantity_x_unit_price_differs_from_total_price' => [
            'group' => 'attention',
            'severity' => 'warning',
            'field' => 'amounts',
            'deduplication_key' =>
                'amounts_need_verification',
            'title' => 'Importi da verificare',
            'message' =>
                'Quantità e prezzo unitario non corrispondono al totale della riga.',
            'action' =>
                'Controlla gli importi prima di confermare il candidato.',
            'show_in_primary_ui' => true,
        ],

        'missing_quantity' => [
            'group' => 'missing',
            'severity' => 'info',
            'field' => 'quantity',
            'deduplication_key' =>
                'amount_data_missing',
            'title' => 'Quantità non disponibile',
            'message' =>
                'La quantità non è stata individuata nella riga prodotto.',
            'action' => null,
            'show_in_primary_ui' => false,
        ],

        'missing_unit_price' => [
            'group' => 'missing',
            'severity' => 'info',
            'field' => 'unit_price',
            'deduplication_key' =>
                'amount_data_missing',
            'title' =>
                'Prezzo unitario non disponibile',
            'message' =>
                'Il prezzo unitario non è stato individuato nella riga prodotto.',
            'action' => null,
            'show_in_primary_ui' => false,
        ],

        'missing_total_price' => [
            'group' => 'missing',
            'severity' => 'info',
            'field' => 'total_price',
            'deduplication_key' =>
                'amount_data_missing',
            'title' =>
                'Totale riga non disponibile',
            'message' =>
                'Il totale della riga non è stato individuato.',
            'action' => null,
            'show_in_primary_ui' => false,
        ],

        'non_positive_amount_data' => [
            'group' => 'attention',
            'severity' => 'warning',
            'field' => 'amounts',
            'deduplication_key' =>
                'amounts_need_verification',
            'title' => 'Importi non validi',
            'message' =>
                'Uno o più importi della riga non risultano maggiori di zero.',
            'action' =>
                'Controlla quantità e prezzi prima della conferma.',
            'show_in_primary_ui' => true,
        ],

        'missing_global_facts' => [
            'group' => 'diagnostic',
            'severity' => 'neutral',
            'field' => 'knowledge',
            'deduplication_key' =>
                'knowledge_reference_missing',
            'title' =>
                'Nessun riferimento ancora conosciuto',
            'message' =>
                'Product Vault non dispone ancora di una conferma globale per questo prodotto.',
            'action' =>
                'Puoi comunque revisionare e confermare il candidato.',
            'show_in_primary_ui' => false,
        ],
    ];

    /**
     * Traduce un segnale tecnico in una presentazione UI.
     *
     * @return array{
     *     version: string,
     *     technical_code: string|null,
     *     raw_value: string|null,
     *     known: bool,
     *     source: string|null,
     *     kind: string,
     *     group: string,
     *     severity: string,
     *     field: string|null,
     *     deduplication_key: string,
     *     title: string,
     *     message: string,
     *     action: string|null,
     *     show_in_primary_ui: bool
     * }
     */
    public function present(
        ?string $signal,
        ?string $source = null,
        string $kind = 'signal'
    ): array {
        $rawValue = $this->nullableString($signal);
        $technicalCode = $this->normalizeCode(
            $rawValue
        );

        $normalizedKind = in_array(
            $kind,
            ['signal', 'warning'],
            true
        )
            ? $kind
            : 'signal';

        if ($technicalCode === null) {
            return [
                'version' => self::VERSION,
                'technical_code' => null,
                'raw_value' => $rawValue,
                'known' => false,
                'source' =>
                    $this->nullableString($source),
                'kind' => $normalizedKind,
                'group' => 'diagnostic',
                'severity' => 'neutral',
                'field' => null,
                'deduplication_key' =>
                    'signal_not_available',
                'title' => 'Segnale non disponibile',
                'message' =>
                    'Non è disponibile un dettaglio leggibile per questo controllo.',
                'action' => null,
                'show_in_primary_ui' => false,
            ];
        }

        $known = array_key_exists(
            $technicalCode,
            self::PRESENTATIONS
        );

        $presentation = $known
            ? self::PRESENTATIONS[$technicalCode]
            : $this->fallbackPresentation(
                kind: $normalizedKind,
                technicalCode: $technicalCode,
            );

        return [
            'version' => self::VERSION,
            'technical_code' => $technicalCode,
            'raw_value' => $rawValue,
            'known' => $known,
            'source' =>
                $this->nullableString($source),
            'kind' => $normalizedKind,
            ...$presentation,
        ];
    }

    /**
     * Presentazione conservativa per un codice non ancora mappato.
     *
     * Il codice tecnico rimane disponibile per la diagnostica, ma non
     * viene mostrato come testo principale all'utente.
     *
     * @return array<string, mixed>
     */
    private function fallbackPresentation(
        string $kind,
        string $technicalCode
    ): array {
        if ($kind === 'warning') {
            return [
                'group' => 'attention',
                'severity' => 'warning',
                'field' => null,
                'deduplication_key' =>
                    'unknown_warning:'
                    . $technicalCode,
                'title' => 'Verifica consigliata',
                'message' =>
                    'Product Vault ha rilevato un elemento che potrebbe richiedere un controllo.',
                'action' =>
                    'Controlla i dati del candidato prima di confermarlo.',
                'show_in_primary_ui' => true,
            ];
        }

        return [
            'group' => 'diagnostic',
            'severity' => 'neutral',
            'field' => null,
            'deduplication_key' =>
                'unknown_signal:'
                . $technicalCode,
            'title' =>
                'Informazione tecnica disponibile',
            'message' =>
                'È stato registrato un dettaglio diagnostico non ancora tradotto per la revisione.',
            'action' => null,
            'show_in_primary_ui' => false,
        ];
    }

    /**
     * Normalizza codici snake_case e messaggi testuali equivalenti.
     */
    private function normalizeCode(
        ?string $value
    ): ?string {
        if ($value === null) {
            return null;
        }

        $normalized = Str::ascii($value);
        $normalized = mb_strtolower($normalized);

        $normalized = preg_replace(
            '/[^a-z0-9]+/i',
            '_',
            $normalized
        ) ?: $normalized;

        $normalized = trim($normalized, '_');

        return $normalized !== ''
            ? $normalized
            : null;
    }

    /**
     * Normalizza una stringa opzionale.
     */
    private function nullableString(
        mixed $value
    ): ?string {
        if (
            ! is_string($value)
            && ! is_numeric($value)
        ) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized !== ''
            ? $normalized
            : null;
    }
}