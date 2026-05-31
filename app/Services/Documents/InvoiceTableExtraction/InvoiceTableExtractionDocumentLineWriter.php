<?php

namespace App\Services\Documents\InvoiceTableExtraction;

use App\Models\Document;
use App\Models\DocumentLine;

/**
 * Converte un risultato di estrazione tabellare in DocumentLine.
 *
 * Questa classe non decide se il risultato è affidabile:
 * prima deve passare da InvoiceTableExtractionQualityGate.
 */
class InvoiceTableExtractionDocumentLineWriter
{   
    /**
     * Scrive le righe estratte nel database, associandole al documento e al tipo di linea specificati.
     *
     * @param Document $document
     * @param int|null $lineTypeId
     * @param InvoiceTableExtractionResult $result
     * @return int Il numero di righe create
     */
    public function write(Document $document, ?int $lineTypeId, InvoiceTableExtractionResult $result): int
    {
        $created = 0;
        $nextLineNumber = $this->nextLineNumber($document);

        foreach ($result->rows as $row) {
            DocumentLine::query()->create([
                'document_id' => $document->id,
                'document_line_type_id' => $lineTypeId,
                'line_number' => $nextLineNumber + $created,
                'raw_text' => $this->buildRawText($row),
                'description' => $row->description,
                'quantity' => $row->quantity,
                'unit_price' => $row->unitPrice,
                'total_price' => $row->totalPrice,
                'confidence_score' => $this->lineConfidenceScore($result, $row),
                'metadata' => [
                    'parser' => 'invoice_table_extraction_v1',
                    'mode' => $result->strategy,
                    'invoice_code' => $row->code,
                    'product_code_candidate' => $row->ean ?: $row->code,
                    'serial_number_candidate' => $row->serialNumber,
                    'discount_amount' => $row->discountAmount,
                    'vat_rate' => $row->vatRate,
                    'supporting_lines' => array_values(array_unique(array_merge(
                        $row->descriptionParts,
                        $row->supportingLines
                    ))),
                    'source_item_ids' => $row->sourceItemIds,
                    'source_visual_line_ids' => $row->sourceVisualLineIds,
                    'extraction_strategy' => $result->strategy,
                    'extraction_score' => $result->score,
                    'extraction_warnings' => $result->warnings,
                    'row_warnings' => $row->warnings,
                    'row_metadata' => $row->metadata,
                ],
            ]);

            $created++;
        }

        return $created;
    }

    /**
     * Calcola il numero di linea da assegnare alla prossima riga creata, basandosi
     * sul numero di linea massimo già presente per il documento.
     */
    private function nextLineNumber(Document $document): int
    {
        $maxLineNumber = DocumentLine::query()
            ->where('document_id', $document->id)
            ->max('line_number');

        return ((int) $maxLineNumber) + 1;
    }

    /**
     * Costruisce il testo grezzo da salvare in DocumentLine->raw_text, combinando
     * codice, descrizione, parti della descrizione, linee di supporto e colonne di importo.
     * Le parti vuote o null vengono ignorate, e gli spazi multipli vengono ridotti a uno.
     */
    private function buildRawText(InvoiceRowCandidate $row): string
    {
        $parts = array_filter([
            $row->code,
            $row->description,
            ...$row->descriptionParts,
            ...$row->supportingLines,
            $this->formatAmountColumns($row),
        ], fn ($part): bool => trim((string) $part) !== '');

        return trim(preg_replace('/\s+/', ' ', implode(' ', $parts)) ?: implode(' ', $parts));
    }

    /**
     * Combina quantità, aliquota IVA, prezzo unitario e prezzo totale in un'unica stringa.
     * Solo le parti presenti vengono incluse, separate da spazi.
     */
    private function formatAmountColumns(InvoiceRowCandidate $row): string
    {
        $parts = array_filter([
            $row->quantity !== null ? (string) $row->quantity : null,
            $row->vatRate,
            $row->unitPrice !== null ? number_format($row->unitPrice, 2, '.', '') : null,
            $row->totalPrice !== null ? number_format($row->totalPrice, 2, '.', '') : null,
        ], fn ($part): bool => $part !== null && trim((string) $part) !== '');

        return implode(' ', $parts);
    }

    /**
     * Calcola un punteggio di confidenza per la riga, basato sul punteggio generale
     * e su eventuali warning specifici della riga.
     */
    private function lineConfidenceScore(InvoiceTableExtractionResult $result, InvoiceRowCandidate $row): int
    {
        $score = $result->score;

        if (! empty($row->warnings)) {
            $score -= 10;
        }

        if (! $row->hasPrice()) {
            $score -= 20;
        }

        if ($row->hasTechnicalIdentifier()) {
            $score += 5;
        }

        return max(0, min(100, $score));
    }
}