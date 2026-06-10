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