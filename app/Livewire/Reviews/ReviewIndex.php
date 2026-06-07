<?php

namespace App\Livewire\Reviews;

use App\Models\Document;
use App\Models\ProductIdentificationCandidate;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\WithPagination;

class ReviewIndex extends Component
{
    use WithPagination;

    /**
     * Numero di candidati mostrati per pagina.
     */
    public int $perPage = 10;

    /**
     * Filtro principale della revisione.
     */
    public string $filter = 'pending';

    /**
     * Mantiene il filtro nella query string.
     */
    protected array $queryString = [
        'filter' => ['except' => 'pending'],
    ];

    /**
     * Reset paginazione quando cambia filtro.
     */
    public function updatedFilter(): void
    {
        $this->resetPage();
    }

    /**
     * Team/workspace corrente.
     */
    private function currentTeamId(): ?int
    {
        $user = Auth::user();

        return $user?->current_team_id ?? $user?->currentTeam?->id;
    }

    /**
     * Query base sui candidati del workspace corrente.
     */
    private function baseCandidateQuery(): Builder
    {
        $teamId = $this->currentTeamId();

        return ProductIdentificationCandidate::query()
            ->whereHas('document', fn (Builder $query) => $query->where('team_id', $teamId));
    }

    /**
     * Query base sui documenti del workspace corrente.
     */
    private function baseDocumentQuery(): Builder
    {
        return Document::query()
            ->where('team_id', $this->currentTeamId());
    }

    /**
     * Applica filtro ai candidati.
     */
    private function applyCandidateFilter(Builder $query): Builder
    {
        return match ($this->filter) {
            'low_confidence' => $query
                ->where('review_status', 'pending')
                ->whereNull('product_id')
                ->where(function (Builder $query): void {
                    $query->whereNull('confidence_score')
                        ->orWhere('confidence_score', '<', 80);
                }),

            'python_warnings' => $query
                ->where('review_status', 'pending')
                ->whereNull('product_id')
                ->whereRaw("JSON_LENGTH(JSON_EXTRACT(metadata, '$.product_understanding_python.warnings')) > 0"),

            'global_fact' => $query
                ->where('review_status', 'pending')
                ->whereNull('product_id')
                ->whereRaw("JSON_EXTRACT(metadata, '$.product_understanding_global_fact.matched') = true"),

            'reviewed' => $query
                ->whereIn('review_status', ['confirmed', 'ignored']),

            default => $query
                ->where('review_status', 'pending')
                ->whereNull('product_id'),
        };
    }

    /**
     * Etichetta leggibile stato candidato.
     */
    public function candidateReviewStatusLabel(ProductIdentificationCandidate $candidate): string
    {
        return match ($candidate->review_status) {
            'pending' => 'Da revisionare',
            'confirmed' => 'Confermato',
            'ignored' => 'Ignorato',
            default => ucfirst((string) $candidate->review_status),
        };
    }

    /**
     * Classi badge stato candidato.
     */
    public function candidateReviewStatusBadgeClasses(ProductIdentificationCandidate $candidate): string
    {
        return match ($candidate->review_status) {
            'pending' => 'bg-orange-50 text-orange-700 ring-orange-600/20',
            'confirmed' => 'bg-green-50 text-green-700 ring-green-600/20',
            'ignored' => 'bg-gray-100 text-gray-700 ring-gray-500/20',
            default => 'bg-gray-100 text-gray-700 ring-gray-500/20',
        };
    }

    /**
     * Etichetta rischio/qualità conoscenza candidato.
     */
    public function candidateKnowledgeLabel(ProductIdentificationCandidate $candidate): string
    {
        $pythonWarnings = data_get($candidate->metadata, 'product_understanding_python.warnings', []);
        $globalFactMatched = data_get($candidate->metadata, 'product_understanding_global_fact.matched') === true;
        $feedbackBias = data_get($candidate->metadata, 'product_understanding_feedback.suggested_bias');

        if ($pythonWarnings !== []) {
            return 'Richiede attenzione';
        }

        if ($globalFactMatched) {
            return 'Conoscenza globale';
        }

        if (in_array($feedbackBias, ['positive', 'previously_confirmed'], true)) {
            return 'Feedback utile';
        }

        if (($candidate->confidence_score ?? 0) < 80) {
            return 'Bassa affidabilità';
        }

        return 'Standard';
    }

    /**
     * Classi badge conoscenza candidato.
     */
    public function candidateKnowledgeBadgeClasses(ProductIdentificationCandidate $candidate): string
    {
        return match ($this->candidateKnowledgeLabel($candidate)) {
            'Richiede attenzione' => 'bg-red-50 text-red-700 ring-red-600/20',
            'Conoscenza globale' => 'bg-indigo-50 text-indigo-700 ring-indigo-600/20',
            'Feedback utile' => 'bg-green-50 text-green-700 ring-green-600/20',
            'Bassa affidabilità' => 'bg-yellow-50 text-yellow-800 ring-yellow-600/20',
            default => 'bg-gray-100 text-gray-700 ring-gray-500/20',
        };
    }

    /**
     * Formatta un segnale tecnico per la UI.
     */
    public function formatSignal(?string $signal): string
    {
        if (! $signal) {
            return '—';
        }

        return ucfirst(str_replace('_', ' ', $signal));
    }

    /**
     * Renderizza pagina revisioni.
     */
    public function render(): View
    {
        abort_unless(Auth::user()?->can('documents.review'), 403);

        $baseCandidates = $this->baseCandidateQuery();
        $baseDocuments = $this->baseDocumentQuery();

        $summary = [
            'documents_needing_review' => (clone $baseDocuments)
                ->whereIn('status', ['needs_review', 'low_confidence'])
                ->count(),

            'pending_candidates' => (clone $baseCandidates)
                ->where('review_status', 'pending')
                ->whereNull('product_id')
                ->count(),

            'low_confidence_candidates' => (clone $baseCandidates)
                ->where('review_status', 'pending')
                ->whereNull('product_id')
                ->where(function (Builder $query): void {
                    $query->whereNull('confidence_score')
                        ->orWhere('confidence_score', '<', 80);
                })
                ->count(),

            'reviewed_candidates' => (clone $baseCandidates)
                ->whereIn('review_status', ['confirmed', 'ignored'])
                ->count(),
        ];

        $documentsNeedingReview = $this->baseDocumentQuery()
            ->with([
                'documentType',
                'merchant',
                'productIdentificationCandidates',
            ])
            ->whereIn('status', ['needs_review', 'low_confidence'])
            ->latest()
            ->limit(5)
            ->get();

        $candidatesQuery = $this->baseCandidateQuery()
            ->with([
                'document.documentType',
                'document.merchant',
                'document.currency',
                'documentLine',
                'product',
            ]);

        $this->applyCandidateFilter($candidatesQuery);

        $candidates = $candidatesQuery
            ->latest('document_id')
            ->latest('id')
            ->paginate($this->perPage);

        return view('livewire.reviews.review-index', [
            'summary' => $summary,
            'documentsNeedingReview' => $documentsNeedingReview,
            'candidates' => $candidates,
        ])->layout('layouts.app');
    }
}