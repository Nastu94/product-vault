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

final class ProductCaseWorkflowBar extends Component
{
    use AuthorizesRequests;

    public ProductCase $productCase;

    public ?string $successMessage = null;

    public ?string $errorMessage = null;

    /**
     * Carica soltanto la pratica corrente e gli eventuali feedback flash.
     */
    public function mount(
        ProductCase $productCase
    ): void {
        $this->authorize(
            'view',
            $productCase
        );

        $this->productCase =
            $productCase->fresh()
            ?? throw new RuntimeException(
                'La pratica non è più disponibile.'
            );

        $successMessage = session()->get(
            'product_case_workflow_success'
        );

        $errorMessage = session()->get(
            'product_case_workflow_error'
        );

        $this->successMessage =
            is_string($successMessage)
                ? $successMessage
                : null;

        $this->errorMessage =
            is_string($errorMessage)
                ? $errorMessage
                : null;
    }

    /**
     * Riporta la pratica alla preparazione senza alterarne i contenuti.
     */
    public function returnToDraft(
        ProductCaseStatusTransitionService $transitionService
    ): void {
        $currentCase =
            $this->productCase->fresh();

        if ($currentCase === null) {
            throw new RuntimeException(
                'La pratica non è più disponibile.'
            );
        }

        $this->authorize(
            'update',
            $currentCase
        );

        $this->successMessage = null;
        $this->errorMessage = null;

        if (
            $currentCase->status
                !== ProductCase::STATUS_READY_TO_CONTACT
        ) {
            session()->flash(
                'product_case_workflow_error',
                'Soltanto una pratica pronta per il contatto può tornare in bozza.'
            );

            $this->redirectRoute(
                'product-cases.show',
                [
                    'productCase' =>
                        $currentCase->id,
                ]
            );

            return;
        }

        $performedBy = Auth::user();

        if (! $performedBy instanceof User) {
            throw new RuntimeException(
                'Utente autenticato non disponibile.'
            );
        }

        $updatedCase =
            $transitionService->transition(
                productCase:
                    $currentCase,

                performedBy:
                    $performedBy,

                targetStatus:
                    ProductCase::STATUS_DRAFT,
            );

        $this->productCase =
            $updatedCase;

        session()->flash(
            'product_case_workflow_success',
            'La pratica è tornata in bozza.'
        );

        /*
         * Il redirect ricarica anche lo stato derivato e la timeline
         * del componente principale della pagina.
         */
        $this->redirectRoute(
            'product-cases.show',
            [
                'productCase' =>
                    $updatedCase->id,
            ]
        );
    }

    public function render(): View
    {
        return view(
            'livewire.product-cases.product-case-workflow-bar'
        );
    }
}
