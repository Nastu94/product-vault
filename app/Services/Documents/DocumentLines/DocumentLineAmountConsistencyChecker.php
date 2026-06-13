<?php

namespace App\Services\Documents\DocumentLines;

/**
 * Verifica diagnostica della coerenza tra quantità, prezzo unitario e totale riga.
 *
 * Questo service NON modifica model, NON corregge importi e NON decide se una riga
 * debba generare un candidato prodotto. Serve solo a produrre una diagnostica
 * tracciabile e riutilizzabile da parser, audit command o metadata futuri.
 */
class DocumentLineAmountConsistencyChecker
{
    /**
     * Tolleranza predefinita in euro.
     *
     * Usiamo 0.02 per gestire piccoli arrotondamenti senza mascherare mismatch reali.
     */
    private const DEFAULT_TOLERANCE = 0.02;

    /**
     * Esegue il controllo diagnostico.
     *
     * @param  int|float|string|null  $quantity
     * @param  int|float|string|null  $unitPrice
     * @param  int|float|string|null  $totalPrice
     * @param  float|null  $tolerance
     * @return array<string, mixed>
     */
    public function check(
        int|float|string|null $quantity,
        int|float|string|null $unitPrice,
        int|float|string|null $totalPrice,
        ?float $tolerance = null
    ): array {
        $tolerance = $tolerance ?? self::DEFAULT_TOLERANCE;

        $normalizedQuantity = $this->normalizeNumber($quantity, 3);
        $normalizedUnitPrice = $this->normalizeNumber($unitPrice, 2);
        $normalizedTotalPrice = $this->normalizeNumber($totalPrice, 2);

        if ($normalizedQuantity === null || $normalizedUnitPrice === null || $normalizedTotalPrice === null) {
            return [
                'checked' => false,
                'is_consistent' => null,
                'expected_total' => null,
                'actual_total' => $normalizedTotalPrice,
                'delta' => null,
                'tolerance' => $tolerance,
                'reason' => 'missing_amount_data',
                'signals' => $this->missingSignals($normalizedQuantity, $normalizedUnitPrice, $normalizedTotalPrice),
            ];
        }

        if ($normalizedQuantity <= 0 || $normalizedUnitPrice <= 0 || $normalizedTotalPrice <= 0) {
            return [
                'checked' => false,
                'is_consistent' => null,
                'expected_total' => null,
                'actual_total' => $normalizedTotalPrice,
                'delta' => null,
                'tolerance' => $tolerance,
                'reason' => 'non_positive_amount_data',
                'signals' => [
                    'non_positive_amount_data',
                ],
            ];
        }

        $expectedTotal = round($normalizedQuantity * $normalizedUnitPrice, 2);
        $delta = round(abs($expectedTotal - $normalizedTotalPrice), 2);
        $isConsistent = $delta <= $tolerance;

        return [
            'checked' => true,
            'is_consistent' => $isConsistent,
            'expected_total' => $expectedTotal,
            'actual_total' => $normalizedTotalPrice,
            'delta' => $delta,
            'tolerance' => $tolerance,
            'reason' => $isConsistent ? 'amounts_consistent' : 'amounts_mismatch',
            'signals' => $isConsistent
                ? ['quantity_x_unit_price_matches_total_price']
                : ['quantity_x_unit_price_differs_from_total_price'],
        ];
    }

    /**
     * Normalizza valori numerici provenienti da model, parser o cast decimal Laravel.
     */
    private function normalizeNumber(int|float|string|null $value, int $precision): ?float
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value);

        if ($normalized === '') {
            return null;
        }

        $normalized = str_replace(' ', '', $normalized);

        if (str_contains($normalized, ',') && ! str_contains($normalized, '.')) {
            $normalized = str_replace(',', '.', $normalized);
        }

        if (! is_numeric($normalized)) {
            return null;
        }

        return round((float) $normalized, $precision);
    }

    /**
     * Segnala quali dati mancano senza trasformare l'assenza in mismatch.
     *
     * @return array<int, string>
     */
    private function missingSignals(?float $quantity, ?float $unitPrice, ?float $totalPrice): array
    {
        $signals = [];

        if ($quantity === null) {
            $signals[] = 'missing_quantity';
        }

        if ($unitPrice === null) {
            $signals[] = 'missing_unit_price';
        }

        if ($totalPrice === null) {
            $signals[] = 'missing_total_price';
        }

        return $signals;
    }
}