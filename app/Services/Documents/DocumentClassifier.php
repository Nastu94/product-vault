<?php

namespace App\Services\Documents;

use App\Models\Document;
use App\Models\DocumentClassification;
use App\Models\DocumentType;

class DocumentClassifier
{
    /**
     * Classifica il documento usando regole semplici basate sul testo estratto.
     *
     * In questa fase non usiamo AI:
     * lavoriamo solo con keyword e punteggi leggibili.
     */
    public function classify(Document $document): ?DocumentClassification
    {
        $text = trim((string) $document->raw_text);

        if ($text === '') {
            return $this->storeClassification(
                document: $document,
                typeCode: 'unknown',
                confidenceScore: 10,
                reason: 'Nessun testo disponibile per classificare il documento.',
                matchedSignals: []
            );
        }

        $normalizedText = $this->normalizeText($text);

        $candidates = [
            /*
            |--------------------------------------------------------------------------
            | Documenti non pertinenti
            |--------------------------------------------------------------------------
            |
            | Prima valutiamo segnali espliciti di esclusione.
            | Frasi come "non è una fattura" contengono la parola "fattura",
            | ma semanticamente dicono l'opposto: non devono diventare invoice.
            |
            */
            'irrelevant' => $this->scoreIrrelevant($normalizedText),

            /*
            |--------------------------------------------------------------------------
            | Ordine logico dei candidati
            |--------------------------------------------------------------------------
            |
            | Alcuni documenti, come DDT e conferme ordine, possono contenere parole
            | ambigue come "fatturazione", "P.IVA", "totale" o "scontrino".
            | Per questo valutiamo prima i tipi più specifici.
            |
            */
            'delivery_note' => $this->scoreDeliveryNote($normalizedText),
            'order_confirmation' => $this->scoreOrderConfirmation($normalizedText),
            'warranty_certificate' => $this->scoreWarrantyCertificate($normalizedText),
            'repair_document' => $this->scoreRepairDocument($normalizedText),
            'manual' => $this->scoreManual($normalizedText),
            'invoice' => $this->scoreInvoice($normalizedText),
            'receipt' => $this->scoreReceipt($normalizedText),
        ];

        arsort($candidates);

        $bestTypeCode = array_key_first($candidates);
        $bestScore = $candidates[$bestTypeCode] ?? 0;

        if ($bestScore < 25) {
            return $this->storeClassification(
                document: $document,
                typeCode: 'unknown',
                confidenceScore: $bestScore,
                reason: 'Il testo è stato estratto, ma non contiene segnali sufficienti per una classificazione affidabile.',
                matchedSignals: $this->collectMatchedSignals($normalizedText)
            );
        }

        return $this->storeClassification(
            document: $document,
            typeCode: $bestTypeCode,
            confidenceScore: min($bestScore, 100),
            reason: 'Classificazione rule-based basata sui segnali trovati nel testo estratto.',
            matchedSignals: $this->collectMatchedSignals($normalizedText)
        );
    }

    /**
     * Salva la classificazione selezionata e aggiorna il documento.
     */
    private function storeClassification(
        Document $document,
        string $typeCode,
        int $confidenceScore,
        string $reason,
        array $matchedSignals
    ): ?DocumentClassification {
        $documentType = DocumentType::query()
            ->where('code', $typeCode)
            ->where('is_active', true)
            ->first();

        if (! $documentType) {
            return null;
        }

        /*
        |--------------------------------------------------------------------------
        | Una sola classificazione selezionata
        |--------------------------------------------------------------------------
        |
        | Per ora ogni nuova classificazione rule-based sostituisce la precedente
        | come classificazione selezionata.
        |
        */
        $document->classifications()
            ->where('is_selected', true)
            ->update(['is_selected' => false]);

        $classification = DocumentClassification::query()->create([
            'document_id' => $document->id,
            'document_type_id' => $documentType->id,
            'classifier' => 'rule_based_v1',
            'reason' => $reason,
            'confidence_score' => $confidenceScore,
            'is_selected' => true,
            'metadata' => [
                'type_code' => $typeCode,
                'matched_signals' => $matchedSignals,
            ],
        ]);

        $document->update([
            'document_type_id' => $documentType->id,
            'status' => 'classified',
        ]);

        return $classification;
    }

    /**
     * Normalizza il testo per renderlo più facile da confrontare.
     */
    private function normalizeText(string $text): string
    {
        $text = mb_strtolower($text);

        return preg_replace('/\s+/', ' ', $text) ?: $text;
    }

