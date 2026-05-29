<?php

namespace App\Services\Documents;

use App\Models\DocumentRelationshipType;
use App\Models\IdentificationStatus;
use App\Models\Product;
use App\Models\ProductIdentificationCandidate;
use Illuminate\Support\Facades\DB;

class ProductFromCandidateCreator
{
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
                'documentLine',
            ]);

            $document = $candidate->document;

            if (! $document) {
                throw new \RuntimeException('Documento non trovato per il candidato prodotto.');
            }

            $identificationStatusId = IdentificationStatus::query()
                ->where('code', 'user_confirmed')
                ->value('id');

            $relationshipTypeId = DocumentRelationshipType::query()
                ->where('code', $this->guessRelationshipTypeCode($document->documentType?->code))
                ->value('id');

            /*
            |--------------------------------------------------------------------------
            | Selezione candidato
            |--------------------------------------------------------------------------
            |
            | Per ora consideriamo un solo candidato selezionato per documento.
            | Quando gestiremo documenti multi-prodotto, potremo permettere più
            | candidati selezionati nello stesso documento.
            |
            */
            ProductIdentificationCandidate::query()
                ->where('document_id', $document->id)
                ->update(['is_selected' => false]);

            $candidate->update([
                'is_selected' => true,
            ]);

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

            $candidate->update([
                'product_id' => $product->id,
            ]);

            $document->update([
                'status' => 'linked_to_product',
                'product_reliability_score' => $product->reliability_score,
            ]);

            return $product->refresh();
        });
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