<?php

namespace App\Livewire\ProductCases;

use App\Models\ProductCase;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

final class ProductCaseStopBar extends Component
{
    use AuthorizesRequests;

    public ProductCase $productCase;

    public function mount(ProductCase $productCase): void
    {
        $this->authorize('view', $productCase);
        $this->productCase = $productCase;
    }

    public function render(): View
    {
        return view('livewire.product-cases.product-case-stop-bar');
    }
}
