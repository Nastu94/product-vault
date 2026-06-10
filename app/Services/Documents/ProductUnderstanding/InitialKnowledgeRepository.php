<?php

namespace App\Services\Documents\ProductUnderstanding;

class InitialKnowledgeRepository
{
    /**
     * Cache locale dei file caricati durante la singola request/comando.
     */
    private array $cache = [];

    /**
     * Restituisce gli alias brand dal knowledge pack iniziale.
     */
    public function brandAliases(): array
    {
        return $this->loadKnowledgeFile('brand_aliases.php');
    }

    /**
     * Restituisce i pattern di esclusione candidati.
     */
    public function candidateSuppressionPatterns(): array
    {
        return collect(data_get($this->loadKnowledgeFile('exclusion_patterns.php'), 'candidate_suppression_patterns', []))
            ->filter(fn ($pattern): bool => is_array($pattern))
            ->values()
            ->all();
    }

    /**
     * Restituisce i line pattern dal knowledge pack iniziale.
     */
    public function linePatterns(): array
    {
        return $this->loadKnowledgeFile('line_patterns.php');
    }

    /**
     * Cerca pattern lessicali applicabili alla riga.
     *
     * Questo metodo è osservativo: non decide se generare o bloccare candidati.
     * Serve a salvare nei metadata quali segnali del knowledge pack hanno matchato.
     */
    public function matchLinePatterns(
        string $description,
        string $rawText,
        ?string $documentLineTypeCode = null,
    ): array {
        $description = $this->normalize($description);
        $rawText = $this->normalize($rawText);
        $lineType = $this->normalize($documentLineTypeCode ?? '');

        $matches = [];

        foreach ($this->linePatterns() as $group => $patterns) {
            if (! is_array($patterns)) {
                continue;
            }

            foreach ($patterns as $pattern) {
                if (! is_array($pattern)) {
                    continue;
                }

                $patternText = $this->normalize((string) ($pattern['pattern'] ?? ''));

                if ($patternText === '') {
                    continue;
                }

                $exactMatched = $this->containsToken($description, $patternText)
                    || $this->containsToken($rawText, $patternText);

                $fuzzyMatch = null;

                if (! $exactMatched) {
                    $fuzzyMatch = $this->matchFuzzyLinePattern(
                        text: trim($description.' '.$rawText),
                        pattern: $patternText,
                    );

                    if ($fuzzyMatch === null) {
                        continue;
                    }
                }

                $patternLineType = $this->normalize((string) ($pattern['document_line_type'] ?? ''));

                $matches[] = [
                    'matched' => true,
                    'group' => (string) $group,
                    'pattern' => (string) ($pattern['pattern'] ?? ''),
                    'normalized_pattern' => $patternText,
                    'match_type' => $exactMatched ? 'exact_pattern' : 'fuzzy_pattern',
                    'matched_text' => $exactMatched ? $patternText : $fuzzyMatch['matched_text'],
                    'similarity' => $exactMatched ? 1.0 : $fuzzyMatch['similarity'],
                    'edit_distance' => $exactMatched ? 0 : $fuzzyMatch['edit_distance'],
                    'document_line_type' => $patternLineType !== '' ? $patternLineType : null,
                    'line_type_matches_document_line_type' => $lineType !== ''
                        && $patternLineType !== ''
                        && $patternLineType === $lineType,
                    'suggested_category_slug' => $pattern['suggested_category_slug'] ?? null,
                    'product_kind' => $pattern['product_kind'] ?? null,
                    'semantic_group' => $pattern['semantic_group'] ?? null,
                    'candidate_bias' => $pattern['candidate_bias'] ?? null,
                    'weight' => (int) ($pattern['weight'] ?? 0),
                    'source' => 'initial_knowledge_pack_v1',
                ];
            }
        }

        return $matches;
    }

    /**
     * Cerca un alias brand nel knowledge pack iniziale.
     *
     * Gli alias non sono importati in database: restano dati versionati usati
     * come supporto leggero al matching.
     */
    public function findBrandAlias(string $value): ?array
    {
        $text = $this->normalize($value);

        if ($text === '') {
            return null;
        }

        $aliases = collect($this->brandAliases())
            ->filter(fn ($alias): bool => is_array($alias))
            ->filter(fn ($alias): bool => trim((string) ($alias['normalized_alias'] ?? '')) !== '')
            ->sortByDesc(fn ($alias): int => mb_strlen((string) $alias['normalized_alias']))
            ->values();

        foreach ($aliases as $alias) {
            $normalizedAlias = $this->normalize((string) ($alias['normalized_alias'] ?? ''));

            if (! $this->containsToken($text, $normalizedAlias)) {
                continue;
            }

            return [
                'alias' => (string) ($alias['alias'] ?? ''),
                'normalized_alias' => $normalizedAlias,
                'brand_normalized_name' => $this->normalize((string) ($alias['brand_normalized_name'] ?? '')),
                'confidence_score' => (int) ($alias['confidence_score'] ?? 0),
            ];
        }

        return null;
    }