    /**
     * Score per documenti esplicitamente non pertinenti.
     *
     * Queste regole gestiscono documenti informativi/amministrativi che possono
     * contenere parole fiscali come P.IVA, fattura, ricevuta o garanzia, ma solo
     * in forma negativa:
     *
     * - "non è una fattura"
     * - "non contiene acquisti"
     * - "non contiene prodotti associabili a garanzia"
     */
    private function scoreIrrelevant(string $text): int
    {
        $score = $this->scoreKeywords($text, [
            'documento non pertinente' => 60,
            'non pertinente' => 50,
            'non supportato' => 45,
            'unsupported' => 45,

            'non e una fattura' => 45,
            'non è una fattura' => 45,
            'non e fattura' => 40,
            'non è fattura' => 40,

            'non e una ricevuta' => 45,
            'non è una ricevuta' => 45,
            'non e ricevuta' => 40,
            'non è ricevuta' => 40,

            'non contiene acquisti' => 45,
            'non contiene prodotti' => 45,
            'non contiene prodotti associabili a garanzia' => 55,
            'non contiene prodotti associabili' => 45,

            'nessun pagamento e richiesto' => 35,
            'nessun pagamento è richiesto' => 35,
            'solo scopo informativo' => 35,
            'scopo informativo' => 25,
            'promemoria informativo' => 35,
            'comunicazione amministrativa' => 35,
            'promemoria per i residenti' => 30,

            'deve essere classificato come non pertinente' => 70,
            'rispetto alla generazione di prodotti' => 45,
            // Documenti logistici privi di valore economico.
            'distinta di spedizione' => 15,
            'packing list' => 15,
            'documento senza prezzi' => 35,
            'nessun importo economico presente' => 45,

            // Documenti che negano esplicitamente il valore fiscale o di acquisto.
            'non valida come fattura' => 60,
            'non valido come fattura' => 60,
            'non costituisce prova di acquisto' => 60,
            'nessun acquisto effettuato' => 55,

            // Preventivi non ancora trasformati in acquisto.
            'preventivo non fiscale' => 45,
            'prezzo indicativo' => 15,
            'totale indicativo' => 15,
        ]);

        return max(0, min($score, 100));
    }

    /**
     * Score per fattura.
     */
    private function scoreInvoice(string $text): int
    {
        $score = $this->scoreKeywords($text, [
            'fattura' => 35,
            'invoice' => 35,
            'numero fattura' => 30,
            'totale fattura' => 25,
            'imponibile' => 25,
            'partita iva' => 15,
            'p.iva' => 10,
            'iva' => 5,
        ]);

        /*
        |--------------------------------------------------------------------------
        | Riduzione falsi positivi
        |--------------------------------------------------------------------------
        |
        | "via fatturazione", "indirizzo di fatturazione" o dati fiscali del cliente
        | non bastano per dire che il documento sia una fattura.
        |
        */
        if (str_contains($text, 'doc. di trasporto')
            || str_contains($text, 'documento di trasporto')
            || str_contains($text, 'causale del trasporto')
            || str_contains($text, 'incaricato del trasporto')) {
            $score -= 35;
        }

        if (str_contains($text, 'via fatturazione')) {
            $score -= 20;
        }

        /*
        |--------------------------------------------------------------------------
        | Negazione esplicita del valore fiscale
        |--------------------------------------------------------------------------
        |
        | La presenza della parola "fattura" non deve aumentare il punteggio quando
        | il documento dichiara esplicitamente di non essere una fattura o una
        | prova di acquisto.
        |
        */
        if ($this->textExplicitlyNegatesInvoice($text)) {
            $score -= 60;
        }

        return max(0, min($score, 100));
    }

