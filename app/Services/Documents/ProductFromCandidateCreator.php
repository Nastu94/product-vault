<?php

namespace App\Services\Documents;

use App\Models\DocumentRelationshipType;
use App\Models\IdentificationStatus;
use App\Models\Product;
use App\Models\ProductIdentificationCandidate;
use App\Services\Documents\AssistedReview\AssistedReviewConfirmationGuard;
use App\Services\Documents\ProductUnderstanding\ProductUnderstandingFeedbackRecorder;
use App\Services\Warranties\DefaultWarrantyCreator;
use App\Services\Products\ProductLifecycleEventRecorder;
use Illuminate\Support\Facades\DB;

class ProductFromCandidateCreator
{
    /**
     * Service per creare un prodotto a partire da un candidato confermato.
     *
     * Questo service rappresenta il passaggio:
     * candidato automatico -> prodotto reale confermato dall'utente.
     */
    public function __construct(
        private readonly ProductUnderstandingFeedbackRecorder $feedbackRecorder,
        private readonly DefaultWarrantyCreator $defaultWarrantyCreator,
        private readonly ProductLifecycleEventRecorder $eventRecorder,
        private readonly AssistedReviewConfirmationGuard $confirmationGuard,
    ) {
    }

    /**
     * Crea un prodotto confermato partendo da un candidato prodotto.
     *
     * Questo service rappresenta il passaggio:
     * candidato automatico -> prodotto reale confermato dall'utente.
     */
    public function create(ProductIdentificationCandidate $candidate, int $userId): Product
    {
        return DB::transaction(function () use ($candidate, $userId) {
            $candidate->load([
                'document.currency',
                'document.merchant',
                'document.documentType',
                'documentLine',
            ]);

            $document = $candidate->document;

            if (! $document) {
                throw new \RuntimeException('Documento non trovato per il candidato prodotto.');
            }

            if ($candidate->product_id) {
                throw new \RuntimeException('Questo candidato è già stato trasformato in prodotto.');
            }

            /*
            * La conferma deve passare dal guardrail centrale, indipendentemente
            * dalla pagina o dal componente che ha richiesto la creazione.
            */
            $this->confirmationGuard->ensureCanConfirm(
                $candidate
            );

            $identificationStatusId = IdentificationStatus::query()
                ->where('code', 'user_confirmed')
                ->value('id');

            $relationshipTypeId = DocumentRelationshipType::query()
                ->where('code', $this->guessRelationshipTypeCode($document->documentType?->code))
                ->value('id');

            /*
            |--------------------------------------------------------------------------
            | Creazione prodotto
            |--------------------------------------------------------------------------
            |
            | Ogni candidato confermato genera un prodotto distinto.
            | Questo consente a fatture e scontrini multi-prodotto di creare più
            | schede prodotto dallo stesso documento.
            |
            */
            $product = Product::query()->create([
                'team_id' => $document->team_id,
                'created_by_user_id' => $userId,
                'category_id' => $candidate->category_id,
                'brand_id' => $candidate->brand_id,
                'merchant_id' => $document->merchant_id,
                'identification_status_id' => $identificationStatusId,
                'currency_id' => $document->currency_id,
                'name' => $candidate->name,
                'model' => $candidate->model,
                'serial_number' => $candidate->serial_number,
                'ean_code' => $candidate->ean_code,
                'purchase_date' => $document->purchase_date,
                'purchase_price' => $candidate->price,
                'reliability_score' => $this->estimateReliabilityScore($candidate),
                'notes' => null,
            ]);

            $product->documents()->attach($document->id, [
                'relationship_type_id' => $relationshipTypeId,
                'linked_by_user_id' => $userId,
                'notes' => 'Prodotto creato da candidato generato automaticamente e confermato dall’utente.',
            ]);

            /*
            |--------------------------------------------------------------------------
            | Stato candidato
            |--------------------------------------------------------------------------
            |
            | Non deselezioniamo più gli altri candidati del documento.
            | Ogni candidato può essere confermato indipendentemente dagli altri.
            |
            */
            $candidate->update([
                'is_selected' => true,
                'product_id' => $product->id,
                'review_status' => 'confirmed',
                'reviewed_by_user_id' => $userId,
                'reviewed_at' => now(),
            ]);

            $candidate->refresh();

            $this->eventRecorder->recordProductCreatedFromCandidate(
                product: $product,
                userId: $userId,
                documentId: $document->id,
                metadata: [
                    'candidate_id' => $candidate->id,
                    'document_line_id' => $candidate->document_line_id,
                    'candidate_name' => $candidate->name,
                    'candidate_ean_code' => $candidate->ean_code,
                    'candidate_serial_number' => $candidate->serial_number,
                ],
            );

            $this->feedbackRecorder->recordConfirmedCandidate(
                candidate: $candidate,
                product: $product,
                userId: $userId,
            );

            $warranty = $this->defaultWarrantyCreator->createForProduct($product);

            if ($warranty && $warranty->wasRecentlyCreated) {
                $this->eventRecorder->recordAutomaticWarrantyCreated(
                    warranty: $warranty,
                    userId: $userId,
                );
            }

            $this->updateDocumentStatusAfterCandidateConfirmation($document);

            return $product->refresh();
        });
    }

