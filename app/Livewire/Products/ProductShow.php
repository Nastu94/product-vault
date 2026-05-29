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
     * Renderizza il dettaglio prodotto.
     */
    public function render(): View
    {
        return view('livewire.products.product-show')
            ->layout('layouts.app');
    }
}