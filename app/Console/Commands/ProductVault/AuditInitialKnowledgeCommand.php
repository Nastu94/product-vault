<?php

namespace App\Console\Commands\ProductVault;

use App\Models\ProductIdentificationCandidate;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

#[Signature('product-vault:audit-initial-knowledge
    {--document= : Limita audit a un singolo document_id}
    {--limit=50 : Numero massimo di candidati da mostrare}
    {--only-matched : Mostra solo candidati con match da initial knowledge}')]
#[Description('Audit read-only dei match prodotti dalla initial Product Vault knowledge base')]
class AuditInitialKnowledgeCommand extends Command
{
    /**
     * Mostra come la initial knowledge base sta arricchendo i candidati prodotto.
     *
     * Il comando è solo diagnostico:
     * - non crea candidati;
     * - non modifica prodotti;
     * - non aggiorna metadata;
     * - non cambia score o review_status.
     */
    public function handle(): int
    {
        $limitOption = (int) $this->option('limit');
        $limit = $limitOption > 0 ? min($limitOption, 500) : 50;

        $documentId = $this->option('document') !== null
            ? (int) $this->option('document')
            : null;

        $query = ProductIdentificationCandidate::query()
            ->with([
                'document',
                'documentLine.documentLineType',
                'brand',
                'category',
            ])
            ->orderByDesc('id');

        if ($documentId !== null && $documentId > 0) {
            $query->where('document_id', $documentId);
        }

        /*
        |--------------------------------------------------------------------------
        | Fetch prudente
        |--------------------------------------------------------------------------
        |
        | Se chiediamo solo i match, prendiamo qualche candidato in più e poi
        | filtriamo in memoria. Evitiamo query JSON fragili tra database diversi.
        |
        */
        $fetchLimit = $this->option('only-matched')
            ? min($limit * 5, 1000)
            : $limit;

        $candidates = $query
            ->limit($fetchLimit)
            ->get();

        if ($this->option('only-matched')) {
            $candidates = $candidates
                ->filter(fn (ProductIdentificationCandidate $candidate): bool => $this->candidateHasInitialKnowledgeMatch($candidate))
                ->take($limit)
                ->values();
        }

        if ($candidates->isEmpty()) {
            $this->warn('Nessun candidato trovato per i filtri indicati.');

            return self::SUCCESS;
        }

        $rows = $candidates
            ->map(fn (ProductIdentificationCandidate $candidate): array => $this->candidateToAuditRow($candidate))
            ->values()
            ->all();

        $this->table([
            'Doc',
            'Cand',
            'Nome',
            'Line type',
            'Brand',
            'Categoria',
            'Pattern',
            'Match',
            'Matched text',
            'Sim',
            'Kind',
            'Signals',
        ], $rows);

        $this->info('Audit initial knowledge completato. Nessun dato è stato modificato.');

        return self::SUCCESS;
    }

    /**
     * Verifica se il candidato ha almeno un match dalla initial knowledge base.
     */
    private function candidateHasInitialKnowledgeMatch(ProductIdentificationCandidate $candidate): bool
    {
        $metadata = $candidate->metadata ?? [];

        return (bool) data_get($metadata, 'product_understanding_brand.matched', false)
            || (bool) data_get($metadata, 'product_understanding_category.matched', false)
            || (bool) data_get($metadata, 'product_understanding_initial_knowledge.summary.matched', false);
    }

    /**
     * Trasforma un candidato in una riga tabellare leggibile.
     */
    private function candidateToAuditRow(ProductIdentificationCandidate $candidate): array
    {
        $metadata = $candidate->metadata ?? [];

        $brandKnowledge = (array) data_get($metadata, 'product_understanding_brand', []);
        $categoryKnowledge = (array) data_get($metadata, 'product_understanding_category', []);
        $summary = (array) data_get($metadata, 'product_understanding_initial_knowledge.summary', []);
        $patterns = collect(data_get($metadata, 'product_understanding_initial_knowledge.line_patterns', []));

        $bestPatternName = $summary['best_positive_pattern'] ?? null;

        $bestPattern = $patterns->first(
            fn ($pattern): bool => is_array($pattern)
                && $bestPatternName !== null
                && ($pattern['pattern'] ?? null) === $bestPatternName
        );

        if (! is_array($bestPattern)) {
            $bestPattern = $patterns->first(fn ($pattern): bool => is_array($pattern)) ?: [];
        }

        $signals = collect($summary['signals'] ?? [])
            ->take(3)
            ->implode(', ');

        return [
            $candidate->document_id,
            $candidate->id,
            Str::limit((string) $candidate->name, 42),
            $candidate->documentLine?->documentLineType?->code ?? '-',
            $candidate->brand?->name
                ?? ($brandKnowledge['brand_name'] ?? '-'),
            $candidate->category?->slug
                ?? ($categoryKnowledge['category_slug'] ?? '-'),
            $bestPattern['pattern'] ?? ($summary['best_positive_pattern'] ?? '-'),
            $bestPattern['match_type'] ?? '-',
            isset($bestPattern['matched_text'])
                ? Str::limit((string) $bestPattern['matched_text'], 24)
                : '-',
            isset($bestPattern['similarity'])
                ? (string) $bestPattern['similarity']
                : '-',
            $summary['best_product_kind'] ?? '-',
            $signals !== '' ? $signals : '-',
        ];
    }
}