    /**
     * Cerca un pattern di esclusione applicabile alla riga.
     *
     * Regola prudente:
     * - il pattern blocca solo se il document_line_type della riga coincide
     *   con quello dichiarato nel knowledge pack;
     * - questo evita di bloccare un prodotto valido solo perché contiene parole
     *   ambigue come "carta", "sconto" o "garanzia".
     */
    public function matchCandidateSuppressionPattern(
        string $description,
        string $rawText,
        ?string $documentLineTypeCode,
    ): ?array {
        $lineType = $this->normalize($documentLineTypeCode ?? '');

        if ($lineType === '') {
            return null;
        }

        $description = $this->normalize($description);
        $rawText = $this->normalize($rawText);

        foreach ($this->candidateSuppressionPatterns() as $pattern) {
            $patternLineType = $this->normalize((string) ($pattern['document_line_type'] ?? ''));

            if ($patternLineType === '' || $patternLineType !== $lineType) {
                continue;
            }

            $patternText = $this->normalize((string) ($pattern['pattern'] ?? ''));

            if ($patternText === '') {
                continue;
            }

            if (
                $this->containsToken($description, $patternText)
                || $this->containsToken($rawText, $patternText)
            ) {
                return [
                    'matched' => true,
                    'pattern' => (string) ($pattern['pattern'] ?? ''),
                    'normalized_pattern' => $patternText,
                    'document_line_type' => $patternLineType,
                    'reason' => $pattern['reason'] ?? null,
                    'weight' => (int) ($pattern['weight'] ?? 0),
                    'source' => 'initial_knowledge_pack_v1',
                ];
            }
        }

        return null;
    }

    /**
     * Cerca un match fuzzy prudente per i line pattern.
     *
     * Vale solo per pattern prodotto/accessorio abbastanza lunghi.
     * Non viene usato per exclusion_patterns, perché lì il rischio di falsi
     * negativi è più pericoloso.
     */
    private function matchFuzzyLinePattern(string $text, string $pattern): ?array
    {
        $text = $this->normalize($text);
        $pattern = $this->normalize($pattern);

        if (! $this->canUseFuzzyLinePattern($pattern)) {
            return null;
        }

        $bestMatch = null;
        $threshold = $this->fuzzyThresholdForPattern($pattern);
        $maxEditDistance = $this->maxEditDistanceForPattern($pattern);

        foreach ($this->candidateWindowsForPattern($text, $pattern) as $candidate) {
            $similarity = $this->textSimilarity($candidate, $pattern);
            $editDistance = levenshtein($candidate, $pattern);

            $passesSimilarity = $similarity >= $threshold;
            $passesEditDistance = $editDistance <= $maxEditDistance;

            if (! $passesSimilarity && ! $passesEditDistance) {
                continue;
            }

            if ($bestMatch === null || $similarity > $bestMatch['similarity']) {
                $bestMatch = [
                    'matched_text' => $candidate,
                    'similarity' => round($similarity, 4),
                    'edit_distance' => $editDistance,
                    'threshold' => $threshold,
                    'max_edit_distance' => $maxEditDistance,
                ];
            }
        }

        return $bestMatch;
    }

    /**
     * Decide se un pattern può essere usato in fuzzy matching.
     *
     * Evitiamo fuzzy su token corti o troppo generici: usb, hdmi, tv, pc, pro,
     * max, mini, hp, lg, 4k e simili devono restare exact.
     */
    private function canUseFuzzyLinePattern(string $pattern): bool
    {
        $compact = str_replace(' ', '', $pattern);

        if (mb_strlen($compact) < 7) {
            return false;
        }

        $blocked = [
            'usb',
            'usb c',
            'usb-c',
            'hdmi',
            'tv',
            'pc',
            'hp',
            'lg',
            'pro',
            'max',
            'mini',
            'smart',
            'wireless',
            '4k',
        ];

        return ! in_array($pattern, $blocked, true);
    }

