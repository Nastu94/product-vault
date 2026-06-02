<?php

namespace App\Services\Documents\ProductUnderstanding;

use App\Models\Product;
use App\Models\ProductIdentificationCandidate;
use App\Models\ProductUnderstandingFeedback;
use Illuminate\Support\Str;

class ProductUnderstandingFeedbackRecorder
{
    /**
     * @param ProductLineAnalyzer $productLineAnalyzer
     * @param ProductUnderstandingGlobalFactUpdater $globalFactUpdater
     */
    public function __construct(
        private readonly ProductLineAnalyzer $productLineAnalyzer,
        private readonly ProductUnderstandingGlobalFactUpdater $globalFactUpdater,
    ) {
    }

    /**
     * Registra feedback positivo: candidato confermato e trasformato in prodotto.
     */
    public function recordConfirmedCandidate(
        ProductIdentificationCandidate $candidate,
        Product $product,
        int $userId
    ): ProductUnderstandingFeedback {
        return $this->record(
            candidate: $candidate,
            reviewStatus: 'confirmed',
            userId: $userId,
            product: $product,
            ignoredReason: null,
            ignoredNote: null,
        );
    }

    /**
     * Registra feedback negativo: candidato escluso dalla revisione.
     */
    public function recordIgnoredCandidate(
        ProductIdentificationCandidate $candidate,
        int $userId,
        ?string $reason = null,
        ?string $note = null
    ): ProductUnderstandingFeedback {
        return $this->record(
            candidate: $candidate,
            reviewStatus: 'ignored',
            userId: $userId,
            product: null,
            ignoredReason: $reason,
            ignoredNote: $note,
        );
    }

    /**
     * Salva o aggiorna il record feedback del candidato.
     */
    private function record(
        ProductIdentificationCandidate $candidate,
        string $reviewStatus,
        int $userId,
        ?Product $product = null,
        ?string $ignoredReason = null,
        ?string $ignoredNote = null,
    ): ProductUnderstandingFeedback {
        $candidate->loadMissing([
            'document.documentType',
            'documentLine',
        ]);

        $document = $candidate->document;

        if (! $document) {
            throw new \RuntimeException('Documento non trovato per il feedback Product Understanding.');
        }

        $line = $candidate->documentLine;
        $candidateMetadata = $candidate->metadata ?? [];

        /*
        |--------------------------------------------------------------------------
        | Product understanding fallback
        |--------------------------------------------------------------------------
        |
        | I candidati creati prima dell'introduzione di ProductLineAnalyzer possono
        | non avere ancora metadata.product_understanding.
        |
        | In quel caso ricalcoliamo l'analisi dalla DocumentLine al momento del
        | feedback, così il dataset resta utile anche per dati preesistenti.
        |
        */
        if (
            ! isset($candidateMetadata['product_understanding'])
            && $line !== null
        ) {
            $candidateMetadata['product_understanding'] = $this->productLineAnalyzer
                ->analyze($line)
                ->toMetadata();

            $candidate->update([
                'metadata' => $candidateMetadata,
            ]);

            $candidate->refresh();
            $candidateMetadata = $candidate->metadata ?? [];
        }

        $analysis = $candidateMetadata['product_understanding'] ?? [];

        $lineDescription = (string) ($line?->description ?? $candidate->name ?? '');
        $rawText = (string) (
            $candidateMetadata['raw_line_text']
            ?? $line?->raw_text
            ?? ''
        );

        $feedback = ProductUnderstandingFeedback::query()->updateOrCreate(
            [
                'candidate_id' => $candidate->id,
            ],
            [
                'team_id' => $document->team_id,
                'document_id' => $document->id,
                'document_line_id' => $line?->id,
                'product_id' => $product?->id,
                'reviewed_by_user_id' => $userId,

                'review_status' => $reviewStatus,
                'ignored_reason' => $ignoredReason,
                'ignored_note' => $ignoredNote,

                'candidate_name' => $candidate->name,
                'candidate_model' => $candidate->model,
                'candidate_serial_number' => $candidate->serial_number,
                'candidate_ean_code' => $candidate->ean_code,
                'candidate_price' => $candidate->price,

                'final_product_name' => $product?->name,

                'line_description' => $lineDescription,
                'normalized_line_description' => $this->normalizeDescription($lineDescription),
                'raw_text_hash' => $rawText !== '' ? hash('sha256', $rawText) : null,

                'analyzer_version' => $analysis['version'] ?? null,
                'analyzer_line_type' => $analysis['line_type'] ?? null,
                'analyzer_suggested_category' => $analysis['suggested_category'] ?? null,
                'registerable_score' => isset($analysis['registerable_score'])
                    ? (int) $analysis['registerable_score']
                    : null,
                'non_product_score' => isset($analysis['non_product_score'])
                    ? (int) $analysis['non_product_score']
                    : null,

                'signals' => $analysis['signals'] ?? [],
                'negative_signals' => $analysis['negative_signals'] ?? [],
                'warnings' => $analysis['warnings'] ?? [],
                'score_breakdown' => $analysis['score_breakdown'] ?? [],

                'metadata' => [
                    'candidate_metadata' => $candidateMetadata,
                    'document_type' => $document->documentType?->code,
                    'merchant_id' => $document->merchant_id,
                    'purchase_date' => $document->purchase_date?->toDateString(),
                    'feedback_recorder' => 'product_understanding_feedback_recorder_v1',
                    'analysis_was_present_on_candidate' => isset(($candidate->getOriginal('metadata') ?? [])['product_understanding']),
                ],

                'reviewed_at' => now(),
            ]
        );

        $this->globalFactUpdater->updateFromFeedback($feedback);

        return $feedback;
    }

    /**
     * Normalizza una descrizione per futuri confronti/fuzzy matching.
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
}