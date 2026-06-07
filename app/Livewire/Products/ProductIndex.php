<?php

namespace App\Livewire\Products;

use App\Models\Product;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\WithPagination;

class ProductIndex extends Component
{
    use AuthorizesRequests;
    use WithPagination;

    /**
     * Numero di prodotti mostrati per pagina.
     */
    public int $perPage = 10;

    /**
     * Garanzia principale da mostrare nella lista prodotti.
     */
    public function primaryWarranty(Product $product): ?\App\Models\Warranty
    {
        return $product->warranties
            ->sort(function ($first, $second): int {
                $firstIsLegal = $first->warrantyType?->code === 'legal' ? 1 : 0;
                $secondIsLegal = $second->warrantyType?->code === 'legal' ? 1 : 0;

                if ($firstIsLegal !== $secondIsLegal) {
                    return $secondIsLegal <=> $firstIsLegal;
                }

                $firstEndsAt = $first->ends_at?->timestamp ?? PHP_INT_MAX;
                $secondEndsAt = $second->ends_at?->timestamp ?? PHP_INT_MAX;

                return $firstEndsAt <=> $secondEndsAt;
            })
            ->first();
    }

    /**
     * Etichetta stato garanzia per la lista prodotti.
     */
    public function warrantyStatusLabel(?\App\Models\Warranty $warranty): string
    {
        if (! $warranty) {
            return 'Non calcolata';
        }

        if (! $warranty->starts_at || ! $warranty->ends_at) {
            return 'Non calcolabile';
        }

        if (now()->startOfDay()->lt($warranty->starts_at)) {
            return 'Non iniziata';
        }

        if (now()->startOfDay()->gt($warranty->ends_at)) {
            return 'Scaduta';
        }

        $remainingDays = now()->startOfDay()->diffInDays($warranty->ends_at, false);

        if ($remainingDays <= 30) {
            return 'In scadenza';
        }

        return 'Attiva';
    }

    /**
     * Classi badge garanzia.
     */
    public function warrantyStatusBadgeClasses(?\App\Models\Warranty $warranty): string
    {
        return match ($this->warrantyStatusLabel($warranty)) {
            'Attiva' => 'bg-green-50 text-green-700 ring-green-600/20',
            'In scadenza' => 'bg-yellow-50 text-yellow-800 ring-yellow-600/20',
            'Scaduta' => 'bg-red-50 text-red-700 ring-red-600/20',
            'Non iniziata' => 'bg-blue-50 text-blue-700 ring-blue-600/20',
            default => 'bg-gray-100 text-gray-700 ring-gray-500/20',
        };
    }

    /**
     * Etichetta fonte garanzia.
     */
    public function warrantySourceLabel(?\App\Models\Warranty $warranty): string
    {
        if (! $warranty) {
            return '—';
        }

        return match ($warranty->source) {
            'calculated' => 'Calcolata',
            'manual' => 'Manuale',
            default => $warranty->source,
        };
    }

    /**
     * Giorni residui alla scadenza.
     */
    public function warrantyRemainingDays(?\App\Models\Warranty $warranty): ?int
    {
        if (! $warranty?->ends_at) {
            return null;
        }

        return now()->startOfDay()->diffInDays($warranty->ends_at, false);
    }

    /**
     * Mostra i prodotti del workspace corrente.
     */
    public function render(): View
    {
        $this->authorize('viewAny', Product::class);

        $user = Auth::user();

        $teamId = $user->current_team_id ?? $user->currentTeam?->id;

        $products = Product::query()
            ->with([
                'merchant',
                'currency',
                'identificationStatus',
                'documents',
                'warranties.warrantyType',
            ])
            ->where('team_id', $teamId)
            ->latest()
            ->paginate($this->perPage);

        return view('livewire.products.product-index', [
            'products' => $products,
        ])->layout('layouts.app');
    }
}