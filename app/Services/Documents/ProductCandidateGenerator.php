<?php

namespace App\Services\Documents;

use App\Models\Document;
use App\Models\DocumentLine;
use App\Models\ProductIdentificationCandidate;

class ProductCandidateGenerator
{
    /**
     * Genera candidati prodotto partendo dalle righe documento estratte.
     *
     * In questa fase NON creiamo ancora Product reali.
     * Salviamo solo candidati revisionabili dall'utente.
     */
    public function generate(Document $document): int
    {
        $lines = $document
            ->lines()
            ->orderBy('line_number')
            ->get();

        if ($lines->isEmpty()) {
            $this->updateDocumentProductReliabilityScore($document);

            return 0;
        }

        /*
        |--------------------------------------------------------------------------
        | Pulizia candidati precedenti
        |--------------------------------------------------------------------------
        |
        | Per ora rigeneriamo i candidati ogni volta.
        | Quando avremo la revisione manuale, eviteremo di eliminare candidati
        | già confermati o modificati dall'utente.
        |
        */
        $this->clearUnlinkedCandidates($document);

        /*
        |--------------------------------------------------------------------------
        | Scontrini non durevoli / food service
        |--------------------------------------------------------------------------
        |
        | Uno scontrino di ristorante, bar, trattoria o pizzeria può avere righe
        | perfettamente leggibili, ma non rappresenta prodotti durevoli da inserire
        | nel vault garanzie. In questo caso salviamo il documento, ma non generiamo
        | candidati prodotto.
        |
        */
        if ($this->documentLooksLikeFoodServiceReceipt($document)) {
            $this->updateDocumentProductReliabilityScore($document);

            return 0;
        }

        $created = 0;

        foreach ($lines as $line) {
            if (! $this->lineIsUsable($line)) {
                continue;
            }

            $productCode = $line->metadata['product_code_candidate'] ?? null;
            $serialNumber = $line->metadata['serial_number_candidate'] ?? null;

            ProductIdentificationCandidate::query()->create([
                'document_id' => $document->id,
                'document_line_id' => $line->id,
                'product_id' => null,
                'brand_id' => null,
                'category_id' => null,
                'name' => $this->normalizeProductName($line->description),
                'model' => $this->guessModel($productCode),
                'serial_number' => $serialNumber,
                'ean_code' => $this->guessEanCode($productCode),
                'price' => $this->guessPrice($line),
                'source' => 'document_line_parser',
                'confidence_score' => $this->estimateConfidenceScore($line, $productCode),
                'is_selected' => false,
                'metadata' => [
                    'generator' => 'product_candidate_generator_v1',
                    'document_line_id' => $line->id,
                    'line_confidence_score' => $line->confidence_score,
                    'line_parser' => $line->metadata['parser'] ?? null,
                    'line_mode' => $line->metadata['mode'] ?? null,
                    'product_code_candidate' => $productCode,
                    'serial_number_candidate' => $serialNumber,
                    'candidate_price_source' => $this->guessPriceSource($line),
                    'raw_line_text' => $line->raw_text,
                    'quantity' => $line->quantity,
                    'unit_price' => $line->unit_price,
                    'total_price' => $line->total_price,
                ],
            ]);

            $created++;
        }

        $this->updateDocumentProductReliabilityScore($document);

        return $created;
    }

    /**
     * Aggiorna lo score prodotto del documento in base al miglior candidato generato.
     *
     * Nota MVP:
     * - null significa che non ci sono candidati prodotto valutabili;
     * - un numero indica la migliore affidabilità tra i candidati revisionabili.
     */
    private function updateDocumentProductReliabilityScore(Document $document): void
    {
        $bestCandidateScore = ProductIdentificationCandidate::query()
            ->where('document_id', $document->id)
            ->whereNull('product_id')
            ->max('confidence_score');

        $document->update([
            'product_reliability_score' => $bestCandidateScore !== null
                ? (int) $bestCandidateScore
                : null,
        ]);
    }

    /**
     * Elimina candidati non ancora collegati a prodotti reali.
     */
    private function clearUnlinkedCandidates(Document $document): void
    {
        ProductIdentificationCandidate::query()
            ->where('document_id', $document->id)
            ->whereNull('product_id')
            ->delete();
    }

