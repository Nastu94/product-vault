<?php

namespace App\Services\Documents\InvoiceTableExtraction;

use App\Models\Document;

/**
 * Esegue più strategie di estrazione tabellare e sceglie il risultato migliore.
 *
 * Questa classe non crea DocumentLine e non modifica il database.
 * Serve solo a confrontare strategie diverse in modo tracciabile.
 */
class InvoiceTableExtractionManager
{
    /**
     * @param iterable<int, InvoiceTableExtractor> $extractors
     */
    public function __construct(
        private readonly iterable $extractors,
    ) {
    }

    /**
     * Estrae la tabella fattura usando tutte le strategie disponibili
     * e restituisce il risultato migliore.
     */
    public function extractBest(Document $document): InvoiceTableExtractionResult
    {
        $results = $this->extractAll($document);

        if ($results === []) {
            return InvoiceTableExtractionResult::empty(
                strategy: 'none',
                warnings: ['no_extractors_available']
            );
        }

        usort(
            $results,
            fn (InvoiceTableExtractionResult $a, InvoiceTableExtractionResult $b): int => $b->score <=> $a->score
        );

        return $results[0];
    }

    /**
     * Restituisce tutti i risultati, utile per debug/confronto strategie.
     *
     * @return array<int, InvoiceTableExtractionResult>
     */
    public function extractAll(Document $document): array
    {
        $results = [];

        foreach ($this->extractors as $extractor) {
            $result = $extractor->extract($document);

            $results[] = $result;
        }

        return $results;
    }
}