    /**
     * Verifica se il testo nega esplicitamente la natura fiscale o di acquisto.
     */
    private function textExplicitlyNegatesInvoice(string $text): bool
    {
        foreach ([
            'non valida come fattura',
            'non valido come fattura',
            'non e una fattura',
            'non è una fattura',
            'non e fattura',
            'non è fattura',
            'non costituisce prova di acquisto',
            'preventivo non fiscale',
            'documento senza prezzi',
            'nessun acquisto effettuato',
        ] as $signal) {
            if (str_contains($text, $signal)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Score per scontrino/ricevuta.
     */
    private function scoreReceipt(string $text): int
    {
        return $this->scoreKeywords($text, [
            'scontrino' => 35,
            'documento commerciale' => 35,
            'totale complessivo' => 25,
            'totale' => 15,
            'resto' => 15,
            'bancomat' => 15,
            'carta' => 10,
            'pagamento elettronico' => 15,
            'registratore telematico' => 20,
        ]);
    }

    /**
     * Score per certificato di garanzia.
     */
    private function scoreWarrantyCertificate(string $text): int
    {
        return $this->scoreKeywords($text, [
            'garanzia' => 35,
            'warranty' => 35,
            'certificato di garanzia' => 45,
            'numero seriale' => 20,
            'serial number' => 20,
            'estensione garanzia' => 25,
            'durata garanzia' => 20,
        ]);
    }

    /**
     * Score per manuale.
     */
    private function scoreManual(string $text): int
    {
        return $this->scoreKeywords($text, [
            'manuale' => 35,
            'manual' => 35,
            'istruzioni' => 25,
            'user guide' => 30,
            'guida utente' => 30,
            'installazione' => 15,
            'sicurezza' => 10,
        ]);
    }

    /**
     * Score per documento di riparazione/assistenza.
     */
    private function scoreRepairDocument(string $text): int
    {
        return $this->scoreKeywords($text, [
            'riparazione' => 35,
            'repair' => 35,
            'assistenza' => 25,
            'preventivo' => 25,
            'intervento tecnico' => 25,
            'centro assistenza' => 20,
            'manutenzione' => 15,
        ]);
    }

    /**
     * Score per documento di trasporto/consegna.
     */
    private function scoreDeliveryNote(string $text): int
    {
        return $this->scoreKeywords($text, [
            'documento di trasporto' => 50,
            'doc. di trasporto' => 50,
            'ddt' => 35,
            'delivery note' => 40,
            'bolla di consegna' => 35,
            'causale del trasporto' => 30,
            'incaricato del trasporto' => 25,
            'data e ora inizio trasporto' => 25,
            'firma destinatario' => 20,
            'destinatario' => 10,
            'destinazione' => 10,
            'consegna' => 10,
        ]);
    }

    /**
     * Score per conferma ordine.
     */
    private function scoreOrderConfirmation(string $text): int
    {
        return $this->scoreKeywords($text, [
            'conferma ordine' => 40,
            'ordine confermato' => 35,
            'order confirmation' => 40,
            'numero ordine' => 25,
            'riepilogo ordine' => 25,
            'grazie per il tuo ordine' => 25,
        ]);
    }

    /**
     * Calcola uno score sommando i pesi delle keyword trovate.
     */
    private function scoreKeywords(string $text, array $keywords): int
    {
        $score = 0;

        foreach ($keywords as $keyword => $points) {
            if (str_contains($text, $keyword)) {
                $score += $points;
            }
        }

        return min($score, 100);
    }

    /**
     * Raccoglie alcuni segnali utili da salvare nei metadata.
     *
     * Questi segnali servono per debug e per spiegare all'utente/admin
     * perché il documento è stato classificato in un certo modo.
     */
    private function collectMatchedSignals(string $text): array
    {
        $signals = [];

        $keywords = [
            // Fatture
            'fattura',
            'invoice',
            'numero fattura',
            'totale fattura',
            'imponibile',
            'p.iva',
            'partita iva',

            // Scontrini / ricevute
            'scontrino',
            'documento commerciale',
            'totale complessivo',
            'resto',
            'bancomat',
            'pagamento elettronico',
            'registratore telematico',

            // Garanzie
            'garanzia',
            'warranty',
            'certificato di garanzia',
            'numero seriale',
            'serial number',
            'estensione garanzia',
            'durata garanzia',

            // Manuali
            'manuale',
            'manual',
            'istruzioni',
            'user guide',
            'guida utente',
            'installazione',

            // Riparazioni / assistenza
            'riparazione',
            'repair',
            'assistenza',
            'preventivo',
            'intervento tecnico',
            'centro assistenza',
            'manutenzione',

            // DDT / consegna / trasporto
            'documento di trasporto',
            'doc. di trasporto',
            'ddt',
            'delivery note',
            'bolla di consegna',
            'causale del trasporto',
            'incaricato del trasporto',
            'data e ora inizio trasporto',
            'firma destinatario',
            'destinatario',
            'destinazione',
            'consegna',

            // Conferme ordine
            'conferma ordine',
            'ordine confermato',
            'order confirmation',
            'numero ordine',
            'riepilogo ordine',
            'grazie per il tuo ordine',

            // Documenti non pertinenti
            'documento non pertinente',
            'non pertinente',
            'non supportato',
            'unsupported',
            'non e una fattura',
            'non è una fattura',
            'non e una ricevuta',
            'non è una ricevuta',
            'non contiene acquisti',
            'non contiene prodotti',
            'nessun pagamento e richiesto',
            'nessun pagamento è richiesto',
            'solo scopo informativo',
            'promemoria informativo',
            'comunicazione amministrativa',
            'deve essere classificato come non pertinente',
            'rispetto alla generazione di prodotti',
            'distinta di spedizione',
            'packing list',
            'documento senza prezzi',
            'nessun importo economico presente',
            'non valida come fattura',
            'non valido come fattura',
            'non costituisce prova di acquisto',
            'nessun acquisto effettuato',
            'preventivo non fiscale',
            'prezzo indicativo',
            'totale indicativo',
        ];

        foreach ($keywords as $keyword) {
            if (str_contains($text, $keyword)) {
                $signals[] = $keyword;
            }
        }

        return array_values(array_unique($signals));
    }
}