    /**
     * Aggiorna lo stato del documento dopo la conferma di un candidato.
     *
     * Se restano candidati non ancora collegati, il documento deve restare
     * revisionabile. Solo quando tutti i candidati sono stati collegati o rimossi
     * può diventare linked_to_product.
     */
    private function updateDocumentStatusAfterCandidateConfirmation($document): void
    {
        $pendingCandidatesCount = ProductIdentificationCandidate::query()
            ->where('document_id', $document->id)
            ->where('review_status', 'pending')
            ->whereNull('product_id')
            ->count();

        $linkedCandidatesCount = ProductIdentificationCandidate::query()
            ->where('document_id', $document->id)
            ->where('review_status', 'confirmed')
            ->whereNotNull('product_id')
            ->count();

        $bestLinkedCandidateScore = ProductIdentificationCandidate::query()
            ->where('document_id', $document->id)
            ->whereNotNull('product_id')
            ->max('confidence_score');

        $document->update([
            'status' => $pendingCandidatesCount > 0
                ? 'needs_review'
                : ($linkedCandidatesCount > 0 ? 'linked_to_product' : 'parsed'),
            'product_reliability_score' => $bestLinkedCandidateScore !== null
                ? (int) $bestLinkedCandidateScore
                : null,
        ]);
    }

    /**
     * Decide il tipo di relazione documento-prodotto.
     */
    private function guessRelationshipTypeCode(?string $documentTypeCode): string
    {
        return match ($documentTypeCode) {
            'receipt', 'invoice', 'order_confirmation' => 'purchase_proof',
            'warranty_certificate', 'extended_warranty' => 'warranty_proof',
            'manual' => 'manual',
            'repair_document', 'service_quote' => 'repair_history',
            'serial_number_photo' => 'serial_number_evidence',
            default => 'other',
        };
    }

    /**
     * Stima iniziale dell'affidabilità prodotto.
     *
     * Non è ancora lo scorer definitivo: serve a dare un valore sensato
     * al prodotto appena creato da un candidato confermato.
     */
    private function estimateReliabilityScore(ProductIdentificationCandidate $candidate): int
    {
        $score = 30;

        if ($candidate->name) {
            $score += 20;
        }

        if ($candidate->model) {
            $score += 15;
        }

        if ($candidate->ean_code) {
            $score += 20;
        }

        if ($candidate->price !== null) {
            $score += 10;
        }

        if ($candidate->document?->purchase_date) {
            $score += 10;
        }

        if ($candidate->document?->merchant_id) {
            $score += 10;
        }

        /*
        |--------------------------------------------------------------------------
        | Bonus conferma utente
        |--------------------------------------------------------------------------
        |
        | La conferma manuale aumenta l'affidabilità, perché l'utente sta dicendo
        | che il candidato è corretto.
        |
        */
        $score += 15;

        return min($score, 100);
    }
}