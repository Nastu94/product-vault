<?php

namespace App\Console\Commands\ProductVault;

use App\Models\Brand;
use App\Models\Category;
use App\Models\ProductIdentificationCandidate;
use App\Services\Documents\ProductUnderstanding\InitialKnowledgeRepository;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

#[Signature('product-vault:refresh-initial-knowledge
    {--document= : Limita il refresh a un document_id}
    {--candidate= : Limita il refresh a un singolo candidate_id}
    {--limit=500 : Numero massimo di candidati da processare}
    {--dry-run : Mostra cosa verrebbe aggiornato senza scrivere nulla}')]
#[Description('Refresh controllato dei metadata initial knowledge sui candidati prodotto esistenti')]
class RefreshInitialKnowledgeCommand extends Command
{
    public function __construct(
        private readonly InitialKnowledgeRepository $initialKnowledgeRepository,
    ) {
        parent::__construct();
    }

    /**
     * Aggiorna solo i metadata initial knowledge dei candidati già esistenti.
     *
     * Il comando non cambia review_status, non crea Product, non elimina candidati
     * e non modifica DocumentLine. Serve per rendere auditabili i candidati storici
     * creati prima dell'introduzione della knowledge base iniziale.
     */
    public function handle(): int
    {
        $limitOption = (int) $this->option('limit');
        $limit = $limitOption > 0 ? min($limitOption, 1000) : 500;

        $documentId = $this->option('document') !== null
            ? (int) $this->option('document')
            : null;

        $candidateId = $this->option('candidate') !== null
            ? (int) $this->option('candidate')
            : null;

        $dryRun = (bool) $this->option('dry-run');

        $query = ProductIdentificationCandidate::query()
            ->with(['documentLine.documentLineType', 'brand', 'category'])
            ->orderByDesc('id')
            ->limit($limit);

        if ($documentId !== null && $documentId > 0) {
            $query->where('document_id', $documentId);
        }

        if ($candidateId !== null && $candidateId > 0) {
            $query->where('id', $candidateId);
        }

        $candidates = $query->get();

        if ($candidates->isEmpty()) {
            $this->warn('Nessun candidato trovato per i filtri indicati.');

            return self::SUCCESS;
        }

        $rows = [];
        $updated = 0;
        $matched = 0;

        foreach ($candidates as $candidate) {
            if (! $candidate->documentLine) {
                $rows[] = [
                    $candidate->document_id,
                    $candidate->id,
                    Str::limit((string) $candidate->name, 36),
                    'SKIP',
                    'missing document line',
                    '-',
                    '-',
                    '-',
                ];

                continue;
            }

            $linePatterns = $this->initialKnowledgeRepository->matchLinePatterns(
                description: (string) ($candidate->documentLine->description ?: $candidate->name),
                rawText: (string) ($candidate->documentLine->raw_text ?: $candidate->name),
                documentLineTypeCode: $candidate->documentLine->documentLineType?->code,
            );

            $summary = $this->initialKnowledgeRepository->summarizeLinePatternMatches($linePatterns);

            $brandKnowledge = $this->resolveBrandFromInitialKnowledge((string) $candidate->name);
            $categoryKnowledge = $this->resolveCategoryFromInitialKnowledge($summary);

            $hasMatch = (bool) ($summary['matched'] ?? false)
                || (bool) ($brandKnowledge['matched'] ?? false)
                || (bool) ($categoryKnowledge['matched'] ?? false);

            if ($hasMatch) {
                $matched++;
            }

            $metadata = $candidate->metadata ?? [];

            $metadata['product_understanding_brand'] = $brandKnowledge;
            $metadata['product_understanding_category'] = $categoryKnowledge;
            $metadata['product_understanding_initial_knowledge'] = [
                'source' => 'initial_knowledge_pack_v1',
                'summary' => $summary,
                'line_patterns' => $linePatterns,
                'refreshed_by' => 'product-vault:refresh-initial-knowledge',
            ];

            $updates = [
                'metadata' => $metadata,
            ];

            if (($brandKnowledge['matched'] ?? false) === true && $candidate->brand_id === null) {
                $updates['brand_id'] = $brandKnowledge['brand_id'];
            }

            if (($categoryKnowledge['matched'] ?? false) === true && $candidate->category_id === null) {
                $updates['category_id'] = $categoryKnowledge['category_id'];
            }

            if (! $dryRun) {
                $candidate->update($updates);
                $updated++;
            }

            $rows[] = [
                $candidate->document_id,
                $candidate->id,
                Str::limit((string) $candidate->name, 36),
                $dryRun ? 'DRY' : 'OK',
                $hasMatch ? 'matched' : 'no match',
                $brandKnowledge['brand_name'] ?? '-',
                $categoryKnowledge['category_slug'] ?? '-',
                $summary['best_positive_pattern'] ?? '-',
            ];
        }

        $this->table([
            'Doc',
            'Cand',
            'Nome',
            'Status',
            'Result',
            'Brand',
            'Categoria',
            'Pattern',
        ], $rows);

        $this->info($dryRun
            ? 'Dry-run completato. Nessun dato è stato modificato.'
            : "Refresh completato. Candidati aggiornati: {$updated}. Candidati con match: {$matched}."
        );

        return self::SUCCESS;
    }

