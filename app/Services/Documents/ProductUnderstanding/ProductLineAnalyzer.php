<?php

namespace App\Services\Documents\ProductUnderstanding;

use App\Models\DocumentLine;

class ProductLineAnalyzer
{
    private array $config;

    public function __construct()
    {
        $this->config = config('product_understanding', []);
    }

    /**
     * Analizza una riga documento e produce segnali strutturati.
     *
     * Questo layer non crea Product e non modifica DocumentLine.
     * Serve a capire se una riga sembra un prodotto registrabile, un servizio,
     * un consumabile, una garanzia estesa, una riga contabile o altro.
     */
    public function analyze(DocumentLine $line): ProductLineAnalysisResult
    {
        $description = trim((string) $line->description);
        $rawText = trim((string) $line->raw_text);
        $metadata = $line->metadata ?? [];

        $normalizedDescription = $this->normalizeText($description);
        $normalizedRawText = $this->normalizeText($rawText);
        $combinedText = trim($normalizedDescription . ' ' . $normalizedRawText);

        $registerableScore = 0;
        $nonProductScore = 0;
        $signals = [];
        $negativeSignals = [];
        $warnings = [];
        $scoreBreakdown = [];

        $productCode = trim((string) ($metadata['product_code_candidate'] ?? ''));
        $serialCandidate = trim((string) ($metadata['serial_number_candidate'] ?? ''));
        $eanCandidate = $metadata['ean_code_candidate'] ?? $this->extractEan($description . ' ' . $rawText);
        $modelCandidate = $this->guessModelCandidate($productCode, $description . ' ' . $rawText);
        $brandCandidate = $this->detectBrand($combinedText);

        $hardType = $this->detectHardNonProductType(
            text: $combinedText,
            productCode: $productCode,
            invoiceCode: trim((string) ($metadata['invoice_code'] ?? '')),
        );

        if ($hardType !== null) {
            $score = abs($this->score('hard_exclusion'));

            return new ProductLineAnalysisResult(
                version: $this->version(),
                lineType: $hardType,
                registerableScore: 0,
                nonProductScore: $score,
                hardExcluded: true,
                suggestedName: $this->suggestName($description),
                suggestedCategory: null,
                brandCandidate: $brandCandidate,
                modelCandidate: $modelCandidate,
                eanCandidate: $eanCandidate,
                serialCandidate: $serialCandidate !== '' ? $serialCandidate : null,
                signals: [],
                negativeSignals: ['hard_exclusion_' . $hardType],
                warnings: [],
                scoreBreakdown: [
                    'hard_exclusion_' . $hardType => $this->score('hard_exclusion'),
                ],
            );
        }

        if ($description !== '') {
            $this->addPositiveScore(
                score: $registerableScore,
                signals: $signals,
                scoreBreakdown: $scoreBreakdown,
                key: 'description_present'
            );
        } else {
            $warnings[] = 'missing_description';

            $this->addNegativeScore(
                score: $nonProductScore,
                signals: $negativeSignals,
                scoreBreakdown: $scoreBreakdown,
                key: 'missing_description'
            );
        }

        if ($this->hasPositivePrice($line)) {
            $this->addPositiveScore(
                score: $registerableScore,
                signals: $signals,
                scoreBreakdown: $scoreBreakdown,
                key: 'positive_price'
            );
        } else {
            $warnings[] = 'missing_positive_price';

            $this->addNegativeScore(
                score: $nonProductScore,
                signals: $negativeSignals,
                scoreBreakdown: $scoreBreakdown,
                key: 'missing_positive_price'
            );
        }

        if ($eanCandidate) {
            $this->addPositiveScore(
                score: $registerableScore,
                signals: $signals,
                scoreBreakdown: $scoreBreakdown,
                key: 'ean_detected'
            );
        }

        if ($serialCandidate !== '') {
            $this->addPositiveScore(
                score: $registerableScore,
                signals: $signals,
                scoreBreakdown: $scoreBreakdown,
                key: 'serial_detected'
            );
        }

        if ($modelCandidate) {
            $this->addPositiveScore(
                score: $registerableScore,
                signals: $signals,
                scoreBreakdown: $scoreBreakdown,
                key: 'model_or_sku_detected'
            );
        }

        if ($brandCandidate) {
            $this->addPositiveScore(
                score: $registerableScore,
                signals: $signals,
                scoreBreakdown: $scoreBreakdown,
                key: 'brand_detected'
            );
        }

        $durableMatch = $this->matchCategoryGroup(
            normalizedDescription: $normalizedDescription,
            normalizedRawText: $normalizedRawText,
            group: 'durable_product',
        );

        $accessoryMatch = $this->matchCategoryGroup(
            normalizedDescription: $normalizedDescription,
            normalizedRawText: $normalizedRawText,
            group: 'accessory',
        );

        $consumableMatch = $this->matchCategoryGroup(
            normalizedDescription: $normalizedDescription,
            normalizedRawText: $normalizedRawText,
            group: 'consumable',
        );

        $serviceMatch = $this->matchCategoryGroup(
            normalizedDescription: $normalizedDescription,
            normalizedRawText: $normalizedRawText,
            group: 'service',
        );

        if ($durableMatch !== null) {
            $key = $durableMatch['source'] === 'description'
                ? 'durable_category_direct'
                : 'durable_category_context';

            $this->addPositiveScore(
                score: $registerableScore,
                signals: $signals,
                scoreBreakdown: $scoreBreakdown,
                key: $key,
                signal: 'durable_category_detected'
            );

            $signals[] = 'category_' . $durableMatch['category'];
            $signals[] = 'category_source_' . $durableMatch['source'];
        }

        if ($accessoryMatch !== null) {
            $key = $accessoryMatch['source'] === 'description'
                ? 'accessory_category_direct'
                : 'accessory_category_context';

            $this->addPositiveScore(
                score: $registerableScore,
                signals: $signals,
                scoreBreakdown: $scoreBreakdown,
                key: $key,
                signal: 'accessory_category_detected'
            );

            $signals[] = 'category_' . $accessoryMatch['category'];
            $signals[] = 'accessory_category_source_' . $accessoryMatch['source'];
        }

        if ($consumableMatch !== null) {
            $key = $consumableMatch['source'] === 'description'
                ? 'consumable_category_direct'
                : 'consumable_category_context';

            $this->addNegativeScore(
                score: $nonProductScore,
                signals: $negativeSignals,
                scoreBreakdown: $scoreBreakdown,
                key: $key,
                signal: 'consumable_category_detected'
            );

            $negativeSignals[] = 'category_' . $consumableMatch['category'];
            $negativeSignals[] = 'category_source_' . $consumableMatch['source'];
        }

        if ($serviceMatch !== null) {
            $key = $serviceMatch['source'] === 'description'
                ? 'service_category_direct'
                : 'service_category_context';

            $this->addNegativeScore(
                score: $nonProductScore,
                signals: $negativeSignals,
                scoreBreakdown: $scoreBreakdown,
                key: $key,
                signal: 'service_category_detected'
            );

            $negativeSignals[] = 'category_' . $serviceMatch['category'];
            $negativeSignals[] = 'category_source_' . $serviceMatch['source'];
        }

        if ($this->looksLikeAccountingNoise($combinedText)) {
            $this->addNegativeScore(
                score: $nonProductScore,
                signals: $negativeSignals,
                scoreBreakdown: $scoreBreakdown,
                key: 'accounting_or_payment_noise'
            );
        }

        if ($line->confidence_score !== null && $line->confidence_score >= 80) {
            $this->addPositiveScore(
                score: $registerableScore,
                signals: $signals,
                scoreBreakdown: $scoreBreakdown,
                key: 'high_line_confidence'
            );
        }

        $documentType = $line->document?->documentType?->code;

        if (in_array($documentType, ['invoice', 'order_confirmation'], true)) {
            $this->addPositiveScore(
                score: $registerableScore,
                signals: $signals,
                scoreBreakdown: $scoreBreakdown,
                key: 'structured_purchase_document'
            );
        }

        $preferredRegisterableMatch = $this->preferredRegisterableMatch(
            durableMatch: $durableMatch,
            accessoryMatch: $accessoryMatch,
        );

        $lineType = $this->inferLineType(
            durableMatch: $durableMatch,
            accessoryMatch: $accessoryMatch,
            consumableMatch: $consumableMatch,
            serviceMatch: $serviceMatch,
            preferredRegisterableMatch: $preferredRegisterableMatch,
            eanCandidate: $eanCandidate,
            modelCandidate: $modelCandidate,
            registerableScore: $registerableScore,
            nonProductScore: $nonProductScore,
        );

        if (
            $lineType === 'unknown'
            && $registerableScore >= $this->threshold('conflict_registerable')
            && $nonProductScore >= $this->threshold('conflict_non_product')
        ) {
            $warnings[] = 'conflicting_product_and_non_product_signals';
        }

        return new ProductLineAnalysisResult(
            version: $this->version(),
            lineType: $lineType,
            registerableScore: min(100, $registerableScore),
            nonProductScore: min(100, $nonProductScore),
            hardExcluded: false,
            suggestedName: $this->suggestName($description),
            suggestedCategory: $preferredRegisterableMatch['category']
                ?? $consumableMatch['category']
                ?? $serviceMatch['category']
                ?? null,
            brandCandidate: $brandCandidate,
            modelCandidate: $modelCandidate,
            eanCandidate: $eanCandidate,
            serialCandidate: $serialCandidate !== '' ? $serialCandidate : null,
            signals: $signals,
            negativeSignals: $negativeSignals,
            warnings: $warnings,
            scoreBreakdown: $scoreBreakdown,
        );
    }

