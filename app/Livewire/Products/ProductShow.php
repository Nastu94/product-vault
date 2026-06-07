<?php

namespace App\Livewire\Products;

use App\Models\Product;
use App\Models\Warranty;
use App\Models\WarrantyType;
use App\Services\Products\ProductLifecycleEventRecorder;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class ProductShow extends Component
{
    use AuthorizesRequests;

    /**
     * Prodotto mostrato nella pagina dettaglio.
     */
    public Product $product;

    /**
     * Stato form modifica garanzia.
     */
    public bool $isEditingWarranty = false;

    /**
     * Stato form creazione garanzia manuale.
     */
    public bool $isCreatingWarranty = false;

    /**
     * Campi editabili della garanzia principale.
     */
    public ?string $warrantyStartsAt = null;

    public ?string $warrantyEndsAt = null;

    public ?string $warrantyDurationMonths = null;

    public ?string $warrantyNotes = null;

    /**
     * Inizializza il componente con route model binding.
     */
    public function mount(Product $product): void
    {
        $this->authorize('view', $product);

        $this->product = $product->load([
            'merchant',
            'currency',
            'identificationStatus',
            'category',
            'brand',
            'createdBy',
            'documents.documentType',
            'documents.merchant',
            'warranties.warrantyType',
            'warranties.sourceDocument.documentType',
            'events.productEventType',
            'events.document',
            'events.createdBy',
        ]);
    }

    /**
     * Etichetta leggibile dello stato di identificazione.
     */
    public function getIdentificationStatusLabelProperty(): string
    {
        return match ($this->product->identificationStatus?->code) {
            'unknown' => 'Sconosciuto',
            'partial' => 'Parziale',
            'probable' => 'Probabile',
            'user_confirmed' => 'Confermato dall’utente',
            'merchant_verified' => 'Verificato dal venditore',
            default => $this->product->identificationStatus?->name ?? '—',
        };
    }

    /**
     * Classi CSS del badge affidabilità.
     */
    public function getReliabilityBadgeClassesProperty(): string
    {
        $score = $this->product->reliability_score;

        if ($score === null) {
            return 'bg-gray-100 text-gray-700 ring-gray-500/20';
        }

        if ($score >= 80) {
            return 'bg-green-50 text-green-700 ring-green-600/20';
        }

        if ($score >= 50) {
            return 'bg-yellow-50 text-yellow-800 ring-yellow-600/20';
        }

        return 'bg-red-50 text-red-700 ring-red-600/20';
    }

    /**
     * Garanzia principale da mostrare nella scheda prodotto.
     */
    public function getPrimaryWarrantyProperty(): ?\App\Models\Warranty
    {
        return $this->product->warranties
            ->sortByDesc(fn ($warranty) => $warranty->warrantyType?->code === 'legal' ? 1 : 0)
            ->sortBy('ends_at')
            ->first();
    }

    /**
     * Etichetta stato garanzia.
     */
    public function getWarrantyStatusLabelProperty(): string
    {
        $warranty = $this->primaryWarranty;

        if (! $warranty || ! $warranty->starts_at || ! $warranty->ends_at) {
            return 'Non calcolabile';
        }

        if (now()->startOfDay()->lt($warranty->starts_at)) {
            return 'Non ancora iniziata';
        }

        if (now()->startOfDay()->gt($warranty->ends_at)) {
            return 'Scaduta';
        }

        return 'Attiva';
    }

    /**
     * Classi CSS badge stato garanzia.
     */
    public function getWarrantyStatusBadgeClassesProperty(): string
    {
        return match ($this->warrantyStatusLabel) {
            'Attiva' => 'bg-green-50 text-green-700 ring-green-600/20',
            'Non ancora iniziata' => 'bg-blue-50 text-blue-700 ring-blue-600/20',
            'Scaduta' => 'bg-red-50 text-red-700 ring-red-600/20',
            default => 'bg-gray-100 text-gray-700 ring-gray-500/20',
        };
    }

    /**
     * Giorni residui alla scadenza della garanzia.
     */
    public function getWarrantyRemainingDaysProperty(): ?int
    {
        $warranty = $this->primaryWarranty;

        if (! $warranty || ! $warranty->ends_at) {
            return null;
        }

        return now()->startOfDay()->diffInDays($warranty->ends_at, false);
    }

    /**
     * Avvia la modifica manuale della garanzia principale.
     */
    public function editWarranty(): void
    {
        $this->authorize('update', $this->product);

        $warranty = $this->primaryWarranty;

        if (! $warranty) {
            return;
        }

        $this->resetValidation();

        $this->warrantyStartsAt = $warranty->starts_at?->format('Y-m-d');
        $this->warrantyEndsAt = $warranty->ends_at?->format('Y-m-d');
        $this->warrantyDurationMonths = $warranty->duration_months !== null
            ? (string) $warranty->duration_months
            : null;
        $this->warrantyNotes = $warranty->notes;

        $this->isEditingWarranty = true;
    }

    /**
     * Annulla la modifica manuale della garanzia.
     */
    public function cancelWarrantyEdit(): void
    {
        $this->resetValidation();

        $this->isEditingWarranty = false;
        $this->isCreatingWarranty = false;
        $this->warrantyStartsAt = null;
        $this->warrantyEndsAt = null;
        $this->warrantyDurationMonths = null;
        $this->warrantyNotes = null;
    }

    /**
     * Salva una garanzia:
     * - se isCreatingWarranty = true, crea una nuova garanzia manuale;
     * - altrimenti modifica la garanzia principale esistente.
     */
    public function saveWarranty(): void
    {
        $this->authorize('update', $this->product);

        $this->validate([
            'warrantyStartsAt' => ['nullable', 'date'],
            'warrantyEndsAt' => ['nullable', 'date', 'after_or_equal:warrantyStartsAt'],
            'warrantyDurationMonths' => ['nullable', 'integer', 'min:1', 'max:600'],
            'warrantyNotes' => ['nullable', 'string', 'max:5000'],
        ], [
            'warrantyEndsAt.after_or_equal' => 'La data di scadenza deve essere successiva o uguale alla data di inizio.',
            'warrantyDurationMonths.integer' => 'La durata deve essere un numero intero di mesi.',
            'warrantyDurationMonths.min' => 'La durata deve essere almeno di 1 mese.',
            'warrantyDurationMonths.max' => 'La durata non può superare 600 mesi.',
        ]);

        /*
        |--------------------------------------------------------------------------
        | Creazione manuale garanzia
        |--------------------------------------------------------------------------
        |
        | Questo ramo deve stare prima della modifica, perché quando stiamo creando
        | una garanzia il prodotto non ha ancora una primaryWarranty.
        |
        */
        if ($this->isCreatingWarranty) {
            if ($this->primaryWarranty) {
                $this->addError('warranty', 'Questo prodotto ha già una garanzia principale.');

                return;
            }

            $warrantyType = WarrantyType::query()
                ->where('code', 'legal')
                ->where('is_active', true)
                ->first();

            if (! $warrantyType) {
                $this->addError('warranty', 'Tipo garanzia legale non disponibile.');

                return;
            }

            $sourceDocument = $this->product->documents()
                ->orderByPivot('created_at')
                ->first();

            $createdWarranty = Warranty::query()->create([
                'product_id' => $this->product->id,
                'warranty_type_id' => $warrantyType->id,
                'source_document_id' => $sourceDocument?->id,
                'starts_at' => $this->warrantyStartsAt ?: null,
                'ends_at' => $this->warrantyEndsAt ?: null,
                'duration_months' => filled($this->warrantyDurationMonths)
                    ? (int) $this->warrantyDurationMonths
                    : null,
                'source' => 'manual',
                'confidence_score' => 90,
                'notes' => $this->warrantyNotes ?: null,
                'metadata' => [
                    'creator' => 'manual_warranty_creation_v1',
                    'created_from' => 'product_show',
                    'created_at' => now()->toISOString(),
                    'created_by_user_id' => auth()->id(),
                ],
            ]);

            app(ProductLifecycleEventRecorder::class)->recordManualWarrantyCreated(
                warranty: $createdWarranty,
                userId: auth()->id(),
            );

            $this->refreshProduct();

            $this->cancelWarrantyEdit();

            session()->flash('status', 'Garanzia creata manualmente.');

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Modifica manuale garanzia esistente
        |--------------------------------------------------------------------------
        */
        $warranty = $this->primaryWarranty;

        if (! $warranty) {
            $this->addError('warranty', 'Nessuna garanzia da modificare.');

            return;
        }

        $previousValues = [
            'starts_at' => $warranty->starts_at?->format('Y-m-d'),
            'ends_at' => $warranty->ends_at?->format('Y-m-d'),
            'duration_months' => $warranty->duration_months,
            'source' => $warranty->source,
            'confidence_score' => $warranty->confidence_score,
            'notes' => $warranty->notes,
        ];

        $metadata = $warranty->metadata ?? [];

        $metadata['manual_override'] = [
            'applied' => true,
            'previous_values' => $previousValues,
            'updated_at' => now()->toISOString(),
            'updated_by_user_id' => auth()->id(),
        ];

        $warranty->update([
            'starts_at' => $this->warrantyStartsAt ?: null,
            'ends_at' => $this->warrantyEndsAt ?: null,
            'duration_months' => filled($this->warrantyDurationMonths)
                ? (int) $this->warrantyDurationMonths
                : null,
            'source' => 'manual',
            'confidence_score' => 90,
            'notes' => $this->warrantyNotes ?: null,
            'metadata' => $metadata,
        ]);

        $warranty->refresh();

        app(ProductLifecycleEventRecorder::class)->recordManualWarrantyUpdated(
            warranty: $warranty,
            previousValues: $previousValues,
            userId: auth()->id(),
        );

        $this->refreshProduct();

        $this->cancelWarrantyEdit();

        session()->flash('status', 'Garanzia aggiornata manualmente.');
    }

    /**
     * Avvia la creazione manuale di una garanzia.
     */
    public function createWarranty(): void
    {
        $this->authorize('update', $this->product);

        if ($this->primaryWarranty) {
            $this->addError('warranty', 'Questo prodotto ha già una garanzia principale.');

            return;
        }

        $this->resetValidation();

        $this->warrantyStartsAt = $this->product->purchase_date?->format('Y-m-d');
        $this->warrantyDurationMonths = '24';

        $this->warrantyEndsAt = $this->product->purchase_date
            ? $this->product->purchase_date->copy()->addMonthsNoOverflow(24)->format('Y-m-d')
            : null;

        $this->warrantyNotes = null;

        $this->isCreatingWarranty = true;
        $this->isEditingWarranty = false;
    }

    /**
     * Ricarica il prodotto con tutte le relazioni usate nella pagina dettaglio.
     */
    private function refreshProduct(): void
    {
        $this->product = $this->product->fresh([
            'merchant',
            'currency',
            'identificationStatus',
            'category',
            'brand',
            'createdBy',
            'documents.documentType',
            'documents.merchant',
            'warranties.warrantyType',
            'warranties.sourceDocument.documentType',
            'events.productEventType',
            'events.document',
            'events.createdBy',
        ]);
    }

    /**
     * Renderizza il dettaglio prodotto.
     */
    public function render(): View
    {
        return view('livewire.products.product-show')
            ->layout('layouts.app');
    }
}