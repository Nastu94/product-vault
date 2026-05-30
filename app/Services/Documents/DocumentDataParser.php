<?php

namespace App\Services\Documents;

use App\Models\Currency;
use App\Models\Document;
use Carbon\Carbon;

class DocumentDataParser
{
    /**
     * Il parser principale per estrarre dati strutturati dal testo del documento.
     * Usa una combinazione di regex, euristiche e logica specifica per cercare
     * di identificare data acquisto, totale documento e valuta.
     * Il parser è "layout-aware" grazie al supporto di LayoutAwareTotalAmountExtractor, 
     * che cerca di identificare il totale usando anche le coordinate OCR quando disponibili.
     * Il parser non è perfetto e non deve essere perfetto: l'obiettivo è estrarre dati utili per una buona parte dei documenti, 
     * migliorando gradualmente con iterazioni future.
     * Il parser non deve essere rigido: è meglio estrarre un dato anche se non perfetto piuttosto che non estrarre nulla. 
     * L'obiettivo è aiutare l'utente a precompilare i campi più importanti, 
     * lasciando sempre la possibilità di correggere manualmente.
     */
    public function __construct(
        private readonly LayoutAwareTotalAmountExtractor $layoutAwareTotalAmountExtractor
    ) {
    }

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
        $totalAmount = $this->layoutAwareTotalAmountExtractor->extract($document)
            ?? $this->extractTotalAmount($text);
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
            | Portiamo a "parsed" solo i documenti che sono ancora nelle fasi iniziali
            | della pipeline. Non dobbiamo retrocedere documenti già in revisione,
            | già collegati a prodotto, falliti o non supportati.
            |
            */
            if ($this->shouldMoveDocumentToParsed($document)) {
                $updates['status'] = 'parsed';
            }