    /**
     * Versione configurata dell'analyzer.
     */
    private function version(): string
    {
        return (string) ($this->config['version'] ?? 'product_line_analyzer_v1');
    }

    /**
     * Soglia configurata.
     */
    private function threshold(string $key): int
    {
        return (int) data_get($this->config, 'thresholds.' . $key, 0);
    }

    /**
     * Score configurato.
     */
    private function score(string $key): int
    {
        return (int) data_get($this->config, 'scores.' . $key, 0);
    }

    /**
     * Aggiunge score positivo.
     */
    private function addPositiveScore(
        int &$score,
        array &$signals,
        array &$scoreBreakdown,
        string $key,
        ?string $signal = null,
    ): void {
        $value = max(0, $this->score($key));

        $score += $value;
        $signals[] = $signal ?? $key;
        $scoreBreakdown[$key] = $value;
    }

    /**
     * Aggiunge score negativo.
     */
    private function addNegativeScore(
        int &$score,
        array &$signals,
        array &$scoreBreakdown,
        string $key,
        ?string $signal = null,
    ): void {
        $value = $this->score($key);
        $absoluteValue = abs($value);

        $score += $absoluteValue;
        $signals[] = $signal ?? $key;
        $scoreBreakdown[$key] = -$absoluteValue;
    }

