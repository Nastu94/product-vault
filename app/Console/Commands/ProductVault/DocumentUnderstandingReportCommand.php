<?php

namespace App\Console\Commands\ProductVault;

use App\Models\Document;
use App\Models\DocumentLine;
use App\Models\ProductIdentificationCandidate;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

#[Signature('product-vault:document-understanding-report
    {--document= : Limita report a un singolo document_id}
    {--filename= : Filtra per original_filename, anche parziale}
    {--status= : Filtra per status documento}
    {--type= : Filtra per document type code}
    {--limit=30 : Numero massimo di documenti da mostrare}
    {--only-issues : Mostra solo documenti con problemi potenziali}
    {--show-candidates : Mostra anche il dettaglio candidati}
    {--include-synthetic : Include i documenti sintetici generati dai fixture test}')]
#[Description('Report read-only sulla qualità di parsing, candidati, knowledge e amount consistency per documento')]
class DocumentUnderstandingReportCommand extends Command
{
    /**
     * Genera un report sintetico sulla qualità del riconoscimento documenti.
     *
     * Il comando è read-only:
     * - non rilancia la pipeline;
     * - non modifica righe;
     * - non modifica candidati;
     * - non modifica prodotti;
     * - non aggiorna metadata.
     */
    public function handle(): int
    {   
        /*
        |--------------------------------------------------------------------------
        | Normalizza opzioni
        |--------------------------------------------------------------------------
        |
        | Normalizza le opzioni di comando, con valori di default e limiti.
        */
        $limit = $this->normalizedLimit();
        $documentId = $this->normalizedPositiveIntegerOption('document');
        $filename = trim((string) ($this->option('filename') ?? ''));
        $status = trim((string) ($this->option('status') ?? ''));
        $type = trim((string) ($this->option('type') ?? ''));
        $onlyIssues = (bool) $this->option('only-issues');
        $showCandidates = (bool) $this->option('show-candidates');
        $includeSynthetic = (bool) $this->option('include-synthetic');

        $query = Document::query()
            ->with([
                'documentType',
                'merchant',
                'lines.documentLineType',
                'productIdentificationCandidates.brand',
                'productIdentificationCandidates.category',
            ])
            ->orderByDesc('id');

        if ($documentId !== null) {
            $query->whereKey($documentId);
        }

        if ($filename !== '') {
            $query->where('original_filename', 'like', '%' . $filename . '%');
        }

        if ($status !== '') {
            $query->where('status', $status);
        }

        if ($type !== '') {
            $query->whereHas(
                'documentType',
                fn ($documentTypeQuery) => $documentTypeQuery->where('code', $type)
            );
        }

        if (! $includeSynthetic) {
            $query->where('original_filename', 'not like', 'synthetic-%');
        }

        /*
        |--------------------------------------------------------------------------
        | Fetch prudente per --only-issues
        |--------------------------------------------------------------------------
        |
        | Il filtro "solo problemi" viene calcolato in memoria perché dipende da
        | relazioni e metadata JSON. Prendiamo quindi un po' più documenti e poi
        | tagliamo al limite richiesto.
        */
        $fetchLimit = $onlyIssues ? min($limit * 10, 500) : $limit;

        $documents = $query
            ->limit($fetchLimit)
            ->get();

        if ($documents->isEmpty()) {
            $this->warn('Nessun documento trovato per i filtri indicati.');

            return self::SUCCESS;
        }

        $reports = $documents
            ->map(fn (Document $document): array => $this->documentReport($document))
            ->when(
                $onlyIssues,
                fn (Collection $reports): Collection => $reports->filter(
                    fn (array $report): bool => $report['issue_count'] > 0
                )
            )
            ->take($limit)
            ->values();

        if ($reports->isEmpty()) {
            $this->warn('Nessun documento con problemi potenziali per i filtri indicati.');

            return self::SUCCESS;
        }

        $this->table([
            'Doc',
            'File',
            'Type',
            'Status',
            'Lines',
            'Cand',
            'Knowledge',
            'Amount',
            'Recovery',
            'Gaps',
            'Issues',
        ], $reports
            ->map(fn (array $report): array => [
                $report['document_id'],
                Str::limit($report['filename'], 42),
                $report['type'],
                $report['status'],
                $report['lines_label'],
                $report['candidates_label'],
                $report['knowledge_label'],
                $report['amount_label'],
                $report['recovery_label'],
                $report['gaps_label'],
                $report['issues_label'],
            ])
            ->all());

        if ($showCandidates) {
            $candidateRows = $reports
                ->flatMap(fn (array $report): array => $this->candidateRows($report['document']))
                ->values();

            if ($candidateRows->isNotEmpty()) {
                $this->newLine();

                $this->table([
                    'Doc',
                    'Cand',
                    'Review',
                    'Name',
                    'Brand',
                    'Category',
                    'Price',
                    'IK',
                    'Fuzzy',
                    'GF',
                    'Amount',
                    'Recovery',
                    'Warnings',
                ], $candidateRows->all());
            }
        }

        $this->info('Document understanding report completato. Nessun dato è stato modificato.');

        return self::SUCCESS;
    }

