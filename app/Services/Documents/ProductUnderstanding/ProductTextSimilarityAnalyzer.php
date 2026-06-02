<?php

namespace App\Services\Documents\ProductUnderstanding;

use App\Models\ProductUnderstandingGlobalFact;
use Symfony\Component\Process\Process;
use Throwable;

class ProductTextSimilarityAnalyzer
{
    private const VERSION = 'product_text_similarity_v1';

    /**
     * Esegue un confronto fuzzy osservativo tra il nome candidato e i nomi
     * canonici già disponibili nei global facts.
     *
     * Non decide se una riga è prodotto.
     * Non modifica nome, score o stato del candidato.
     * Restituisce solo segnali salvabili nei metadata.
     */
    public function analyze(
        ?string $candidateName,
        ?string $eanCode,
        array $globalFactContext = [],
        ?string $suggestedCategory = null,
        ?string $suggestedLineType = null,
    ): array {
        if (! (bool) config('services.product_text_similarity.enabled', false)) {
            return $this->emptyResult(enabled: false, warning: 'disabled');
        }

        $candidateName = trim((string) $candidateName);

        if ($candidateName === '') {
            return $this->emptyResult(enabled: true, warning: 'missing_candidate_name');
        }

        $globalFacts = $this->globalFactsFromContext($globalFactContext);

        if ($globalFacts === []) {
            $globalFacts = $this->globalFactsFromCategoryFallback(
                suggestedCategory: $suggestedCategory,
                suggestedLineType: $suggestedLineType,
            );
        }

        if ($globalFacts === []) {
            return $this->emptyResult(enabled: true, warning: 'missing_global_facts');
        }

        $python = (string) config('services.product_text_similarity.python');
        $script = (string) config('services.product_text_similarity.script');
        $timeout = (int) config('services.product_text_similarity.timeout', 30);
        $minScore = (int) config('services.product_text_similarity.min_score', 80);

        if (! is_file($python)) {
            return $this->emptyResult(
                enabled: true,
                warning: 'python_binary_not_found',
                error: $python,
            );
        }

        if (! is_file($script)) {
            return $this->emptyResult(
                enabled: true,
                warning: 'python_script_not_found',
                error: $script,
            );
        }

        $payload = [
            'candidate_name' => $candidateName,
            'ean_code' => $eanCode,
            'global_facts' => $globalFacts,
        ];

        try {
            $process = new Process([
                $python,
                $script,
                '--min-score',
                (string) $minScore,
            ]);

            $process->setInput(json_encode(
                $payload,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            ));

            $process->setTimeout($timeout);
            $process->run();

            $output = trim($process->getOutput());
            $errorOutput = trim($process->getErrorOutput());

            $decoded = $this->extractJsonPayload($output);

            if (! is_array($decoded)) {
                return $this->emptyResult(
                    enabled: true,
                    warning: 'invalid_python_json',
                    error: mb_substr(trim($output . ' ' . $errorOutput), 0, 1000),
                );
            }

            return $this->normalizeResult($decoded);
        } catch (Throwable $exception) {
            return $this->emptyResult(
                enabled: true,
                warning: 'python_similarity_failed',
                error: $exception->getMessage(),
            );
        }
    }

    /**
     * Estrae dal contesto globale i soli dati utili al confronto testuale.
     */
    private function globalFactsFromContext(array $globalFactContext): array
    {
        if (($globalFactContext['matched'] ?? false) !== true) {
            return [];
        }

        $canonicalName = trim((string) ($globalFactContext['canonical_name'] ?? ''));

        if ($canonicalName === '') {
            return [];
        }

        return [[
            'canonical_name' => $canonicalName,
            'suggested_category' => $globalFactContext['suggested_category'] ?? null,
            'suggested_line_type' => $globalFactContext['suggested_line_type'] ?? null,
            'confidence' => $globalFactContext['global_product_confidence_score'] ?? null,
        ]];
    }

    /**
     * Fallback osservativo per candidati senza EAN.
     *
     * Cerca pochi nomi canonici compatibili con categoria/tipo riga.
     * Non deve diventare una decisione business: serve solo a produrre segnali
     * di similarità testuale nei metadata.
     */
    private function globalFactsFromCategoryFallback(
        ?string $suggestedCategory,
        ?string $suggestedLineType,
    ): array {
        $suggestedCategory = trim((string) $suggestedCategory);
        $suggestedLineType = trim((string) $suggestedLineType);

        if ($suggestedCategory === '' && $suggestedLineType === '') {
            return [];
        }

        return ProductUnderstandingGlobalFact::query()
            ->whereNotNull('canonical_name')
            ->where('canonical_name', '<>', '')
            ->when(
                $suggestedCategory !== '',
                fn ($query) => $query->where('suggested_category', $suggestedCategory)
            )
            ->when(
                $suggestedCategory === '' && $suggestedLineType !== '',
                fn ($query) => $query->where('suggested_line_type', $suggestedLineType)
            )
            ->orderByDesc('global_product_confidence_score')
            ->orderByDesc('confirmed_count')
            ->orderByDesc('seen_count')
            ->limit(50)
            ->get()
            ->map(fn (ProductUnderstandingGlobalFact $fact): array => [
                'canonical_name' => $fact->canonical_name,
                'suggested_category' => $fact->suggested_category,
                'suggested_line_type' => $fact->suggested_line_type,
                'confidence' => $fact->global_product_confidence_score,
            ])
            ->values()
            ->all();
    }

    /**
     * Risultato vuoto ma stabile, così la pipeline non deve gestire eccezioni.
     */
    private function emptyResult(
        bool $enabled,
        string $warning,
        ?string $error = null,
    ): array {
        $result = [
            'version' => self::VERSION,
            'enabled' => $enabled,
            'best_match' => null,
            'signals' => [],
            'warnings' => [$warning],
        ];

        if ($error !== null) {
            $result['error'] = mb_substr($error, 0, 1000);
        }

        return $result;
    }

    /**
     * Accetta solo una forma dati sicura e prevedibile.
     */
    private function normalizeResult(array $result): array
    {
        return [
            'version' => (string) ($result['version'] ?? self::VERSION),
            'enabled' => (bool) ($result['enabled'] ?? true),
            'best_match' => is_array($result['best_match'] ?? null)
                ? $result['best_match']
                : null,
            'matches' => collect($result['matches'] ?? [])
                ->filter(fn ($match): bool => is_array($match))
                ->take(5)
                ->values()
                ->all(),
            'signals' => collect($result['signals'] ?? [])
                ->filter(fn ($signal): bool => is_string($signal) && $signal !== '')
                ->values()
                ->all(),
            'warnings' => collect($result['warnings'] ?? [])
                ->filter(fn ($warning): bool => is_string($warning) && $warning !== '')
                ->values()
                ->all(),
        ];
    }

    /**
     * Lo script dovrebbe stampare solo JSON, ma questo rende il wrapper più
     * tollerante se in futuro una libreria Python stampa log su stdout.
     */
    private function extractJsonPayload(string $output): ?array
    {
        $position = strrpos($output, '{"version"');

        if ($position === false) {
            return null;
        }

        $json = trim(substr($output, $position));
        $payload = json_decode($json, true);

        return is_array($payload) ? $payload : null;
    }
}