            $document->update($updates);
        }

        return $document->refresh();
    }

    /**
     * Decide se il parser può portare il documento allo stato parsed.
     */
    private function shouldMoveDocumentToParsed(Document $document): bool
    {
        return in_array($document->status, [
            'uploaded',
            'text_extracted',
            'classified',
        ], true);
    }

    /**
     * Estrae una data candidata dal testo.
     *
     * Supporta pattern comuni:
     * - 08/02/2026
     * - 08-02-2026
     * - 2026-02-08
     * - 06/02/16 13:45
     * - 06/02/1613:45, caso OCR dove "16" e "13:45" sono attaccati.
     */
    private function extractDate(string $text): ?Carbon
    {
        /*
        |--------------------------------------------------------------------------
        | Caso OCR: data con anno a due cifre attaccato all'orario
        |--------------------------------------------------------------------------
        |
        | Esempio reale:
        | 06/02/1613:45
        |
        | Qui non vogliamo interpretare 1613 come anno.
        | Interpretiamo invece:
        | giorno = 06
        | mese = 02
        | anno breve = 16
        | ora = 13:45
        |
        */
        if (preg_match('/\b(?<day>\d{1,2})[\/\-.](?<month>\d{1,2})[\/\-.]?(?<year>\d{2})(?=\d{1,2}:\d{2}\b)/u', $text, $matches)) {
            $date = $this->buildDateFromParts(
                day: (int) $matches['day'],
                month: (int) $matches['month'],
                year: $this->normalizeTwoDigitYear((int) $matches['year']),
            );

            if ($date) {
                return $date;
            }
        }

        $patterns = [
            '/\b(?<day>\d{1,2})[\/\-.](?<month>\d{1,2})[\/\-.](?<year>\d{4})\b/u',
            '/\b(?<year>\d{4})[\/\-.](?<month>\d{1,2})[\/\-.](?<day>\d{1,2})\b/u',
            '/\b(?<day>\d{1,2})[\/\-.](?<month>\d{1,2})[\/\-.](?<year>\d{2})\b/u',
        ];

        foreach ($patterns as $pattern) {
            if (! preg_match($pattern, $text, $matches)) {
                continue;
            }

            $year = (int) $matches['year'];

            if ($year < 100) {
                $year = $this->normalizeTwoDigitYear($year);
            }

            $date = $this->buildDateFromParts(
                day: (int) $matches['day'],
                month: (int) $matches['month'],
                year: $year,
            );

            if ($date) {
                return $date;
            }
        }

        return null;
    }

    /**
     * Converte un anno a due cifre in anno completo.
     *
     * Regola MVP:
     * - 00-49 => 2000-2049
     * - 50-99 => 1950-1999
     */
    private function normalizeTwoDigitYear(int $year): int
    {
        return $year <= 49
            ? 2000 + $year
            : 1900 + $year;
    }

    /**
     * Crea una data solo se valida e plausibile.
     */
    private function buildDateFromParts(int $day, int $month, int $year): ?Carbon
    {
        if (! checkdate($month, $day, $year)) {
            return null;
        }

        /*
        |--------------------------------------------------------------------------
        | Filtro anti-OCR
        |--------------------------------------------------------------------------
        |
        | Evita anni assurdi come 1613, 3024, ecc.
        | Per Product Vault ha senso accettare acquisti moderni.
        |
        */
        $minimumYear = 1990;
        $maximumYear = now()->addYear()->year;

        if ($year < $minimumYear || $year > $maximumYear) {
            return null;
        }

        return Carbon::createFromDate($year, $month, $day)->startOfDay();
    }

    /**
     * Estrae il totale candidato.
     *
     * Strategia:
     * - per righe "totale complessivo" usiamo una logica più prudente;
     * - evitiamo di scambiare IVA, pagamenti o sconti per totale documento;
     * - fallback: massimo importo nel testo.
     */
    private function extractTotalAmount(string $text): ?float
    {
        $lines = preg_split('/\R/u', $text) ?: [];

        /*
        |--------------------------------------------------------------------------
        | Totale fattura/documento sulla stessa riga
        |--------------------------------------------------------------------------
        |
        | Se una riga forte come "TOTALE FATTURA 597,99" contiene già un importo,
        | quella riga è più affidabile di importi vicini come acconti, netto a pagare,
        | IVA o pagamenti.
        |
        */
        foreach ($lines as $line) {
            $normalizedLine = mb_strtolower(trim($line));

            if (! $this->lineLooksLikeStrongTotal($normalizedLine)) {
                continue;
            }

            $amounts = $this->extractAmountsFromText($line);

            if (! empty($amounts)) {
                return end($amounts);
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Totale complessivo / totale documento
        |--------------------------------------------------------------------------
        |
        | OCR e PDF parser possono mettere l'importo:
        | - sulla stessa riga;
        | - nella riga precedente;
        | - nella riga successiva;
        | - oppure separato da una riga sporca.
        |
        | Per questo analizziamo una finestra locale attorno alla riga totale,
        | ma scartiamo righe chiaramente riferite a IVA, pagamento o sconto.
        |
        */
        foreach ($lines as $index => $line) {
            $normalizedLine = mb_strtolower(trim($line));

            if (! $this->lineLooksLikeStrongTotal($normalizedLine)) {
                continue;
            }

            $candidateAmounts = [];

            for ($offset = -4; $offset <= 4; $offset++) {
                $nearbyIndex = $index + $offset;

                if (! isset($lines[$nearbyIndex])) {
                    continue;
                }

                $nearbyLine = trim($lines[$nearbyIndex]);
                $nearbyNormalizedLine = mb_strtolower($nearbyLine);

                if ($this->lineLooksLikeAmountToIgnoreNearTotal($nearbyNormalizedLine)) {
                    continue;
                }

                if ($this->amountLineHasIgnoredContextNearTotal($lines, $nearbyIndex, $index)) {
                    continue;
                }

                foreach ($this->extractAmountsFromText($nearbyLine) as $amount) {
                    $candidateAmounts[] = [
                        'amount' => $amount,
                        'distance' => abs($offset),
                        'offset' => $offset,
                    ];
                }
            }

            if (! empty($candidateAmounts)) {
                usort($candidateAmounts, function (array $a, array $b): int {
                    /*
                    |--------------------------------------------------------------------------
                    | Priorità importi vicino a una riga forte di totale
                    |--------------------------------------------------------------------------
                    |
                    | Ordine:
                    | 1. distanza minore dalla riga "TOTALE FATTURA / TOTALE COMPLESSIVO";
                    | 2. a parità di distanza, preferiamo la riga precedente;
                    | 3. infine fallback sull'ordine naturale.
                    |
                    | Questo gestisce OCR a colonne come:
                    | 597,99
                    | TOTALE FATTURA
                    | 30,36
                    |
                    */
                    $distanceComparison = $a['distance'] <=> $b['distance'];

                    if ($distanceComparison !== 0) {
                        return $distanceComparison;
                    }

                    $aDirectionPriority = $a['offset'] < 0 ? 0 : 1;
                    $bDirectionPriority = $b['offset'] < 0 ? 0 : 1;

                    return $aDirectionPriority <=> $bDirectionPriority;
                });

                return $candidateAmounts[0]['amount'];
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Altri totali meno specifici
        |--------------------------------------------------------------------------
        |
        | Manteniamo compatibilità con DDT/fatture già funzionanti:
        | Tot. documento €
        | 2.080,00
        */
        $candidateAmounts = [];

        foreach ($lines as $index => $line) {
            $normalizedLine = mb_strtolower(trim($line));

            if (! $this->lineLooksLikeTotal($normalizedLine)) {
                continue;
            }

            $window = implode(' ', array_slice($lines, $index, 3));

            foreach ($this->extractAmountsFromText($window) as $amount) {
                $candidateAmounts[] = $amount;
            }
        }

        if (! empty($candidateAmounts)) {
            return max($candidateAmounts);
        }

        /*
        |--------------------------------------------------------------------------
        | Fallback
        |--------------------------------------------------------------------------
        */
        $allAmounts = $this->extractAmountsFromText($text);

        if (empty($allAmounts)) {
            return null;
        }

        return max($allAmounts);
    }

    /**
     * Verifica se una riga importo vicina al totale appartiene in realtà
     * a subtotale, IVA, pagamento, sconto o altre righe da ignorare.
     */
    private function amountLineHasIgnoredContextNearTotal(
        array $lines, int $amountLineIndex, int $strongTotalLineIndex
    ): bool
    {
        foreach ([-1, 0, 1] as $offset) {
            $index = $amountLineIndex + $offset;

            if (! isset($lines[$index])) {
                continue;
            }

            if ($index === $strongTotalLineIndex) {
                continue;
            }

            $line = mb_strtolower(trim($lines[$index]));

            if ($line === '') {
                continue;
            }

            if ($this->lineLooksLikeStrongTotal($line)) {
                continue;
            }

            if ($this->lineLooksLikeAmountToIgnoreNearTotal($line)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Riconosce righe forti di totale documento.
     */
    private function lineLooksLikeStrongTotal(string $line): bool
    {
        $signals = [
            'totale complessivo',
            'totale documento',
            'tot. documento',
            'tot documento',
            'totale fattura',
            'tot. fattura',
            'importo totale',
        ];

        foreach ($signals as $signal) {
            if (str_contains($line, $signal)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Esclude importi vicini al totale che non sono il totale documento.
     */
    private function lineLooksLikeAmountToIgnoreNearTotal(string $line): bool
    {
        $signals = [
            'di cui iva',
            'iva',
            'pagamento',
            'pagamento non riscosso',
            'pagamento elettronico',
            'pasamento elettronico',
            'sconto',
            'sconto a pagare',
            'resto',
            'subtotale',
            'acconto',
            'acconto gia pagato',
            'acconto già pagato',
            'netto a pagare',
            'totale iva',
            'totale imponibile',
            'riepilogo iva',
        ];

        foreach ($signals as $signal) {
            if (str_contains($line, $signal)) {
                return true;
            }
        }

        return false;
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