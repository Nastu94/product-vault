<?php

namespace App\Services\Documents\ProductUnderstanding;

use App\Models\DocumentLine;
use App\Models\ProductUnderstandingFeedback;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class ProductUnderstandingFeedbackMatcher
{
    private const VERSION = 'product_understanding_feedback_matcher_v2';

    /**
     * Cerca feedback storici simili alla riga/candidato corrente.
     *
     * Importante:
     * - product_identity_score misura se la riga assomiglia a un prodotto/accessorio già visto;
     * - registration_preference_score misura se l'utente/team tende a confermarlo o ignorarlo.
     *
     * Queste due cose non sono equivalenti.
     */
    public function match(
        DocumentLine $line,
        ?string $candidateName,
        ?string $eanCode,
    ): array {
        $line->loadMissing('document');

        $teamId = $line->document?->team_id;
        $normalizedDescription = $this->normalizeDescription(
            $candidateName ?: (string) $line->description
        );

        $exactEanFeedback = $this->exactEanFeedback($teamId, $eanCode);
        $exactDescriptionFeedback = $this->exactDescriptionFeedback($teamId, $normalizedDescription);
        $similarFeedback = $this->similarDescriptionFeedback($teamId, $normalizedDescription);

        $productIdentityScore = 0;
        $registrationPreferenceScore = 0;

        $identitySignals = [];
        $preferenceSignals = [];

        $confirmedEanCount = $exactEanFeedback->where('review_status', 'confirmed')->count();
        $ignoredEanCount = $exactEanFeedback->where('review_status', 'ignored')->count();

        /*
        |--------------------------------------------------------------------------
        | Identità prodotto da EAN
        |--------------------------------------------------------------------------
        |
        | Se l'EAN è già stato visto, la riga è fortemente riconoscibile come lo
        | stesso prodotto/accessorio, indipendentemente dal fatto che sia stato
        | confermato o ignorato.
        |
        */
        if (($confirmedEanCount + $ignoredEanCount) > 0) {
            $productIdentityScore += min(80, 60 + (($confirmedEanCount + $ignoredEanCount) * 5));
            $identitySignals[] = 'exact_ean_seen_feedback';
        }

        if ($confirmedEanCount > 0) {
            $registrationPreferenceScore += min(70, 50 + ($confirmedEanCount * 5));
            $preferenceSignals[] = 'exact_ean_confirmed_feedback';
        }

        if ($ignoredEanCount > 0) {
            $registrationPreferenceScore -= min(70, 50 + ($ignoredEanCount * 5));
            $preferenceSignals[] = 'exact_ean_ignored_feedback';
        }

        $confirmedDescriptionCount = $exactDescriptionFeedback->where('review_status', 'confirmed')->count();
        $ignoredDescriptionCount = $exactDescriptionFeedback->where('review_status', 'ignored')->count();

        if (($confirmedDescriptionCount + $ignoredDescriptionCount) > 0) {
            $productIdentityScore += min(45, 25 + (($confirmedDescriptionCount + $ignoredDescriptionCount) * 5));
            $identitySignals[] = 'exact_description_seen_feedback';
        }

        if ($confirmedDescriptionCount > 0) {
            $registrationPreferenceScore += min(45, 25 + ($confirmedDescriptionCount * 5));
            $preferenceSignals[] = 'exact_description_confirmed_feedback';
        }

        if ($ignoredDescriptionCount > 0) {
            $registrationPreferenceScore -= min(45, 25 + ($ignoredDescriptionCount * 5));
            $preferenceSignals[] = 'exact_description_ignored_feedback';
        }

        $bestConfirmedSimilarity = (float) ($similarFeedback
            ->where('review_status', 'confirmed')
            ->max('similarity') ?? 0);

        $bestIgnoredSimilarity = (float) ($similarFeedback
            ->where('review_status', 'ignored')
            ->max('similarity') ?? 0);

        $bestSimilarity = max($bestConfirmedSimilarity, $bestIgnoredSimilarity);

        if ($bestSimilarity >= 0.75) {
            $productIdentityScore += (int) round($bestSimilarity * 25);
            $identitySignals[] = 'similar_description_seen_feedback';
        }

        if ($bestConfirmedSimilarity >= 0.75) {
            $registrationPreferenceScore += (int) round($bestConfirmedSimilarity * 30);
            $preferenceSignals[] = 'similar_confirmed_description_feedback';
        }

        if ($bestIgnoredSimilarity >= 0.75) {
            $registrationPreferenceScore -= (int) round($bestIgnoredSimilarity * 30);
            $preferenceSignals[] = 'similar_ignored_description_feedback';
        }

        $productIdentityScore = max(0, min(100, $productIdentityScore));
        $registrationPreferenceScore = max(-100, min(100, $registrationPreferenceScore));

        return [
            'version' => self::VERSION,
            'normalized_description' => $normalizedDescription,

            /*
            |--------------------------------------------------------------------------
            | Nuovi punteggi separati
            |--------------------------------------------------------------------------
            */
            'product_identity_score' => $productIdentityScore,
            'registration_preference_score' => $registrationPreferenceScore,
            'suggested_bias' => $this->suggestedBias(
                productIdentityScore: $productIdentityScore,
                registrationPreferenceScore: $registrationPreferenceScore,
            ),
            'review_hint' => $this->reviewHint(
                productIdentityScore: $productIdentityScore,
                registrationPreferenceScore: $registrationPreferenceScore,
                confirmedEanCount: $confirmedEanCount,
                ignoredEanCount: $ignoredEanCount,
                confirmedDescriptionCount: $confirmedDescriptionCount,
                ignoredDescriptionCount: $ignoredDescriptionCount,
                bestConfirmedSimilarity: $bestConfirmedSimilarity,
                bestIgnoredSimilarity: $bestIgnoredSimilarity,
            ),

            /*
            |--------------------------------------------------------------------------
            | Retrocompatibilità temporanea
            |--------------------------------------------------------------------------
            |
            | evidence_score resta valorizzato con la preferenza di registrazione.
            | Più avanti potremo rimuoverlo quando la UI/userà i due score separati.
            |
            */
            'evidence_score' => $registrationPreferenceScore,

            'identity_signals' => array_values(array_unique($identitySignals)),
            'preference_signals' => array_values(array_unique($preferenceSignals)),

            /*
            |--------------------------------------------------------------------------
            | Campo legacy signals
            |--------------------------------------------------------------------------
            |
            | Per ora manteniamo anche signals, così eventuali viste/debug esistenti
            | continuano a funzionare.
            |
            */
            'signals' => array_values(array_unique(array_merge(
                $identitySignals,
                $preferenceSignals,
            ))),

            'exact_ean' => [
                'confirmed_count' => $confirmedEanCount,
                'ignored_count' => $ignoredEanCount,
                'seen_count' => $confirmedEanCount + $ignoredEanCount,
            ],

            'exact_description' => [
                'confirmed_count' => $confirmedDescriptionCount,
                'ignored_count' => $ignoredDescriptionCount,
                'seen_count' => $confirmedDescriptionCount + $ignoredDescriptionCount,
            ],

            'similar_description' => [
                'best_confirmed_similarity' => round($bestConfirmedSimilarity, 3),
                'best_ignored_similarity' => round($bestIgnoredSimilarity, 3),
                'best_similarity' => round($bestSimilarity, 3),
                'matches' => $similarFeedback
                    ->sortByDesc('similarity')
                    ->take(5)
                    ->map(fn (array $match): array => [
                        'feedback_id' => $match['feedback_id'],
                        'review_status' => $match['review_status'],
                        'candidate_name' => $match['candidate_name'],
                        'normalized_line_description' => $match['normalized_line_description'],
                        'similarity' => round((float) $match['similarity'], 3),
                        'analyzer_line_type' => $match['analyzer_line_type'],
                        'analyzer_suggested_category' => $match['analyzer_suggested_category'],
                    ])
                    ->values()
                    ->all(),
            ],
        ];
    }

    /**
     * Feedback con stesso EAN.
     */
    private function exactEanFeedback(?int $teamId, ?string $eanCode): Collection
    {
        $eanCode = trim((string) $eanCode);

        if ($eanCode === '') {
            return collect();
        }

        return $this->baseQuery($teamId)
            ->where('candidate_ean_code', $eanCode)
            ->get();
    }

    /**
     * Feedback con descrizione normalizzata identica.
     */
    private function exactDescriptionFeedback(?int $teamId, ?string $normalizedDescription): Collection
    {
        if (! $normalizedDescription) {
            return collect();
        }

        return $this->baseQuery($teamId)
            ->where('normalized_line_description', $normalizedDescription)
            ->get();
    }

    /**
     * Feedback con descrizione simile.
     *
     * MVP: similarità Jaccard sui token.
     * In futuro potremo sostituire questo punto con RapidFuzz/Python o embedding.
     */
    private function similarDescriptionFeedback(?int $teamId, ?string $normalizedDescription): Collection
    {
        if (! $normalizedDescription) {
            return collect();
        }

        $sourceTokens = $this->tokens($normalizedDescription);

        if ($sourceTokens === []) {
            return collect();
        }

        return $this->baseQuery($teamId)
            ->whereNotNull('normalized_line_description')
            ->latest('id')
            ->limit(300)
            ->get()
            ->map(function (ProductUnderstandingFeedback $feedback) use ($sourceTokens): array {
                $targetTokens = $this->tokens((string) $feedback->normalized_line_description);

                return [
                    'feedback_id' => $feedback->id,
                    'review_status' => $feedback->review_status,
                    'candidate_name' => $feedback->candidate_name,
                    'normalized_line_description' => $feedback->normalized_line_description,
                    'similarity' => $this->jaccardSimilarity($sourceTokens, $targetTokens),
                    'analyzer_line_type' => $feedback->analyzer_line_type,
                    'analyzer_suggested_category' => $feedback->analyzer_suggested_category,
                ];
            })
            ->filter(fn (array $match): bool => $match['similarity'] >= 0.65)
            ->values();
    }

    /**
     * Query base team-scoped.
     */
    private function baseQuery(?int $teamId)
    {
        return ProductUnderstandingFeedback::query()
            ->when(
                $teamId !== null,
                fn ($query) => $query->where('team_id', $teamId),
                fn ($query) => $query->whereNull('team_id'),
            );
    }

    /**
     * Bias sintetico per UI/debug.
     */
    private function suggestedBias(
        int $productIdentityScore,
        int $registrationPreferenceScore,
    ): string {
        if ($productIdentityScore >= 50 && $registrationPreferenceScore >= 25) {
            return 'previously_confirmed';
        }

        if ($productIdentityScore >= 50 && $registrationPreferenceScore <= -25) {
            return 'previously_ignored';
        }

        if ($productIdentityScore >= 50) {
            return 'previously_seen';
        }

        if ($registrationPreferenceScore >= 25) {
            return 'positive';
        }

        if ($registrationPreferenceScore <= -25) {
            return 'negative';
        }

        return 'neutral';
    }

    /**
     * Hint leggibile per la futura revisione UI.
     */
    private function reviewHint(
        int $productIdentityScore,
        int $registrationPreferenceScore,
        int $confirmedEanCount,
        int $ignoredEanCount,
        int $confirmedDescriptionCount,
        int $ignoredDescriptionCount,
        float $bestConfirmedSimilarity,
        float $bestIgnoredSimilarity,
    ): ?string {
        if ($confirmedEanCount > 0) {
            return 'same_ean_previously_confirmed';
        }

        if ($ignoredEanCount > 0) {
            return 'same_ean_previously_ignored';
        }

        if ($confirmedDescriptionCount > 0) {
            return 'same_description_previously_confirmed';
        }

        if ($ignoredDescriptionCount > 0) {
            return 'same_description_previously_ignored';
        }

        if ($bestConfirmedSimilarity >= 0.75) {
            return 'similar_description_previously_confirmed';
        }

        if ($bestIgnoredSimilarity >= 0.75) {
            return 'similar_description_previously_ignored';
        }

        if ($productIdentityScore >= 50 && $registrationPreferenceScore === 0) {
            return 'product_seen_without_clear_registration_preference';
        }

        return null;
    }

    /**
     * Normalizza descrizione per matching.
     */
    private function normalizeDescription(string $description): ?string
    {
        $normalized = Str::ascii($description);
        $normalized = mb_strtolower($normalized);
        $normalized = preg_replace('/[^a-z0-9]+/i', ' ', $normalized) ?: $normalized;
        $normalized = trim(preg_replace('/\s+/', ' ', $normalized) ?: $normalized);

        if ($normalized === '') {
            return null;
        }

        return mb_substr($normalized, 0, 512);
    }

    /**
     * Token significativi per confronto.
     */
    private function tokens(string $text): array
    {
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
     * Similarità Jaccard tra due set di token.
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