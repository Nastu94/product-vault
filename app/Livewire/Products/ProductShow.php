<?php

namespace App\Livewire\Products;

use App\Models\Product;
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
     * Renderizza il dettaglio prodotto.
     */
    public function render(): View
    {
        return view('livewire.products.product-show')
            ->layout('layouts.app');
    }
}