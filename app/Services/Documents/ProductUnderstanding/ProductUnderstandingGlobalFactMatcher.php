<?php

namespace App\Services\Documents\ProductUnderstanding;

use App\Models\ProductUnderstandingGlobalFact;
use Illuminate\Support\Str;

class ProductUnderstandingGlobalFactMatcher
{
    private const VERSION = 'product_understanding_global_fact_matcher_v1';

    /**
     * Cerca conoscenza globale aggregata per un candidato.
     *
     * MVP:
     * - match solo EAN-based;
     * - non decide se generare o escludere candidati;
     * - non sovrascrive automaticamente il nome OCR;
     * - restituisce suggerimenti spiegabili da salvare nei metadata.
     */
    public function match(
        ?string $eanCode,
        ?string $candidateName = null,
    ): array {
        $eanCode = trim((string) $eanCode);

        if ($eanCode === '') {
            return $this->emptyResult(reason: 'missing_ean');
        }

        $fact = ProductUnderstandingGlobalFact::query()
            ->where('fact_type', 'ean')
            ->where('fact_key', hash('sha256', $eanCode))
            ->first();

        if (! $fact) {
            return $this->emptyResult(reason: 'global_fact_not_found');
        }

        $candidateNormalizedName = $this->normalizeName((string) $candidateName);
        $canonicalNormalizedName = $this->normalizeName((string) $fact->canonical_name);

        $nameSimilarity = $this->jaccardSimilarity(
            leftTokens: $this->tokens($candidateNormalizedName ?? ''),
            rightTokens: $this->tokens($canonicalNormalizedName ?? ''),
        );

        $nameDiffersFromCanonical = $candidateNormalizedName !== null
            && $canonicalNormalizedName !== null
            && $candidateNormalizedName !== $canonicalNormalizedName;

        return [
            'version' => self::VERSION,
            'matched' => true,
            'match_type' => 'ean',
            'match_key' => 'sha256:' . hash('sha256', $eanCode),

            /*
            |--------------------------------------------------------------------------
            | Dati globali aggregati
            |--------------------------------------------------------------------------
            */
            'canonical_name' => $fact->canonical_name,
            'suggested_category' => $fact->suggested_category,
            'suggested_line_type' => $fact->suggested_line_type,
            'seen_count' => $fact->seen_count,
            'confirmed_count' => $fact->confirmed_count,
            'ignored_count' => $fact->ignored_count,
            'global_registration_rate' => $fact->global_registration_rate,
            'global_product_confidence_score' => $fact->global_product_confidence_score,

            /*
            |--------------------------------------------------------------------------
            | Confronto nome OCR/candidato vs nome globale
            |--------------------------------------------------------------------------
            */
            'candidate_name' => $candidateName,
            'candidate_normalized_name' => $candidateNormalizedName,
            'canonical_normalized_name' => $canonicalNormalizedName,
            'candidate_canonical_name_similarity' => round($nameSimilarity, 3),
            'candidate_name_differs_from_canonical' => $nameDiffersFromCanonical,

            /*
            |--------------------------------------------------------------------------
            | Suggerimenti non vincolanti
            |--------------------------------------------------------------------------
            */
            'suggested_bias' => $this->suggestedBias($fact),
            'review_hint' => $this->reviewHint(
                fact: $fact,
                nameDiffersFromCanonical: $nameDiffersFromCanonical,
                nameSimilarity: $nameSimilarity,
            ),

            'signals' => $this->signals(
                fact: $fact,
                nameDiffersFromCanonical: $nameDiffersFromCanonical,
                nameSimilarity: $nameSimilarity,
            ),
        ];
    }

    /**
     * Risultato vuoto ma strutturato.
     */
    private function emptyResult(string $reason): array
    {
        return [
            'version' => self::VERSION,
            'matched' => false,
            'reason' => $reason,
            'match_type' => null,
            'canonical_name' => null,
            'suggested_category' => null,
            'suggested_line_type' => null,
            'seen_count' => 0,
            'confirmed_count' => 0,
            'ignored_count' => 0,
            'global_registration_rate' => null,
            'global_product_confidence_score' => 0,
            'suggested_bias' => 'none',
            'review_hint' => null,
            'signals' => [],
        ];
    }