    /**
     * Risolve un brand globale usando prima alias iniziali e poi nomi brand.
     */
    private function resolveBrandFromInitialKnowledge(string $candidateName): array
    {
        $normalizedCandidateName = $this->normalize($candidateName);

        if ($normalizedCandidateName === '') {
            return $this->emptyBrandKnowledge();
        }

        foreach ($this->initialKnowledgeRepository->brandAliases() as $alias) {
            if (! is_array($alias)) {
                continue;
            }

            $normalizedAlias = $this->normalize((string) ($alias['normalized_alias'] ?? $alias['alias'] ?? ''));

            if ($normalizedAlias === '' || ! $this->containsToken($normalizedCandidateName, $normalizedAlias)) {
                continue;
            }

            $brand = Brand::query()
                ->whereNull('team_id')
                ->where('normalized_name', $alias['brand_normalized_name'] ?? null)
                ->where('is_active', true)
                ->first();

            if ($brand) {
                return [
                    'matched' => true,
                    'match_type' => 'initial_brand_alias',
                    'brand_id' => $brand->id,
                    'brand_name' => $brand->name,
                    'brand_normalized_name' => $brand->normalized_name,
                    'matched_text' => $alias['alias'] ?? $normalizedAlias,
                    'source' => 'initial_knowledge_pack_v1',
                ];
            }
        }

        $brands = Brand::query()
            ->whereNull('team_id')
            ->where('is_active', true)
            ->get();

        foreach ($brands as $brand) {
            $normalizedBrand = $this->normalize((string) $brand->normalized_name);

            if ($normalizedBrand === '' || ! $this->containsToken($normalizedCandidateName, $normalizedBrand)) {
                continue;
            }

            return [
                'matched' => true,
                'match_type' => 'initial_brand_name',
                'brand_id' => $brand->id,
                'brand_name' => $brand->name,
                'brand_normalized_name' => $brand->normalized_name,
                'matched_text' => $brand->name,
                'source' => 'initial_knowledge_pack_v1',
            ];
        }

        return $this->emptyBrandKnowledge();
    }

    /**
     * Risolve una categoria globale partendo dalla summary dei line pattern.
     */
    private function resolveCategoryFromInitialKnowledge(array $summary): array
    {
        $suggestedCategorySlug = trim((string) ($summary['best_suggested_category_slug'] ?? ''));

        if ($suggestedCategorySlug === '') {
            return $this->emptyCategoryKnowledge(null, $summary);
        }

        $category = Category::query()
            ->whereNull('team_id')
            ->where('slug', $suggestedCategorySlug)
            ->where('is_active', true)
            ->first();

        if (! $category) {
            return $this->emptyCategoryKnowledge($suggestedCategorySlug, $summary);
        }

        return [
            'matched' => true,
            'match_type' => 'initial_line_pattern_summary',
            'category_id' => $category->id,
            'category_name' => $category->name,
            'category_slug' => $category->slug,
            'suggested_category_slug' => $suggestedCategorySlug,
            'source_pattern' => $summary['best_positive_pattern'] ?? null,
            'source' => 'initial_knowledge_pack_v1',
        ];
    }

    /**
     * Payload brand vuoto ma stabile.
     */
    private function emptyBrandKnowledge(): array
    {
        return [
            'matched' => false,
            'match_type' => null,
            'brand_id' => null,
            'brand_name' => null,
            'brand_normalized_name' => null,
            'matched_text' => null,
            'source' => 'initial_knowledge_pack_v1',
        ];
    }

    /**
     * Payload categoria vuoto ma stabile.
     */
    private function emptyCategoryKnowledge(?string $suggestedCategorySlug, array $summary): array
    {
        return [
            'matched' => false,
            'match_type' => $suggestedCategorySlug !== null ? 'initial_line_pattern_summary' : null,
            'category_id' => null,
            'category_name' => null,
            'category_slug' => null,
            'suggested_category_slug' => $suggestedCategorySlug,
            'source_pattern' => $summary['best_positive_pattern'] ?? null,
            'source' => 'initial_knowledge_pack_v1',
        ];
    }

    /**
     * Normalizza una stringa per matching lessicale prudente.
     */
    private function normalize(string $value): string
    {
        $value = mb_strtolower($value);
        $value = preg_replace('/[^a-z0-9à-ÿ]+/u', ' ', $value) ?: $value;

        return trim(preg_replace('/\s+/', ' ', $value) ?: $value);
    }

    /**
     * Cerca un token/frase evitando match dentro parole più lunghe.
     */
    private function containsToken(string $text, string $token): bool
    {
        $text = $this->normalize($text);
        $token = $this->normalize($token);

        if ($text === '' || $token === '') {
            return false;
        }

        return preg_match('/(^|\s)'.preg_quote($token, '/').'($|\s)/u', $text) === 1;
    }
}