    /**
     * Capisce se il documento sembra uno scontrino di ristorante/bar/food service.
     *
     * Non stiamo dicendo che il documento non sia utile: viene salvato e parsato.
     * Stiamo solo evitando di trasformare piatti, bevande o coperti in prodotti
     * durevoli con garanzia.
     */
    private function documentLooksLikeFoodServiceReceipt(Document $document): bool
    {
        if ($document->documentType?->code !== 'receipt') {
            return false;
        }

        $text = mb_strtolower((string) $document->raw_text);

        if ($text === '') {
            return false;
        }

        /*
        |--------------------------------------------------------------------------
        | Segnali di prodotto durevole
        |--------------------------------------------------------------------------
        |
        | Se compaiono segnali forti di prodotto fisico durevole, non applichiamo
        | il filtro food service. Questo evita di bloccare scontrini elettronica
        | che possono contenere parole generiche non rilevanti.
        |
        */
        $durableSignals = [
            'garanzia',
            'seriale',
            'barcode',
            'ean',
            'smartphone',
            'telefono',
            'iphone',
            'tablet',
            'notebook',
            'laptop',
            'pc ',
            'computer',
            'monitor',
            'tv ',
            'televisore',
            'lavatrice',
            'lavastoviglie',
            'frigorifero',
            'forno',
            'asciugatrice',
            'aspirapolvere',
            'console',
            'stampante',
            'modello',
        ];

        foreach ($durableSignals as $signal) {
            if (str_contains($text, $signal)) {
                return false;
            }
        }

        $foodServiceSignals = [
            'ristorante',
            'trattoria',
            'pizzeria',
            'osteria',
            'bar ',
            'caffe',
            'caffè',
            'tavolo',
            'coperto',
            'menu',
            'menù',
            'antipasto',
            'primo',
            'secondo',
            'piatto',
            'contorno',
            'dolce',
            'pizza',
            'pasta',
            'gnocchi',
            'ravioli',
            'vino',
            'birra',
            'acqua',
            'bevanda',
            'amaro',
            'zabaione',
        ];

        $matches = 0;

        foreach ($foodServiceSignals as $signal) {
            if (str_contains($text, $signal)) {
                $matches++;
            }
        }

        return $matches >= 2;
    }

    /**
     * Decide se una riga è abbastanza utile per generare un candidato prodotto.
     */
    private function lineIsUsable(DocumentLine $line): bool
    {
        if (! $line->description) {
            return false;
        }

        if (mb_strlen($line->description) < 3) {
            return false;
        }

        if ($this->lineLooksLikeNonDurableOrService($line)) {
            return false;
        }

        return true;
    }

