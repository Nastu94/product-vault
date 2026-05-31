<?php

namespace App\Services\Documents\InvoiceTableExtraction;

/**
 * Risultato di una strategia di estrazione tabella fattura.
 *
 * Più strategie possono produrre un InvoiceTableExtractionResult.
 * Lo scorer sceglierà il risultato più affidabile.
 */
class InvoiceTableExtractionResult
{
    /**
     * @param array<int, InvoiceRowCandidate> $rows
     */
    public function __construct(
        public readonly string $strategy,
        public readonly array $rows = [],
        public readonly int $score = 0,
        public readonly array $warnings = [],
        public readonly array $metadata = [],
    ) {
    }

    /**
     * Risultato vuoto per strategie non applicabili.
     */
    public static function empty(string $strategy, array $warnings = []): self
    {
        return new self(
            strategy: $strategy,
            rows: [],
            score: 0,
            warnings: $warnings,
        );
    }

    /**
     * Crea una copia del risultato con uno score aggiornato.
     */
    public function withScore(int $score, array $warnings = []): self
    {
        return new self(
            strategy: $this->strategy,
            rows: $this->rows,
            score: max(0, min(100, $score)),
            warnings: array_values(array_unique(array_merge($this->warnings, $warnings))),
            metadata: $this->metadata,
        );
    }

    /**
     * Numero di righe candidate.
     */
    public function rowCount(): int
    {
        return count($this->rows);
    }

    /**
     * Dice se il risultato contiene almeno una riga utilizzabile.
     */
    public function hasRows(): bool
    {
        return $this->rowCount() > 0;
    }

    /**
     * Righe con prezzo.
     */
    public function pricedRowsCount(): int
    {
        return count(array_filter(
            $this->rows,
            fn (InvoiceRowCandidate $row): bool => $row->hasPrice()
        ));
    }

    /**
     * Righe con EAN o seriale.
     */
    public function identifiedRowsCount(): int
    {
        return count(array_filter(
            $this->rows,
            fn (InvoiceRowCandidate $row): bool => $row->hasTechnicalIdentifier()
        ));
    }
}