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
            ])
            ->where('team_id', $teamId)
            ->latest()
            ->paginate($this->perPage);

        return view('livewire.products.product-index', [
            'products' => $products,
        ])->layout('layouts.app');
    }
}