    /**
     * Costruisce il report sintetico per un documento.
     *
     * @return array<string, mixed>
     */
    private function documentReport(Document $document): array
    {
        $lines = $document->lines;
        $productLines = $lines->filter(
            fn (DocumentLine $line): bool => $line->documentLineType?->code === 'product'
        );

        $candidates = $document->productIdentificationCandidates;

        $pendingCandidates = $candidates->where('review_status', 'pending');
        $confirmedCandidates = $candidates->filter(
            fn (ProductIdentificationCandidate $candidate): bool => $candidate->isConfirmed()
        );
        $ignoredCandidates = $candidates->where('review_status', 'ignored');

        $amountChecked = $productLines->filter(fn (DocumentLine $line): bool => $this->lineAmountChecked($line))->count();
        $amountMismatch = $productLines->filter(fn (DocumentLine $line): bool => $this->lineAmountMismatch($line))->count();
        $amountOk = $productLines->filter(fn (DocumentLine $line): bool => $this->lineAmountConsistent($line))->count();
        $amountSkipped = max($productLines->count() - $amountChecked, 0);

        $lineRecoveries = $productLines
            ->filter(fn (DocumentLine $line): bool => data_get($line->metadata, 'quantity_recovered_from_amount_mismatch') === true)
            ->count();

        $candidateRecoveries = $candidates
            ->filter(fn (ProductIdentificationCandidate $candidate): bool => data_get($candidate->metadata, 'quantity_recovered_from_amount_mismatch') === true)
            ->count();

        $initialKnowledgeMatched = $candidates
            ->filter(fn (ProductIdentificationCandidate $candidate): bool => data_get(
                $candidate->metadata,
                'product_understanding_initial_knowledge.summary.matched'
            ) === true)
            ->count();

        $initialKnowledgeFuzzy = $candidates
            ->filter(fn (ProductIdentificationCandidate $candidate): bool => data_get(
                $candidate->metadata,
                'product_understanding_initial_knowledge.summary.has_fuzzy_positive_match'
            ) === true)
            ->count();

        $globalFactsMatched = $candidates
            ->filter(fn (ProductIdentificationCandidate $candidate): bool => data_get(
                $candidate->metadata,
                'product_understanding_global_fact.matched'
            ) === true)
            ->count();

        $pythonWarnings = $candidates
            ->filter(fn (ProductIdentificationCandidate $candidate): bool => count((array) data_get(
                $candidate->metadata,
                'product_understanding_python.warnings',
                []
            )) > 0)
            ->count();

        $pendingWithoutBrand = $pendingCandidates
            ->filter(fn (ProductIdentificationCandidate $candidate): bool => $candidate->brand_id === null)
            ->count();

        $pendingWithoutCategory = $pendingCandidates
            ->filter(fn (ProductIdentificationCandidate $candidate): bool => $candidate->category_id === null)
            ->count();

        $issues = $this->documentIssues(
            document: $document,
            productLines: $productLines,
            candidates: $candidates,
            amountMismatch: $amountMismatch
        );

        return [
            'document' => $document,
            'document_id' => $document->id,
            'filename' => (string) $document->original_filename,
            'type' => $document->documentType?->code ?? '-',
            'status' => (string) $document->status,
            'issue_count' => count($issues),
            'lines_label' => sprintf(
                '%d/%d product',
                $productLines->count(),
                $lines->count()
            ),
            'candidates_label' => sprintf(
                'P:%d C:%d I:%d T:%d',
                $pendingCandidates->count(),
                $confirmedCandidates->count(),
                $ignoredCandidates->count(),
                $candidates->count()
            ),
            'knowledge_label' => sprintf(
                'IK:%d FZ:%d GF:%d PYw:%d',
                $initialKnowledgeMatched,
                $initialKnowledgeFuzzy,
                $globalFactsMatched,
                $pythonWarnings
            ),
            'amount_label' => sprintf(
                'OK:%d MIS:%d SKIP:%d',
                $amountOk,
                $amountMismatch,
                $amountSkipped
            ),
            'recovery_label' => sprintf(
                'L:%d C:%d',
                $lineRecoveries,
                $candidateRecoveries
            ),
            'gaps_label' => sprintf(
                'noBrand:%d noCat:%d',
                $pendingWithoutBrand,
                $pendingWithoutCategory
            ),
            'issues_label' => $issues === [] ? '-' : implode(', ', $issues),
        ];
    }