    /**
     * Normalizza testo per segnali lessicali robusti.
     */
    private function normalizeText(string $text): string
    {
        $text = mb_strtolower($text);
        $text = str_replace('0', 'o', $text);
        $text = preg_replace('/[^a-z0-9à-ÿ\-\s\/]+/u', ' ', $text) ?: $text;
        $text = trim(preg_replace('/\s+/', ' ', $text) ?: $text);

        return $text;
    }

    /**
     * Matching testuale token-aware.
     */
    private function containsSignal(string $text, string $signal): bool
    {
        $signal = $this->normalizeText($signal);

        if ($text === '' || $signal === '') {
            return false;
        }

        if (str_contains($signal, ' ') || str_contains($signal, '-') || str_contains($signal, '/')) {
            return str_contains($text, $signal);
        }

        return preg_match(
            '/(?<![a-z0-9à-ÿ])' . preg_quote($signal, '/') . '(?![a-z0-9à-ÿ])/u',
            $text
        ) === 1;
    }

    /**
     * Righe che non devono mai diventare prodotto registrabile.
     */
    private function detectHardNonProductType(
        string $text,
        string $productCode,
        string $invoiceCode,
    ): ?string {
        $code = mb_strtolower(trim($productCode));
        $invoiceCode = mb_strtolower(trim($invoiceCode));

        foreach ((array) data_get($this->config, 'hard_exclusions.prefixes', []) as $prefix => $type) {
            if (
                str_starts_with($code, (string) $prefix)
                || str_starts_with($invoiceCode, (string) $prefix)
            ) {
                return (string) $type;
            }
        }

        foreach ((array) data_get($this->config, 'hard_exclusions.signals', []) as $signal => $type) {
            if ($this->containsSignal($text, (string) $signal)) {
                return (string) $type;
            }
        }

        return null;
    }

    /**
     * Match categoria pesando descrizione più del contesto.
     */
    private function matchCategoryGroup(
        string $normalizedDescription,
        string $normalizedRawText,
        string $group,
    ): ?array {
        $categories = (array) data_get($this->config, 'categories.' . $group, []);

        foreach ($categories as $category => $signals) {
            foreach ((array) $signals as $signal) {
                if ($this->containsSignal($normalizedDescription, (string) $signal)) {
                    return [
                        'group' => $group,
                        'category' => (string) $category,
                        'signal' => (string) $signal,
                        'source' => 'description',
                    ];
                }
            }
        }

        foreach ($categories as $category => $signals) {
            foreach ((array) $signals as $signal) {
                if ($this->containsSignal($normalizedRawText, (string) $signal)) {
                    return [
                        'group' => $group,
                        'category' => (string) $category,
                        'signal' => (string) $signal,
                        'source' => 'context',
                    ];
                }
            }
        }

        return null;
    }

