<?php

namespace App\Services\Documents\DocumentLines;

use App\Models\Document;
use App\Models\DocumentLine;

/**
 * Salva nei metadata delle righe documento la diagnostica di coerenza importi.
 *
 * Questo service NON corregge quantità o prezzi e NON modifica candidati prodotto.
 * Aggiunge solo un'informazione diagnostica riutilizzabile in audit, debug o UI futura.
 */
class DocumentLineAmountConsistencyAnnotator
{
    public function __construct(
        private readonly DocumentLineAmountConsistencyChecker $checker,
    ) {
    }

    /**
     * Annota le righe product del documento.
     */
    public function annotateDocument(Document $document): int
    {
        $updated = 0;

        $document->lines()
            ->with('documentLineType')
            ->orderBy('line_number')
            ->get()
            ->each(function (DocumentLine $line) use (&$updated): void {
                if ($line->documentLineType?->code !== 'product') {
                    return;
                }

                $metadata = $line->metadata ?? [];

                $metadata['amount_consistency'] = [
                    'version' => 'document_line_amount_consistency_v1',
                    ...$this->checker->check(
                        quantity: $line->quantity,
                        unitPrice: $line->unit_price,
                        totalPrice: $line->total_price,
                    ),
                ];

                $line->forceFill([
                    'metadata' => $metadata,
                ])->save();

                $updated++;
            });

        return $updated;
    }
}