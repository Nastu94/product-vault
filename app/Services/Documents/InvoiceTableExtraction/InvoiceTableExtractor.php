<?php

namespace App\Services\Documents\InvoiceTableExtraction;

use App\Models\Document;

/**
 * Contratto comune per ogni strategia di estrazione tabellare fattura.
 *
 * Esempi futuri:
 * - testo digitale;
 * - visual lines OCR;
 * - geometria OCR items;
 * - Python layout reconstruction.
 */
interface InvoiceTableExtractor
{
    public function extract(Document $document): InvoiceTableExtractionResult;
}