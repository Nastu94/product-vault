<?php

namespace App\Services\Documents\InvoiceTableExtraction;

/**
 * Calcola l'affidabilità di un risultato di estrazione tabella.
 *
 * L'obiettivo non è riconoscere il prodotto finale, ma scegliere quale strategia
 * ha ricostruito meglio le righe fattura.
 */
class InvoiceTableExtractionScorer
{
    /**
     * Restituisce il risultato con punteggio e warning aggiornati.
     * Il punteggio è un numero da 0 a 100 che rappresenta l'affidabilità del risultato.
     * I warning sono stringhe che indicano potenziali problemi o aree di miglioramento.
     * Il punteggio è calcolato principalmente sulla base delle righe estratte, ma tiene conto anche di
     * altri fattori come la coerenza dei dati, la presenza di codici prodotto, la completezza e la qualità delle descrizioni.
     */
    public function score(InvoiceTableExtractionResult $result): InvoiceTableExtractionResult
    {
        if (! $result->hasRows()) {
            return $result->withScore(0, ['no_rows_extracted']);
        }

        $rowScores = [];
        $warnings = [];

        foreach ($result->rows as $row) {
            $rowScores[] = $this->scoreRow($row);

            foreach ($this->rowWarnings($row) as $warning) {
                $warnings[] = $warning;
            }
        }

        foreach ($this->resultWarnings($result) as $warning) {
            $warnings[] = $warning;
        }

        $averageRowScore = (int) round(array_sum($rowScores) / max(1, count($rowScores)));

        $coverageBonus = $this->coverageBonus($result);
        $penalty = $this->resultPenalty($result);

        $score = $averageRowScore + $coverageBonus - $penalty;

        return $result->withScore($score, $warnings);
    }

    /**
     * Calcola il punteggio di una singola riga candidata.
     * Il punteggio è basato su vari fattori come la presenza di codice, la lunghezza della descrizione, 
     * la coerenza dei prezzi e quantità, e altri elementi che indicano se la riga rappresenta un prodotto acquistato.
     * Il punteggio è normalizzato tra 0 e 100, con penalità per righe 
     * che sembrano essere sconti, coupon, storni o metadata tecnici.
     */
    private function scoreRow(InvoiceRowCandidate $row): int
    {
        $score = 0;

        if ($row->code !== null && trim($row->code) !== '') {
            $score += 12;
        }

        if (mb_strlen($row->fullDescription()) >= 6) {
            $score += 15;
        }

        if ($row->quantity !== null && $row->quantity > 0) {
            $score += 10;
        }

        if ($row->vatRate !== null && $row->vatRate !== '') {
            $score += 5;
        }

        if ($row->unitPrice !== null && $row->unitPrice > 0) {
            $score += 15;
        }

        if ($row->totalPrice !== null && $row->totalPrice > 0) {
            $score += 15;
        }

        if ($row->hasCoherentAmounts()) {
            $score += 15;
        }

        if ($row->ean !== null) {
            $score += 8;
        }

        if ($row->serialNumber !== null) {
            $score += 5;
        }

        if (! empty($row->supportingLines)) {
            $score += 3;
        }

        /*
        |--------------------------------------------------------------------------
        | Penalità forti
        |--------------------------------------------------------------------------
        |
        | Un totale <= 0 non è una riga prodotto acquistato.
        | Può essere sconto, coupon, storno o rettifica.
        */
        if ($row->totalPrice !== null && $row->totalPrice <= 0) {
            $score -= 45;
        }

        if ($row->unitPrice !== null && $row->unitPrice <= 0) {
            $score -= 25;
        }

        if ($this->descriptionLooksLikeTechnicalOnly($row->description)) {
            $score -= 20;
        }

        if (in_array('completed_from_shifted_amounts', $row->warnings, true)) {
            $score -= 8;
        }

        return max(0, min(100, $score));
    }

