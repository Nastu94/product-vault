<?php

namespace App\Services\Documents\ProductUnderstanding;

use App\Models\ProductUnderstandingFeedback;
use App\Models\ProductUnderstandingGlobalFact;

class ProductUnderstandingGlobalFactUpdater
{
    /**
     * Aggiorna i fatti globali partendo da un feedback locale.
     *
     * MVP:
     * - aggiorniamo solo fatti EAN-based;
     * - non salviamo riferimenti a team, utenti, documenti o merchant;
     * - ricostruiamo l'aggregato da tutti i feedback con stesso EAN per evitare doppi conteggi.
     */
    public function updateFromFeedback(ProductUnderstandingFeedback $feedback): ?ProductUnderstandingGlobalFact
    {
        $eanCode = trim((string) $feedback->candidate_ean_code);

        if ($eanCode === '') {
            return null;
        }

        $relatedFeedback = ProductUnderstandingFeedback::query()
            ->where('candidate_ean_code', $eanCode)
            ->get();

        if ($relatedFeedback->isEmpty()) {
            return null;
        }

        $seenCount = $relatedFeedback->count();
        $confirmedCount = $relatedFeedback
            ->where('review_status', 'confirmed')
            ->count();
        $ignoredCount = $relatedFeedback
            ->where('review_status', 'ignored')
            ->count();

        $canonicalNameCounts = $this->countValues(
            $relatedFeedback
                ->map(fn (ProductUnderstandingFeedback $item): ?string => $item->final_product_name ?: $item->candidate_name)
                ->filter()
                ->values()
                ->all()
        );

        $categoryCounts = $this->countValues(
            $relatedFeedback
                ->pluck('analyzer_suggested_category')
                ->filter()
                ->values()
                ->all()
        );

        $lineTypeCounts = $this->countValues(
            $relatedFeedback
                ->pluck('analyzer_line_type')
                ->filter()
                ->values()
                ->all()
        );

        $registrationRate = $seenCount > 0
            ? round(($confirmedCount / $seenCount) * 100, 2)
            : null;

        return ProductUnderstandingGlobalFact::query()->updateOrCreate(
            [
                'fact_type' => 'ean',
                'fact_key' => hash('sha256', $eanCode),
            ],
            [
                'fact_value' => $eanCode,
                'canonical_name' => $this->mostFrequentValue($canonicalNameCounts),
                'suggested_category' => $this->mostFrequentValue($categoryCounts),
                'suggested_line_type' => $this->mostFrequentValue($lineTypeCounts),

                'seen_count' => $seenCount,
                'confirmed_count' => $confirmedCount,
                'ignored_count' => $ignoredCount,
                'global_registration_rate' => $registrationRate,
                'global_product_confidence_score' => $this->calculateGlobalProductConfidenceScore(
                    seenCount: $seenCount,
                    confirmedCount: $confirmedCount,
                    ignoredCount: $ignoredCount,
                    categoryCounts: $categoryCounts,
                ),

                'canonical_name_counts' => $canonicalNameCounts,
                'category_counts' => $categoryCounts,
                'line_type_counts' => $lineTypeCounts,

                'metadata' => [
                    'updater' => 'product_understanding_global_fact_updater_v1',
                    'fact_source' => 'aggregated_product_understanding_feedback',
                    'privacy' => [
                        'stores_document_reference' => false,
                        'stores_user_reference' => false,
                        'stores_team_reference' => false,
                        'stores_merchant_reference' => false,
                        'stores_raw_text' => false,
                    ],
                ],

                'first_seen_at' => $relatedFeedback
                    ->min('reviewed_at'),
                'last_seen_at' => $relatedFeedback
                    ->max('reviewed_at'),
            ]
        );
    }

    /**
     * Conta valori normalizzati mantenendo il valore originale.
     */
    private function countValues(array $values): array
    {
        $counts = [];

        foreach ($values as $value) {
            $value = trim((string) $value);

            if ($value === '') {
                continue;
            }

            $key = mb_strtolower($value);

            if (! isset($counts[$key])) {
                $counts[$key] = [
                    'value' => $value,
                    'count' => 0,
                ];
            }

            $counts[$key]['count']++;
        }

        return collect($counts)
            ->sortByDesc('count')
            ->values()
            ->all();
    }

    /**
     * Restituisce il valore più frequente.
     */
    private function mostFrequentValue(array $counts): ?string
    {
        if ($counts === []) {
            return null;
        }

        return $counts[0]['value'] ?? null;
    }

    /**
     * Calcola quanto il sistema globale è sicuro che questo EAN rappresenti un prodotto/accessorio reale.
     *
     * Nota: gli ignored non abbassano drasticamente l'identità prodotto,
     * perché un accessorio può essere reale ma non interessante per un utente.
     */
    private function calculateGlobalProductConfidenceScore(
        int $seenCount,
        int $confirmedCount,
        int $ignoredCount,
        array $categoryCounts,
    ): int {
        if ($seenCount <= 0) {
            return 0;
        }

        $score = 35;

        $score += min(30, $seenCount * 5);
        $score += min(25, $confirmedCount * 7);

        if ($categoryCounts !== []) {
            $score += 10;
        }

        /*
        |--------------------------------------------------------------------------
        | Ignored come segnale debole
        |--------------------------------------------------------------------------
        |
        | Ignored indica spesso preferenza di non registrazione, non falsità del
        | prodotto. Penalizziamo poco, solo se non ci sono conferme.
        |
        */
        if ($confirmedCount === 0 && $ignoredCount > 0) {
            $score -= min(15, $ignoredCount * 3);
        }

        return max(0, min(100, $score));
    }
}