<?php

namespace App\Services\Documents;

use App\Models\Document;
use App\Models\DocumentTextExtraction;

class LayoutAwareTotalAmountExtractor
{
    /**
     * Prova a estrarre il totale documento usando coordinate OCR.
     *
     * Questo extractor non sostituisce il parser testuale:
     * viene usato prima del fallback raw_text quando sono disponibili ocr_items.
     */
    public function extract(Document $document): ?float
    {
        $extraction = DocumentTextExtraction::query()
            ->where('document_id', $document->id)
            ->where('status', 'completed')
            ->whereNotNull('metadata')
            ->latest('id')
            ->first();

        if (! $extraction) {
            return null;
        }

        $items = $extraction->metadata['ocr_items'] ?? [];

        if (! is_array($items) || empty($items)) {
            return null;
        }

        return $this->extractFromItems($items);
    }

    /**
     * Estrae il totale cercando una label forte e l'importo allineato a destra.
     */
    private function extractFromItems(array $items): ?float
    {
        $strongTotalItems = array_values(array_filter(
            $items,
            fn (array $item): bool => $this->itemLooksLikeStrongTotal($item)
        ));

        if (empty($strongTotalItems)) {
            return null;
        }

        $candidates = [];

        foreach ($strongTotalItems as $labelItem) {
            /*
            |--------------------------------------------------------------------------
            | Caso 1: label e importo nello stesso item OCR
            |--------------------------------------------------------------------------
            |
            | Esempio possibile:
            | "TOTALE FATTURA 597,99"
            |
            */
            foreach ($this->extractAmountsFromText((string) ($labelItem['text'] ?? '')) as $amount) {
                $candidates[] = [
                    'amount' => $amount,
                    'score' => 100,
                    'reason' => 'same_item',
                ];
            }

            /*
            |--------------------------------------------------------------------------
            | Caso 2: importo a destra della label sulla stessa fascia visiva
            |--------------------------------------------------------------------------
            |
            | Esempio reale:
            | TOTALE FATTURA          597,99
            |
            | In raw_text può finire in ordine sbagliato, ma le coordinate restano
            | abbastanza affidabili.
            |
            */
            foreach ($items as $amountItem) {
                if ($amountItem === $labelItem) {
                    continue;
                }

                if (! $this->itemIsOnSamePage($labelItem, $amountItem)) {
                    continue;
                }

                if (! $this->itemIsOnSameVisualBand($labelItem, $amountItem)) {
                    continue;
                }

                if (! $this->itemIsToTheRightOf($labelItem, $amountItem)) {
                    continue;
                }

                $amounts = $this->extractAmountsFromText((string) ($amountItem['text'] ?? ''));

                if (empty($amounts)) {
                    continue;
                }

                foreach ($amounts as $amount) {
                    $candidates[] = [
                        'amount' => $amount,
                        'score' => $this->scoreRightAlignedAmount($labelItem, $amountItem),
                        'reason' => 'right_aligned_same_band',
                    ];
                }
            }
        }

        if (empty($candidates)) {
            return null;
        }

        usort($candidates, function (array $a, array $b): int {
            return $b['score'] <=> $a['score'];
        });

        return $candidates[0]['amount'];
    }

    /**
     * Riconosce label forti di totale documento.
     */
    private function itemLooksLikeStrongTotal(array $item): bool
    {
        $text = mb_strtolower(trim((string) ($item['text'] ?? '')));

        if ($text === '') {
            return false;
        }

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
            if (str_contains($text, $signal)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Verifica che label e importo siano sulla stessa pagina.
     */
    private function itemIsOnSamePage(array $labelItem, array $amountItem): bool
    {
        return (int) ($labelItem['page'] ?? 1) === (int) ($amountItem['page'] ?? 1);
    }

    /**
     * Verifica se due item sono sulla stessa fascia visiva orizzontale.
     */
    private function itemIsOnSameVisualBand(array $labelItem, array $amountItem): bool
    {
        $labelCenterY = $this->floatOrNull($labelItem['center_y'] ?? null);
        $amountCenterY = $this->floatOrNull($amountItem['center_y'] ?? null);

        if ($labelCenterY === null || $amountCenterY === null) {
            return false;
        }

        $labelHeight = $this->floatOrNull($labelItem['height'] ?? null) ?? 0.0;
        $amountHeight = $this->floatOrNull($amountItem['height'] ?? null) ?? 0.0;

        $threshold = max(14.0, max($labelHeight, $amountHeight) * 0.75);

        return abs($labelCenterY - $amountCenterY) <= $threshold;
    }

    /**
     * Verifica se l'importo è a destra della label totale.
     */
    private function itemIsToTheRightOf(array $labelItem, array $amountItem): bool
    {
        $labelX2 = $this->floatOrNull($labelItem['x2'] ?? null);
        $amountX1 = $this->floatOrNull($amountItem['x1'] ?? null);

        if ($labelX2 === null || $amountX1 === null) {
            return false;
        }

        /*
        |----------------------------------------------------------------------
        | Tolleranza anti-skew
        |----------------------------------------------------------------------
        |
        | Permette piccoli disallineamenti dovuti a foto inclinate o bbox non
        | perfette, ma mantiene il vincolo principale: l'importo deve stare
        | a destra della label, non a sinistra come importi IVA.
        |
        */
        return $amountX1 >= ($labelX2 - 30.0);
    }

    /**
     * Assegna un punteggio agli importi candidati.
     */
    private function scoreRightAlignedAmount(array $labelItem, array $amountItem): int
    {
        $labelCenterY = $this->floatOrNull($labelItem['center_y'] ?? null) ?? 0.0;
        $amountCenterY = $this->floatOrNull($amountItem['center_y'] ?? null) ?? 0.0;

        $verticalDistance = abs($labelCenterY - $amountCenterY);

        $amountX1 = $this->floatOrNull($amountItem['x1'] ?? null) ?? 0.0;
        $labelX2 = $this->floatOrNull($labelItem['x2'] ?? null) ?? 0.0;

        $horizontalDistance = max(0.0, $amountX1 - $labelX2);

        $score = 95;
        $score -= min(30, (int) round($verticalDistance));
        $score -= min(20, (int) round($horizontalDistance / 80));

        return max(1, $score);
    }

    /**
     * Estrae importi in formato italiano/europeo.
     */
    private function extractAmountsFromText(string $text): array
    {
        preg_match_all(
            '/(?<!\d)(?:€\s*)?(?<amount>\d{1,3}(?:[.\s]\d{3})*,\d{2}|\d+,\d{2})(?!\d)/u',
            $text,
            $matches
        );

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
     * Converte un valore numerico se valido.
     */
    private function floatOrNull(mixed $value): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }
}