    /**
     * Restituisce un array di warning per una riga candidata, basati su potenziali problemi come:
     * - Mancanza di codice prodotto
     * - Descrizione troppo corta
     * - Mancanza di prezzi o quantità
     * - Prezzi non coerenti tra unitario e totale
     * - Descrizione che sembra essere solo metadata tecnico (es. EAN, serial number)
     * - Warning specifici generati durante l'estrazione, come "completed_from_shifted_amounts" 
     *   che indica che la riga è stata completata inferendo prezzi/quantità da altre righe, il che può essere meno affidabile.
     * Questi warning non indicano necessariamente che la riga è errata, 
     * ma segnalano aree che potrebbero richiedere attenzione o miglioramenti nella strategia di estrazione.
     */
    private function rowWarnings(InvoiceRowCandidate $row): array
    {
        $warnings = [];

        if ($row->code === null || trim($row->code) === '') {
            $warnings[] = 'row_without_code';
        }

        if (mb_strlen($row->fullDescription()) < 6) {
            $warnings[] = 'row_description_too_short';
        }

        if (! $row->hasPrice()) {
            $warnings[] = 'row_without_price';
        }

        if ($row->unitPrice !== null && $row->unitPrice <= 0) {
            $warnings[] = 'row_with_non_positive_unit_price';
        }

        if ($row->totalPrice !== null && $row->totalPrice <= 0) {
            $warnings[] = 'row_with_non_positive_total_price';
        }

        if (
            $row->quantity !== null
            && $row->unitPrice !== null
            && $row->totalPrice !== null
            && ! $row->hasCoherentAmounts()
        ) {
            $warnings[] = 'row_amounts_not_coherent';
        }

        if ($this->descriptionLooksLikeTechnicalOnly($row->description)) {
            $warnings[] = 'row_description_looks_like_technical_metadata';
        }

        if (in_array('completed_from_shifted_amounts', $row->warnings, true)) {
            $warnings[] = 'row_completed_from_shifted_amounts';
        }

        return $warnings;
    }

    /**
     * Calcola bonus e malus basati sulla copertura e completezza del risultato:
     * - Bonus per avere almeno 2 righe, perché una singola riga è spesso un falso positivo.
     * - Bonus per avere tutte le righe con prezzo, perché una riga senza prezzo è più sospetta.
     * - Bonus per avere almeno una riga identificata, perché indica che la strategia
     *  è riuscita a ricostruire almeno un prodotto con dati coerenti.
     * - Malus per non avere righe con prezzo, perché è molto probabile che non siano righe prodotto acquistato.
     * - Malus per avere solo 1 riga, perché è spesso un falso positivo (es. una riga di totale o un'intestazione).
     * - Malus per ogni riga senza prezzo, con un limite massimo, perché più righe senza prezzo ci sono, 
     *  più è probabile che il risultato sia spazzatura.
     */
    private function coverageBonus(InvoiceTableExtractionResult $result): int
    {
        $bonus = 0;

        if ($result->rowCount() >= 2) {
            $bonus += 5;
        }

        if ($result->pricedRowsCount() === $result->rowCount()) {
            $bonus += 8;
        }

        if ($result->identifiedRowsCount() > 0) {
            $bonus += 5;
        }

        return $bonus;
    }