    /**
     * Bias sintetico per UI/debug.
     *
     * Questo bias NON deve essere usato da solo per generare o nascondere
     * candidati. Serve solo come suggerimento.
     */
    private function suggestedBias(ProductUnderstandingGlobalFact $fact): string
    {
        if ($fact->global_product_confidence_score >= 70 && $fact->confirmed_count > $fact->ignored_count) {
            return 'globally_confirmed_product';
        }

        if ($fact->global_product_confidence_score >= 50 && $fact->ignored_count > $fact->confirmed_count) {
            return 'globally_seen_often_ignored';
        }

        if ($fact->global_product_confidence_score >= 50) {
            return 'globally_seen_product';
        }

        return 'weak_global_signal';
    }

    /**
     * Hint leggibile per la futura UI di revisione.
     */
    private function reviewHint(
        ProductUnderstandingGlobalFact $fact,
        bool $nameDiffersFromCanonical,
        float $nameSimilarity,
    ): ?string {
        if ($nameDiffersFromCanonical && $nameSimilarity < 0.85 && $fact->canonical_name) {
            return 'global_canonical_name_available';
        }

        if ($fact->confirmed_count > 0 && $fact->ignored_count === 0) {
            return 'ean_globally_confirmed';
        }

        if ($fact->ignored_count > 0 && $fact->confirmed_count === 0) {
            return 'ean_globally_seen_but_often_ignored';
        }

        if ($fact->confirmed_count > 0 && $fact->ignored_count > 0) {
            return 'ean_globally_mixed_registration_preference';
        }

        if ($fact->seen_count > 0) {
            return 'ean_globally_seen';
        }

        return null;
    }

    /**
     * Segnali spiegabili.
     */
    private function signals(
        ProductUnderstandingGlobalFact $fact,
        bool $nameDiffersFromCanonical,
        float $nameSimilarity,
    ): array {
        $signals = [
            'global_ean_fact_found',
        ];

        if ($fact->global_product_confidence_score >= 70) {
            $signals[] = 'strong_global_product_confidence';
        } elseif ($fact->global_product_confidence_score >= 50) {
            $signals[] = 'medium_global_product_confidence';
        } else {
            $signals[] = 'weak_global_product_confidence';
        }

        if ($fact->confirmed_count > 0) {
            $signals[] = 'global_confirmations_present';
        }

        if ($fact->ignored_count > 0) {
            $signals[] = 'global_ignored_observations_present';
        }

        if ($nameDiffersFromCanonical) {
            $signals[] = 'candidate_name_differs_from_global_canonical_name';
        }

        if ($nameSimilarity >= 0.85) {
            $signals[] = 'candidate_name_similar_to_global_canonical_name';
        }

        return array_values(array_unique($signals));
    }

    /**
     * Normalizza un nome per confronti robusti.
     */
    private function normalizeName(string $name): ?string
    {
        $normalized = Str::ascii($name);
        $normalized = mb_strtolower($normalized);
        $normalized = preg_replace('/[^a-z0-9]+/i', ' ', $normalized) ?: $normalized;
        $normalized = trim(preg_replace('/\s+/', ' ', $normalized) ?: $normalized);

        if ($normalized === '') {
            return null;
        }

        return mb_substr($normalized, 0, 512);
    }

    /**
     * Token significativi.
     */
    private function tokens(string $text): array
    {
        if ($text === '') {
            return [];
        }

        $tokens = preg_split('/\s+/', $text) ?: [];

        $stopWords = [
            'di',
            'da',
            'del',
            'della',
            'dello',
            'dei',
            'degli',
            'per',
            'con',
            'senza',
            'the',
            'and',
            'for',
            'new',
        ];

        return collect($tokens)
            ->map(fn (string $token): string => trim($token))
            ->filter(fn (string $token): bool => mb_strlen($token) >= 2)
            ->reject(fn (string $token): bool => in_array($token, $stopWords, true))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Similarità Jaccard tra token.
     */
    private function jaccardSimilarity(array $leftTokens, array $rightTokens): float
    {
        if ($leftTokens === [] || $rightTokens === []) {
            return 0.0;
        }

        $left = array_values(array_unique($leftTokens));
        $right = array_values(array_unique($rightTokens));

        $intersection = array_intersect($left, $right);
        $union = array_unique(array_merge($left, $right));

        if ($union === []) {
            return 0.0;
        }

        return count($intersection) / count($union);
    }
}