<?php

namespace App\Services\Documents\ProductUnderstanding;

class ProductLineAnalysisResult
{
    /**
     * Risultato strutturato dell'analisi semantica di una riga documento.
     */
    public function __construct(
        public readonly string $version,
        public readonly string $lineType,
        public readonly int $registerableScore,
        public readonly int $nonProductScore,
        public readonly bool $hardExcluded,
        public readonly ?string $suggestedName,
        public readonly ?string $suggestedCategory,
        public readonly ?string $brandCandidate,
        public readonly ?string $modelCandidate,
        public readonly ?string $eanCandidate,
        public readonly ?string $serialCandidate,
        public readonly array $signals = [],
        public readonly array $negativeSignals = [],
        public readonly array $warnings = [],
        public readonly array $scoreBreakdown = [],
    ) {
    }

    /**
     * Indica se l'analisi considera la riga un prodotto registrabile.
     *
     * In questa prima patch il risultato è salvato nei metadata, ma non sostituisce
     * ancora il gate storico del ProductCandidateGenerator.
     */
    public function shouldGenerateCandidate(int $threshold = 65): bool
    {
        if ($this->hardExcluded) {
            return false;
        }

        if (! in_array($this->lineType, ['durable_product', 'accessory'], true)) {
            return false;
        }

        return $this->registerableScore >= $threshold
            && $this->registerableScore > $this->nonProductScore;
    }

    /**
     * Confidenza suggerita per il candidato prodotto.
     */
    public function candidateConfidenceScore(): int
    {
        if ($this->hardExcluded) {
            return 0;
        }

        $score = $this->registerableScore - max(0, $this->nonProductScore - 20);

        return max(0, min(100, $score));
    }

    /**
     * Payload salvabile nei metadata del candidato.
     */
    public function toMetadata(): array
    {
        return [
            'version' => $this->version,
            'line_type' => $this->lineType,
            'registerable_score' => $this->registerableScore,
            'non_product_score' => $this->nonProductScore,
            'hard_excluded' => $this->hardExcluded,
            'should_generate_candidate' => $this->shouldGenerateCandidate(),
            'suggested_name' => $this->suggestedName,
            'suggested_category' => $this->suggestedCategory,
            'brand_candidate' => $this->brandCandidate,
            'model_candidate' => $this->modelCandidate,
            'ean_candidate' => $this->eanCandidate,
            'serial_candidate' => $this->serialCandidate,
            'signals' => array_values(array_unique($this->signals)),
            'negative_signals' => array_values(array_unique($this->negativeSignals)),
            'warnings' => array_values(array_unique($this->warnings)),
            'score_breakdown' => $this->scoreBreakdown,
        ];
    }
}