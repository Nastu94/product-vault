<?php

namespace App\Services\Documents\InvoiceTableExtraction;

/**
 * Decide se un risultato di estrazione tabella è abbastanza affidabile
 * per essere usato automaticamente dalla pipeline.
 *
 * Questa classe non modifica il database.
 */
class InvoiceTableExtractionQualityGate
{
    /**
     * Soglia minima per accettazione automatica.
     */
    private const MIN_ACCEPTED_SCORE = 80;

    /**
     * Warning che rendono il risultato non accettabile automaticamente.
     */
    private const BLOCKING_WARNINGS = [
        'no_rows_extracted',
        'pending_row_discarded',
        'result_missing_expected_code_rows',
        'result_low_code_row_coverage',
        'result_contains_unpriced_rows',
        'row_without_price',
        'row_with_non_positive_unit_price',
        'row_with_non_positive_total_price',
        'row_amounts_not_coherent',
        'row_description_too_short',
        'row_description_looks_like_technical_metadata',
    ];

    /**
     * Verifica se il risultato dell'estrazione è accettabile per l'uso automatico.
     *
     * @param InvoiceTableExtractionResult $result
     * @return bool
     */
    public function passes(InvoiceTableExtractionResult $result): bool
    {
        return $this->rejectionReasons($result) === [];
    }

    /**
     * Restituisce i motivi per cui il risultato non è accettabile.
     *
     * @param InvoiceTableExtractionResult $result
     * @return string[]
     */
    public function rejectionReasons(InvoiceTableExtractionResult $result): array
    {
        $reasons = [];

        if (! $result->hasRows()) {
            $reasons[] = 'no_rows';
        }

        if ($result->score < self::MIN_ACCEPTED_SCORE) {
            $reasons[] = 'score_below_threshold';
        }

        foreach (self::BLOCKING_WARNINGS as $warning) {
            if (in_array($warning, $result->warnings, true)) {
                $reasons[] = 'blocking_warning:' . $warning;
            }
        }

        if ($result->pricedRowsCount() < $result->rowCount()) {
            $reasons[] = 'not_all_rows_have_price';
        }

        return array_values(array_unique($reasons));
    }
}