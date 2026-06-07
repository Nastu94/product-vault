<?php

namespace App\Services\Products;

use App\Models\Product;
use App\Models\ProductEvent;
use App\Models\ProductEventType;
use App\Models\Warranty;

class ProductLifecycleEventRecorder
{
    /**
     * Registra l'evento di creazione prodotto da candidato confermato.
     */
    public function recordProductCreatedFromCandidate(
        Product $product,
        ?int $userId = null,
        ?int $documentId = null,
        array $metadata = []
    ): ProductEvent {
        return ProductEvent::query()->create([
            'product_id' => $product->id,
            'product_event_type_id' => $this->eventTypeId('purchase'),
            'document_id' => $documentId,
            'created_by_user_id' => $userId,
            'title' => 'Prodotto creato',
            'description' => 'Scheda prodotto creata da un candidato confermato dall’utente.',
            'event_date' => $product->purchase_date,
            'source' => 'candidate_confirmation',
            'confidence_score' => $product->reliability_score,
            'metadata' => [
                'recorder' => 'product_lifecycle_event_recorder_v1',
                'event_source' => 'product_from_candidate_creator',
                ...$metadata,
            ],
        ]);
    }

    /**
     * Registra la creazione automatica di una garanzia.
     */
    public function recordAutomaticWarrantyCreated(Warranty $warranty, ?int $userId = null): ProductEvent
    {
        return ProductEvent::query()->create([
            'product_id' => $warranty->product_id,
            'product_event_type_id' => $this->eventTypeId('warranty_update'),
            'document_id' => $warranty->source_document_id,
            'created_by_user_id' => $userId,
            'title' => 'Garanzia calcolata',
            'description' => 'Garanzia legale stimata automaticamente in base alla data di acquisto e alle regole configurate.',
            'event_date' => $warranty->starts_at,
            'source' => 'calculated',
            'confidence_score' => $warranty->confidence_score,
            'metadata' => [
                'recorder' => 'product_lifecycle_event_recorder_v1',
                'event_source' => 'default_warranty_creator',
                'warranty_id' => $warranty->id,
                'warranty_type_id' => $warranty->warranty_type_id,
                'starts_at' => $warranty->starts_at?->format('Y-m-d'),
                'ends_at' => $warranty->ends_at?->format('Y-m-d'),
                'duration_months' => $warranty->duration_months,
                'warranty_metadata' => $warranty->metadata,
            ],
        ]);
    }

    /**
     * Registra la creazione manuale di una garanzia.
     */
    public function recordManualWarrantyCreated(Warranty $warranty, ?int $userId = null): ProductEvent
    {
        return ProductEvent::query()->create([
            'product_id' => $warranty->product_id,
            'product_event_type_id' => $this->eventTypeId('warranty_update'),
            'document_id' => $warranty->source_document_id,
            'created_by_user_id' => $userId,
            'title' => 'Garanzia creata manualmente',
            'description' => 'Garanzia inserita manualmente dall’utente.',
            'event_date' => $warranty->starts_at,
            'source' => 'manual',
            'confidence_score' => $warranty->confidence_score,
            'metadata' => [
                'recorder' => 'product_lifecycle_event_recorder_v1',
                'event_source' => 'product_show_manual_create',
                'warranty_id' => $warranty->id,
                'warranty_type_id' => $warranty->warranty_type_id,
                'starts_at' => $warranty->starts_at?->format('Y-m-d'),
                'ends_at' => $warranty->ends_at?->format('Y-m-d'),
                'duration_months' => $warranty->duration_months,
            ],
        ]);
    }

    /**
     * Registra una modifica manuale della garanzia.
     */
    public function recordManualWarrantyUpdated(
        Warranty $warranty,
        array $previousValues,
        ?int $userId = null
    ): ProductEvent {
        return ProductEvent::query()->create([
            'product_id' => $warranty->product_id,
            'product_event_type_id' => $this->eventTypeId('warranty_update'),
            'document_id' => $warranty->source_document_id,
            'created_by_user_id' => $userId,
            'title' => 'Garanzia modificata',
            'description' => 'Garanzia aggiornata manualmente dall’utente.',
            'event_date' => now()->toDateString(),
            'source' => 'manual',
            'confidence_score' => $warranty->confidence_score,
            'metadata' => [
                'recorder' => 'product_lifecycle_event_recorder_v1',
                'event_source' => 'product_show_manual_update',
                'warranty_id' => $warranty->id,
                'previous_values' => $previousValues,
                'new_values' => [
                    'starts_at' => $warranty->starts_at?->format('Y-m-d'),
                    'ends_at' => $warranty->ends_at?->format('Y-m-d'),
                    'duration_months' => $warranty->duration_months,
                    'source' => $warranty->source,
                    'confidence_score' => $warranty->confidence_score,
                    'notes' => $warranty->notes,
                ],
            ],
        ]);
    }

    /**
     * Recupera l'id del tipo evento.
     */
    private function eventTypeId(string $code): ?int
    {
        return ProductEventType::query()
            ->where('code', $code)
            ->where('is_active', true)
            ->value('id');
    }
}