    /**
     * Rileva problemi potenziali senza modificare dati.
     *
     * @param  Collection<int, DocumentLine>  $productLines
     * @param  Collection<int, ProductIdentificationCandidate>  $candidates
     * @return array<int, string>
     */
    private function documentIssues(
        Document $document,
        Collection $productLines,
        Collection $candidates,
        int $amountMismatch
    ): array {
        $issues = [];

        $documentType = $document->documentType?->code;

        if ($document->text_extraction_status !== 'completed') {
            $issues[] = 'text_not_completed';
        }

        if ($amountMismatch > 0) {
            $issues[] = 'amount_mismatch';
        }

        if (
            in_array($documentType, ['invoice', 'receipt', 'order_confirmation'], true)
            && $productLines->count() > 0
            && $candidates->count() === 0
        ) {
            $issues[] = 'product_lines_without_candidates';
        }

        if (
            in_array($documentType, ['invoice', 'receipt', 'order_confirmation'], true)
            && $document->status === 'needs_review'
            && $candidates->count() === 0
        ) {
            $issues[] = 'needs_review_without_candidates';
        }

        if ($document->status === 'failed') {
            $issues[] = 'document_failed';
        }

        return array_values(array_unique($issues));
    }

    /**
     * Costruisce righe dettaglio candidati.
     *
     * @return array<int, array<int, string|int|null>>
     */
    private function candidateRows(Document $document): array
    {
        return $document
            ->productIdentificationCandidates
            ->sortBy('id')
            ->map(fn (ProductIdentificationCandidate $candidate): array => [
                $candidate->document_id,
                $candidate->id,
                $candidate->review_status,
                Str::limit((string) $candidate->name, 42),
                $candidate->brand?->name ?? '-',
                $candidate->category?->slug ?? '-',
                $candidate->price !== null ? number_format((float) $candidate->price, 2, '.', '') : '-',
                $this->yesNo(data_get($candidate->metadata, 'product_understanding_initial_knowledge.summary.matched') === true),
                $this->yesNo(data_get($candidate->metadata, 'product_understanding_initial_knowledge.summary.has_fuzzy_positive_match') === true),
                $this->yesNo(data_get($candidate->metadata, 'product_understanding_global_fact.matched') === true),
                $this->candidateAmountLabel($candidate),
                $this->yesNo(data_get($candidate->metadata, 'quantity_recovered_from_amount_mismatch') === true),
                $this->candidateWarningsLabel($candidate),
            ])
            ->values()
            ->all();
    }

    /**
     * Verifica se la riga ha diagnostica importi controllata.
     */
    private function lineAmountChecked(DocumentLine $line): bool
    {
        return (bool) data_get($line->metadata, 'amount_consistency.checked', false);
    }

    /**
     * Verifica se la riga ha mismatch importi.
     */
    private function lineAmountMismatch(DocumentLine $line): bool
    {
        return $this->lineAmountChecked($line)
            && data_get($line->metadata, 'amount_consistency.is_consistent') === false;
    }

    /**
     * Verifica se la riga ha importi coerenti.
     */
    private function lineAmountConsistent(DocumentLine $line): bool
    {
        return $this->lineAmountChecked($line)
            && data_get($line->metadata, 'amount_consistency.is_consistent') === true;
    }

    /**
     * Etichetta sintetica amount consistency candidato.
     */
    private function candidateAmountLabel(ProductIdentificationCandidate $candidate): string
    {
        $amountConsistency = (array) data_get(
            $candidate->metadata,
            'document_line_amount_consistency',
            []
        );

        if ($amountConsistency === []) {
            return '-';
        }

        if (! (bool) data_get($amountConsistency, 'checked', false)) {
            return 'SKIP:' . (string) data_get($amountConsistency, 'reason', '-');
        }

        return data_get($amountConsistency, 'is_consistent') === true
            ? 'OK'
            : 'MIS:' . (string) data_get($amountConsistency, 'reason', '-');
    }

    /**
     * Etichetta sintetica warning candidato.
     */
    private function candidateWarningsLabel(ProductIdentificationCandidate $candidate): string
    {
        $warnings = array_values(array_filter([
            ...((array) data_get($candidate->metadata, 'product_understanding.warnings', [])),
            ...((array) data_get($candidate->metadata, 'product_understanding_python.warnings', [])),
        ]));

        if ($warnings === []) {
            return '-';
        }

        return collect($warnings)
            ->unique()
            ->take(3)
            ->implode(', ');
    }

    /**
     * Normalizza il limite massimo mostrato.
     */
    private function normalizedLimit(): int
    {
        $limit = (int) $this->option('limit');

        return $limit > 0 ? min($limit, 200) : 30;
    }

    /**
     * Restituisce una option intera positiva o null.
     */
    private function normalizedPositiveIntegerOption(string $name): ?int
    {
        $value = $this->option($name);

        if ($value === null || $value === '') {
            return null;
        }

        $value = (int) $value;

        return $value > 0 ? $value : null;
    }

    /**
     * Converte booleano in etichetta compatta.
     */
    private function yesNo(bool $value): string
    {
        return $value ? 'yes' : '-';
    }
}