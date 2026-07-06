<?php

namespace App\Livewire\ProductCases;

use App\Models\ProductCase;
use App\Models\User;
use App\Services\ProductCases\ProductCaseStatusTransitionService;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use RuntimeException;

final class ProductCaseStopBar extends Component
{
    use AuthorizesRequests;

    public ProductCase $productCase;

    public function mount(ProductCase $productCase): void
    {
        $this->authorize('view', $productCase);

        $this->productCase = $productCase->fresh()
            ?? throw new RuntimeException('La pratica non è più disponibile.');
    }

    public function stopWorkflow(
        ProductCaseStatusTransitionService $transitionService
    ): void {
        $currentCase = $this->productCase->fresh();

        if ($currentCase === null) {
            throw new RuntimeException('La pratica non è più disponibile.');
        }

        $this->authorize('update', $currentCase);

        $allowedStatuses = [
            ProductCase::STATUS_DRAFT,
            ProductCase::STATUS_READY_TO_CONTACT,
            ProductCase::STATUS_CONTACTED,
        ];

        if (! in_array($currentCase->status, $allowedStatuses, true)) {
            session()->flash(
                'product_case_workflow_error',
                'La pratica non può essere interrotta nello stato corrente.'
            );

            $this->redirectToCase($currentCase);
            return;
        }

        $user = Auth::user();

        if (! $user instanceof User) {
            throw new RuntimeException('Utente autenticato non disponibile.');
        }

        $updatedCase = $transitionService->transition(
            productCase: $currentCase,
            performedBy: $user,
            targetStatus: ProductCase::STATUS_CANCELLED,
        );

        $this->productCase = $updatedCase;

        session()->flash(
            'product_case_workflow_success',
            'La pratica è stata annullata.'
        );

        $this->redirectToCase($updatedCase);
    }

    private function redirectToCase(ProductCase $productCase): void
    {
        $this->redirectRoute(
            'product-cases.show',
            ['productCase' => $productCase->id]
        );
    }

    public function render(): View
    {
        return view('livewire.product-cases.product-case-stop-bar');
    }
}
