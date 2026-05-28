<?php

namespace App\Services\Documents;

use App\Models\Currency;
use App\Models\Document;
use Carbon\Carbon;

class DocumentDataParser
{
    /**
     * Estrae dati base dal testo del documento.
     *
     * MVP iniziale:
     * - data candidata
     * - totale/importo candidato
     * - valuta EUR se presente o se il simbolo € è rilevato
     *
     * Non crea ancora righe documento e non crea prodotti.
     */
    public function parse(Document $document): Document
    {
        $text = trim((string) $document->raw_text);

        if ($text === '') {
            return $document;
        }

        $purchaseDate = $this->extractDate($text);
        $totalAmount = $this->extractTotalAmount($text);
        $currencyId = $this->detectCurrencyId($text);

        $updates = [];

        if ($purchaseDate) {
            $updates['purchase_date'] = $purchaseDate;
        }

        if ($totalAmount !== null) {
            $updates['total_amount'] = $totalAmount;
        }

        if ($currencyId) {
            $updates['currency_id'] = $currencyId;
        }

        if (! empty($updates)) {
            /*
            |--------------------------------------------------------------------------
            | Stato parsed
            |--------------------------------------------------------------------------
            |
            | Il documento è già stato classificato. Se troviamo almeno un dato utile,
            | lo portiamo allo stato parsed. La revisione manuale arriverà dopo.
            |
            */
            $updates['status'] = 'parsed';

            $document->update($updates);
        }

        return $document->refresh();
    }

    /**
     * Estrae una data candidata dal testo.
     *
     * Supporta pattern comuni:
     * - 08/02/2026
     * - 08-02-2026
     * - 2026-02-08
     */
    private function extractDate(string $text): ?Carbon
    {
        $patterns = [
            '/\b(?<day>\d{1,2})[\/\-.](?<month>\d{1,2})[\/\-.](?<year>\d{4})\b/',
            '/\b(?<year>\d{4})[\/\-.](?<month>\d{1,2})[\/\-.](?<day>\d{1,2})\b/',
        ];

        foreach ($patterns as $pattern) {
            if (! preg_match($pattern, $text, $matches)) {
                continue;
            }

            $day = (int) $matches['day'];
            $month = (int) $matches['month'];
            $year = (int) $matches['year'];

            if (! checkdate($month, $day, $year)) {
                continue;
            }

            return Carbon::createFromDate($year, $month, $day)->startOfDay();
        }

        return null;
    }

    /**
     * Estrae il totale candidato.
     *
     * Strategia prudente:
     * - cerca righe con parole forti: totale, tot. documento, importo totale
     * - prende l'ultimo importo trovato vicino a quelle righe
     */
    private function extractTotalAmount(string $text): ?float
    {
        $lines = preg_split('/\R/u', $text) ?: [];

        $candidateAmounts = [];

        foreach ($lines as $index => $line) {
            $normalizedLine = mb_strtolower(trim($line));

            if (! $this->lineLooksLikeTotal($normalizedLine)) {
                continue;
            }

            /*
            |--------------------------------------------------------------------------
            | Finestra locale
            |--------------------------------------------------------------------------
            |
            | Alcuni PDF estratti mettono "Tot. documento €" su una riga
            | e l'importo nella riga successiva. Per questo guardiamo anche
            | una piccola finestra di righe successive.
            |
            */
            $window = implode(' ', array_slice($lines, $index, 3));

            foreach ($this->extractAmountsFromText($window) as $amount) {
                $candidateAmounts[] = $amount;
            }
        }

        if (! empty($candidateAmounts)) {
            return end($candidateAmounts);
        }

        /*
        |--------------------------------------------------------------------------
        | Fallback prudente
        |--------------------------------------------------------------------------
        |
        | Se non troviamo una riga "totale", prendiamo il massimo importo nel testo.
        | Non è perfetto, ma spesso è corretto in documenti semplici.
        |
        */
        $allAmounts = $this->extractAmountsFromText($text);

        if (empty($allAmounts)) {
            return null;
        }

        return max($allAmounts);
    }

    /**
     * Capisce se una riga sembra contenere il totale.
     */
    private function lineLooksLikeTotal(string $line): bool
    {
        $signals = [
            'totale',
            'tot. documento',
            'tot documento',
            'totale documento',
            'importo totale',
            'tot. fattura',
            'totale fattura',
        ];

        foreach ($signals as $signal) {
            if (str_contains($line, $signal)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Estrae importi in formato italiano/europeo.
     *
     * Esempi:
     * - € 2.080,00
     * - 2.080,00
     * - 1040,00
     */
    private function extractAmountsFromText(string $text): array
    {
        preg_match_all('/(?<!\d)(?:€\s*)?(?<amount>\d{1,3}(?:[.\s]\d{3})*,\d{2}|\d+,\d{2})(?!\d)/u', $text, $matches);

        $amounts = [];

        foreach ($matches['amount'] ?? [] as $rawAmount) {
            $normalized = str_replace(['.', ' '], '', $rawAmount);
            $normalized = str_replace(',', '.', $normalized);

            if (! is_numeric($normalized)) {
                continue;
            }

            $amounts[] = (float) $normalized;
        }

        return $amounts;
    }

    /**
     * Rileva la valuta.
     */
    private function detectCurrencyId(string $text): ?int
    {
        if (! str_contains($text, '€') && ! str_contains(mb_strtolower($text), 'eur')) {
            return null;
        }

        return Currency::query()
            ->where('code', 'EUR')
            ->value('id');
    }
}