    /**
     * Calcola penalità basate su problemi evidenziati dal risultato, come:
     * - Non avere righe con prezzo, perché è molto probabile che non siano righe prodotto acquistato.
     * - Avere solo 1 riga, perché è spesso un falso positivo (es. una riga di totale o un'intestazione).
     * - Avere righe senza prezzo, con penalità che aumentano al crescere del numero di righe senza prezzo, perché più righe senza prezzo ci sono, più è probabile che il risultato sia spazzatura.
     * - Avere meno righe del numero di righe codice prodotto attese, perché indica che la strategia non è riuscita a ricostruire tutte le righe prodotto, il che può essere un segnale di scarsa qualità del risultato.
     * - Avere una copertura insufficiente delle righe codice prodotto attese, con penalità che aumentano se la copertura è inferiore a soglie come 80% o 60%, perché una copertura bassa indica che molte righe prodotto non sono state estratte, il che può essere un segnale di scarsa qualità del risultato.
     * Queste penalità aiutano a distinguere i risultati che, pur avendo alcune righe, mostrano evidenti segnali di incompletezza o incoerenza, e quindi sono meno affidabili.
     */
    private function resultPenalty(InvoiceTableExtractionResult $result): int
    {
        $penalty = 0;

        if ($result->pricedRowsCount() === 0) {
            $penalty += 30;
        }

        if ($result->rowCount() === 1) {
            $penalty += 8;
        }

        $unpricedRows = $result->rowCount() - $result->pricedRowsCount();

        if ($unpricedRows > 0) {
            $penalty += min(30, $unpricedRows * 10);
        }

        $expectedCodeRows = (int) ($result->metadata['expected_code_rows'] ?? 0);

        if ($expectedCodeRows > 0) {
            $missingRows = max(0, $expectedCodeRows - $result->rowCount());
            $coverageRatio = $result->rowCount() / max(1, $expectedCodeRows);

            if ($missingRows > 0) {
                $penalty += min(45, $missingRows * 12);
            }

            if ($expectedCodeRows >= 3 && $coverageRatio < 0.8) {
                $penalty += 20;
            }

            if ($expectedCodeRows >= 3 && $coverageRatio < 0.6) {
                $penalty += 15;
            }
        }

        return $penalty;
    }

    /**
     * Restituisce un array di warning basati su potenziali problemi del risultato complessivo, come:
     * - Avere meno righe del numero di righe codice prodotto attese, perché indica che la strategia non è riuscita a ricostruire tutte le righe prodotto, il che può essere un segnale di scarsa qualità del risultato.
     * - Avere una copertura insufficiente delle righe codice prodotto attese, con warning che indicano se la copertura è inferiore a soglie come 80% o 60%, perché una copertura bassa indica che molte righe prodotto non sono state estratte, il che può essere un segnale di scarsa qualità del risultato.
     * - Avere righe senza prezzo, perché è molto probabile che non siano righe prodotto acquistato, e un risultato con molte righe senza prezzo è meno affidabile.
     * Questi warning aiutano a evidenziare aree specifiche del risultato che potrebbero richiedere attenzione o miglioramenti nella strategia di estrazione, e forniscono indicazioni utili per il debug e l'ottimizzazione delle strategie stesse.
     */
    private function resultWarnings(InvoiceTableExtractionResult $result): array
    {
        $warnings = [];

        $expectedCodeRows = (int) ($result->metadata['expected_code_rows'] ?? 0);

        if ($expectedCodeRows > 0 && $result->rowCount() < $expectedCodeRows) {
            $warnings[] = 'result_missing_expected_code_rows';
        }

        if ($expectedCodeRows >= 3) {
            $coverageRatio = $result->rowCount() / max(1, $expectedCodeRows);

            if ($coverageRatio < 0.8) {
                $warnings[] = 'result_low_code_row_coverage';
            }
        }

        if ($result->pricedRowsCount() < $result->rowCount()) {
            $warnings[] = 'result_contains_unpriced_rows';
        }

        return $warnings;
    }

    /**
     * Restituisce true se la descrizione sembra essere solo metadata tecnico, come EAN, serial number, 
     * IMEI o altre stringhe che non rappresentano una descrizione commerciale di un prodotto acquistato.
     * Questa funzione aiuta a identificare righe che, nonostante abbiano una descrizione, 
     * non forniscono informazioni utili per identificare un prodotto acquistato, 
     * e quindi dovrebbero essere penalizzate nel punteggio.
     */
    private function descriptionLooksLikeTechnicalOnly(string $description): bool
    {
        $normalized = mb_strtolower(trim($description));

        if ($normalized === '') {
            return true;
        }

        return str_starts_with($normalized, 'ean ')
            || str_starts_with($normalized, 's/n ')
            || str_starts_with($normalized, 'sn ')
            || str_starts_with($normalized, 'serial ')
            || str_starts_with($normalized, 'seriale ')
            || str_starts_with($normalized, 'imei ')
            || str_starts_with($normalized, 'barcode ');
    }
}