    /**
     * Esclude righe che rappresentano pasti, consumabili, pulizia o servizi.
     *
     * Il documento può comunque conservarle come DocumentLine, ma non devono
     * diventare candidati prodotto perché non sono beni durevoli da gestire
     * nel vault garanzie.
     */
    private function lineLooksLikeNonDurableOrService(DocumentLine $line): bool
    {
        $description = mb_strtolower((string) $line->description);
        $rawText = mb_strtolower((string) $line->raw_text);
        $invoiceCode = mb_strtolower((string) ($line->metadata['invoice_code'] ?? ''));
        $productCode = mb_strtolower((string) ($line->metadata['product_code_candidate'] ?? ''));

        /*
        |--------------------------------------------------------------------------
        | Prefix da fattura
        |--------------------------------------------------------------------------
        |
        | Se una fattura usa codici strutturati come FOOD-01, CLEAN-01 o SERV-TRASP,
        | possiamo usarli come segnale forte per evitare falsi candidati prodotto.
        |
        */
        $blockedPrefixes = [
            'food',
            'clean',
            'serv',
            'ship',
            'trasp',
            'sconto',
            'discount',
        ];

        foreach ($blockedPrefixes as $prefix) {
            if (
                str_starts_with($invoiceCode, $prefix)
                || str_starts_with($productCode, $prefix)
            ) {
                return true;
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Righe contabili di sconto
        |--------------------------------------------------------------------------
        |
        | Blocchiamo righe che sono esse stesse sconti/promozioni, ma non prodotti
        | validi che menzionano uno sconto nel testo di supporto.
        |
        */
        if (
            str_starts_with($description, 'sconto')
            || str_starts_with($description, 'promo')
        ) {
            return true;
        }

        /*
        |--------------------------------------------------------------------------
        | Segnali testuali non durevoli
        |--------------------------------------------------------------------------
        |
        | Questo filtro deve restare prudente: blocca parole fortemente associate
        | a pasti, bevande, pulizia/consumabili e servizi logistici.
        |
        */
        $blockedSignals = [
            'menu',
            'pranzo',
            'cena',
            'caffe',
            'caffè',
            'espresso',
            'pizza',
            'pasta',
            'ravioli',
            'gnocchi',
            'vino',
            'birra',
            'acqua',
            'bevanda',
            'dolce',
            'zabaione',
            'coperto',
            'servizio',
            'trasporto',
            'spedizione',
            'consegna',
            'detergente',
            'pavimenti',
            'microfibra',
            'panno',
            'pulizia',
            'limone 1l',
            'riparazione',
            'manodopera',
            'sanificante',
            'mensa',
        ];

        foreach ($blockedSignals as $signal) {
            if (str_contains($description, $signal) || str_contains($rawText, $signal)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Normalizza il nome prodotto candidato.
     */
    private function normalizeProductName(?string $description): ?string
    {
        if (! $description) {
            return null;
        }

        return trim(preg_replace('/\s+/', ' ', $description) ?: $description);
    }

    /**
     * Usa il codice prodotto come modello se non sembra un EAN.
     */
    private function guessModel(?string $productCode): ?string
    {
        if (! $productCode) {
            return null;
        }

        if ($this->looksLikeEan($productCode)) {
            return null;
        }

        return $productCode;
    }

    /**
     * Riconosce EAN/GTIN numerici comuni.
     */
    private function guessEanCode(?string $productCode): ?string
    {
        if (! $productCode) {
            return null;
        }

        $normalized = preg_replace('/\D+/', '', $productCode) ?: '';

        if ($this->looksLikeEan($normalized)) {
            return $normalized;
        }

        return null;
    }

    /**
     * Verifica se un codice sembra EAN/GTIN.
     */
    private function looksLikeEan(string $code): bool
    {
        return (bool) preg_match('/^\d{8}$|^\d{12}$|^\d{13}$|^\d{14}$/', $code);
    }

    /**
     * Sceglie il prezzo candidato del singolo prodotto.
     *
     * Il ProductIdentificationCandidate rappresenta il prodotto, non la riga ordine.
     * Per questo il prezzo migliore è:
     * - unit_price, se disponibile;
     * - total_price / quantity, se abbiamo totale riga e quantità maggiore di zero;
     * - total_price solo come fallback quando la quantità è assente o pari a 1.
     */
    private function guessPrice(DocumentLine $line): ?float
    {
        if ($line->unit_price !== null) {
            return (float) $line->unit_price;
        }

        if ($line->total_price !== null && $line->quantity !== null && (float) $line->quantity > 0) {
            return round((float) $line->total_price / (float) $line->quantity, 2);
        }

        if ($line->total_price !== null) {
            return (float) $line->total_price;
        }

        return null;
    }

    /**
     * Indica da dove deriva il prezzo candidato.
     */
    private function guessPriceSource(DocumentLine $line): ?string
    {
        if ($line->unit_price !== null) {
            return 'unit_price';
        }

        if ($line->total_price !== null && $line->quantity !== null && (float) $line->quantity > 0) {
            return 'total_price_divided_by_quantity';
        }

        if ($line->total_price !== null) {
            return 'total_price_fallback';
        }

        return null;
    }

    /**
     * Stima semplice della confidenza del candidato prodotto.
     */
    private function estimateConfidenceScore(DocumentLine $line, ?string $productCode): int
    {
        $score = 35;

        if ($line->description) {
            $score += 20;
        }

        if ($productCode) {
            $score += 20;
        }

        if ($line->quantity !== null) {
            $score += 10;
        }

        if ($line->total_price !== null || $line->unit_price !== null) {
            $score += 10;
        }

        if ($line->confidence_score !== null && $line->confidence_score >= 80) {
            $score += 10;
        }

        return min($score, 100);
    }
}