    /**
     * Sceglie la categoria registrabile preferita.
     *
     * Regola importante:
     * - un accessorio trovato nella descrizione principale batte un prodotto
     *   durevole trovato solo nel contesto.
     *
     * Esempio:
     * "Docking Station USB-C ... compatibile notebook"
     * => docking_station, non notebook.
     */
    private function preferredRegisterableMatch(?array $durableMatch, ?array $accessoryMatch): ?array
    {
        if ($accessoryMatch === null) {
            return $durableMatch;
        }

        if ($durableMatch === null) {
            return $accessoryMatch;
        }

        if (
            $accessoryMatch['source'] === 'description'
            && $durableMatch['source'] === 'context'
        ) {
            return $accessoryMatch;
        }

        if (
            $durableMatch['source'] === 'description'
            && $accessoryMatch['source'] === 'context'
        ) {
            return $durableMatch;
        }

        /*
        |--------------------------------------------------------------------------
        | A parità di source, preferiamo la categoria più specifica della riga.
        |--------------------------------------------------------------------------
        |
        | Gli accessori sono spesso descritti con termini più specifici.
        | Questo evita classificazioni troppo generiche.
        |
        */
        if ($accessoryMatch['source'] === $durableMatch['source']) {
            return $accessoryMatch;
        }

        return $durableMatch;
    }

    /**
     * Riconosce rumore contabile o di pagamento.
     */
    private function looksLikeAccountingNoise(string $text): bool
    {
        foreach ((array) data_get($this->config, 'accounting_noise', []) as $signal) {
            if ($this->containsSignal($text, (string) $signal)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Verifica prezzo positivo.
     */
    private function hasPositivePrice(DocumentLine $line): bool
    {
        if ($line->unit_price !== null && (float) $line->unit_price > 0) {
            return true;
        }

        if ($line->total_price !== null && (float) $line->total_price > 0) {
            return true;
        }

        return false;
    }

    /**
     * Estrae EAN/GTIN dal testo.
     */
    private function extractEan(string $text): ?string
    {
        if (preg_match('/\bEAN\s*[:\-]?\s*(?<ean>\d{8}|\d{12}|\d{13}|\d{14})\b/iu', $text, $matches)) {
            return $matches['ean'];
        }

        if (preg_match('/\b(?<ean>\d{8}|\d{12}|\d{13}|\d{14})\b/u', $text, $matches)) {
            return $matches['ean'];
        }

        return null;
    }

    /**
     * Riconosce un possibile modello/codice prodotto.
     */
    private function guessModelCandidate(string $productCode, string $text): ?string
    {
        if ($productCode !== '' && ! $this->looksLikeEan($productCode)) {
            return $productCode;
        }

        if (preg_match('/\b[A-Z]{2,}[A-Z0-9]*[-\/]?[A-Z0-9]{2,}\b/u', $text, $matches)) {
            $candidate = $matches[0];

            if (! $this->looksLikeEan($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Brand detection minimale e locale.
     *
     * Non è un database brand definitivo: serve solo come segnale debole iniziale.
     */
    private function detectBrand(string $text): ?string
    {
        foreach ((array) data_get($this->config, 'brands', []) as $brand) {
            if ($this->containsSignal($text, (string) $brand)) {
                return ucfirst((string) $brand);
            }
        }

        return null;
    }

    /**
     * Suggerisce un nome prodotto ripulito.
     */
    private function suggestName(string $description): ?string
    {
        $name = trim(preg_replace('/\s+/', ' ', $description) ?: $description);

        if ($name === '') {
            return null;
        }

        $name = preg_replace('/\s+\d{1,2}(?:[,.]\d{2})?\s*%$/u', '', $name) ?: $name;
        $name = preg_replace('/\s+%$/u', '', $name) ?: $name;

        return trim($name);
    }

    /**
     * Verifica EAN/GTIN.
     */
    private function looksLikeEan(string $code): bool
    {
        $normalized = preg_replace('/\D+/', '', $code) ?: '';

        return (bool) preg_match('/^\d{8}$|^\d{12}$|^\d{13}$|^\d{14}$/', $normalized);
    }

    /**
     * Determina il tipo finale della riga.
     */
    private function inferLineType(
        ?array $durableMatch,
        ?array $accessoryMatch,
        ?array $consumableMatch,
        ?array $serviceMatch,
        ?array $preferredRegisterableMatch,
        ?string $eanCandidate,
        ?string $modelCandidate,
        int $registerableScore,
        int $nonProductScore,
    ): string {
        if ($serviceMatch !== null && $registerableScore < 65) {
            return 'service';
        }

        if ($consumableMatch !== null && $registerableScore < 70) {
            return 'consumable';
        }

        if ($preferredRegisterableMatch !== null) {
            return $preferredRegisterableMatch['group'] === 'accessory'
                ? 'accessory'
                : 'durable_product';
        }

        if (($eanCandidate || $modelCandidate) && $registerableScore >= 55 && $nonProductScore < 45) {
            return 'durable_product';
        }

        if ($nonProductScore >= 50 && $registerableScore < 60) {
            return 'non_product';
        }

        return 'unknown';
    }
}