<?php

namespace App\Services\Documents\InvoiceTableExtraction;

/**
 * Rappresenta una riga candidata estratta da una tabella fattura.
 *
 * Questa classe NON crea DocumentLine e NON decide se una riga diventerà
 * ProductIdentificationCandidate. Serve solo come formato intermedio,
 * normalizzato e confrontabile tra strategie diverse.
 */
class InvoiceRowCandidate
{
    /**
     * @param string|null $code codice identificativo (es. SKU, codice articolo)
     * @param string $description descrizione testuale (es. "1x Prodotto A")
     * @param array<int, string> $descriptionParts parti della descrizione (es. quantità, unità di misura)
     * @param float|null $quantity quantità numerica (es. 1, 2.5)
     * @param string|null $vatRate aliquota IVA (es. "22%")
     * @param float|null $unitPrice prezzo unitario (es. 10.50)
     * @param float|null $totalPrice prezzo totale (es. 10.50, 21.00)
     * @param float|null $discountAmount importo sconto (es. 0.50)
     * @param array<int, InvoiceRowCandidate> $supportingLines righe di support collegate (es. sconto, riga descrittiva)
     * @param string|null $ean codice EAN (es. "8001234567890")
     * @param string|null $serialNumber numero di serie (es. "SN123456")
     * @param array<int, string> $sourceItemIds ID degli item sorgente (es. righe OCR, linee testo)
     * @param array<int, string> $sourceVisualLineIds ID delle visual line sorgente (es. linee OCR)
     * @param array<int, string> $warnings eventuali warning sulla riga (es. "missing_unit_price")
     * @param array<string, mixed> $metadata eventuali dati aggiuntivi (es. coordinate di estrazione, font usato)
     */
    public function __construct(
        public readonly ?string $code,
        public readonly string $description,
        public readonly array $descriptionParts = [],
        public readonly ?float $quantity = null,
        public readonly ?string $vatRate = null,
        public readonly ?float $unitPrice = null,
        public readonly ?float $totalPrice = null,
        public readonly ?float $discountAmount = null,
        public readonly array $supportingLines = [],
        public readonly ?string $ean = null,
        public readonly ?string $serialNumber = null,
        public readonly array $sourceItemIds = [],
        public readonly array $sourceVisualLineIds = [],
        public readonly array $warnings = [],
        public readonly array $metadata = [],
    ) {
    }

    /**
     * Restituisce la descrizione completa normalizzata.
     */
    public function fullDescription(): string
    {
        $parts = array_values(array_filter([
            $this->description,
            ...$this->descriptionParts,
        ], fn ($part): bool => trim((string) $part) !== ''));

        return trim(preg_replace('/\s+/', ' ', implode(' ', $parts)) ?: implode(' ', $parts));
    }

    /**
     * Dice se la riga ha almeno un prezzo utilizzabile.
     */
    public function hasPrice(): bool
    {
        return $this->unitPrice !== null || $this->totalPrice !== null;
    }

    /**
     * Dice se la riga ha un identificatore tecnico forte.
     */
    public function hasTechnicalIdentifier(): bool
    {
        return $this->ean !== null || $this->serialNumber !== null;
    }

    /**
     * Verifica la coerenza aritmetica quantità × unitario ≈ totale.
     */
    public function hasCoherentAmounts(float $tolerance = 0.05): bool
    {
        if ($this->quantity === null || $this->unitPrice === null || $this->totalPrice === null) {
            return false;
        }

        return abs(($this->quantity * $this->unitPrice) - $this->totalPrice) <= $tolerance;
    }

    /**
     * Crea una copia della riga con warning aggiuntivi.
     */
    public function withWarnings(array $warnings): self
    {
        return new self(
            code: $this->code,
            description: $this->description,
            descriptionParts: $this->descriptionParts,
            quantity: $this->quantity,
            vatRate: $this->vatRate,
            unitPrice: $this->unitPrice,
            totalPrice: $this->totalPrice,
            discountAmount: $this->discountAmount,
            supportingLines: $this->supportingLines,
            ean: $this->ean,
            serialNumber: $this->serialNumber,
            sourceItemIds: $this->sourceItemIds,
            sourceVisualLineIds: $this->sourceVisualLineIds,
            warnings: array_values(array_unique(array_merge($this->warnings, $warnings))),
            metadata: $this->metadata,
        );
    }
}