    /**
     * Genera finestre candidate dal testo per confrontarle con il pattern.
     *
     * Per pattern singoli confronta token singoli.
     * Per pattern composti confronta finestre di token vicine alla stessa lunghezza.
     */
    private function candidateWindowsForPattern(string $text, string $pattern): array
    {
        $tokens = array_values(array_filter(explode(' ', $text)));
        $patternTokens = array_values(array_filter(explode(' ', $pattern)));
        $patternTokenCount = count($patternTokens);

        if ($tokens === [] || $patternTokens === []) {
            return [];
        }

        $windows = [];

        if ($patternTokenCount === 1) {
            foreach ($tokens as $token) {
                if (mb_strlen($token) >= 4) {
                    $windows[] = $token;
                }
            }

            return array_values(array_unique($windows));
        }

        $minWindowSize = max(1, $patternTokenCount - 1);
        $maxWindowSize = $patternTokenCount + 1;

        for ($windowSize = $minWindowSize; $windowSize <= $maxWindowSize; $windowSize++) {
            for ($index = 0; $index <= count($tokens) - $windowSize; $index++) {
                $windows[] = implode(' ', array_slice($tokens, $index, $windowSize));
            }
        }

        return array_values(array_unique($windows));
    }

    /**
     * Similarità testuale normalizzata tra 0 e 1.
     */
    private function textSimilarity(string $candidate, string $pattern): float
    {
        $candidate = $this->normalize($candidate);
        $pattern = $this->normalize($pattern);

        if ($candidate === '' || $pattern === '') {
            return 0.0;
        }

        $maxLength = max(mb_strlen($candidate), mb_strlen($pattern));

        if ($maxLength === 0) {
            return 0.0;
        }

        $lengthDifference = abs(mb_strlen($candidate) - mb_strlen($pattern));

        if ($lengthDifference > max(2, (int) floor($maxLength * 0.35))) {
            return 0.0;
        }

        $levenshteinSimilarity = 1 - (levenshtein($candidate, $pattern) / $maxLength);

        similar_text($candidate, $pattern, $percentage);

        $similarTextSimilarity = $percentage / 100;

        return max($levenshteinSimilarity, $similarTextSimilarity);
    }

    /**
     * Soglia fuzzy in base alla lunghezza del pattern.
     *
     * Nota:
     * - per pattern corti restiamo molto severi;
     * - da 8 caratteri in su tolleriamo meglio errori OCR/refusi interni;
     * - il fuzzy resta solo osservativo, quindi non genera né blocca candidati.
     */
    private function fuzzyThresholdForPattern(string $pattern): float
    {
        $length = mb_strlen(str_replace(' ', '', $pattern));

        if ($length >= 10) {
            return 0.76;
        }

        if ($length >= 8) {
            return 0.76;
        }

        return 0.86;
    }

    /**
     * Numero massimo di errori tollerati per pattern lunghi.
     *
     * Questa regola serve a intercettare refusi/OCR come:
     * - notebok => notebook
     * - stanpamte => stampante
     *
     * Rimane sicura perché il fuzzy è disabilitato sui token corti/generici.
     */
    private function maxEditDistanceForPattern(string $pattern): int
    {
        $length = mb_strlen(str_replace(' ', '', $pattern));

        if ($length >= 12) {
            return 3;
        }

        if ($length >= 8) {
            return 2;
        }

        return 1;
    }

    /**
     * Carica un file del knowledge pack iniziale.
     */
    private function loadKnowledgeFile(string $filename): array
    {
        if (array_key_exists($filename, $this->cache)) {
            return $this->cache[$filename];
        }

        $path = base_path('data/product_vault/knowledge/v1/'.$filename);

        if (! file_exists($path)) {
            return $this->cache[$filename] = [];
        }

        $data = require $path;

        return $this->cache[$filename] = is_array($data) ? $data : [];
    }

    /**
     * Normalizzazione minima per confronti con la knowledge base.
     */
    public function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/u', ' ', $value) ?: $value;
        $value = preg_replace('/\s+/', ' ', $value) ?: $value;

        return trim($value);
    }

    /**
     * Verifica token intero per evitare match dentro parole più lunghe.
     */
    public function containsToken(string $text, string $token): bool
    {
        $token = $this->normalize($token);

        if ($text === '' || $token === '') {
            return false;
        }

        return preg_match(
            '/(?<![a-z0-9])'.preg_quote($token, '/').'(?![a-z0-9])/u',
            $text
        ) === 1;
    }
}