<?php

namespace App\Livewire\ProductCases;

use App\Exceptions\ProductCases\ProductCaseNotReadyException;
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
            $this->freshProductCase();

        $this->authorize(
            'update',
            $currentCase
        );

        $this->resetMessages();

        if (
            $currentCase->status
                !== ProductCase::STATUS_READY_TO_CONTACT
        ) {
            $this->redirectWithError(
                $currentCase,
                'Soltanto una pratica pronta per il contatto può tornare in bozza.'
            );

            return;
        }

        $updatedCase =
            $transitionService->transition(
                productCase:
                    $currentCase,

                performedBy:
                    $this->authenticatedUser(),

                targetStatus:
                    ProductCase::STATUS_DRAFT,
            );

        $this->productCase =
            $updatedCase;

        $this->redirectWithSuccess(
            $updatedCase,
            'La pratica è tornata in bozza.'
        );
    }

    /**
     * Registra che il contatto è stato realmente effettuato.
     *
     * Questa azione non invia messaggi e non contatta servizi esterni.
     */
    public function markContacted(
        ProductCaseStatusTransitionService $transitionService
    ): void {
        $currentCase =
            $this->freshProductCase();

        $this->authorize(
            'update',
            $currentCase
        );

        $this->resetMessages();

        if (
            $currentCase->status
                !== ProductCase::STATUS_READY_TO_CONTACT
        ) {
            $this->redirectWithError(
                $currentCase,
                'Soltanto una pratica pronta per il contatto può essere registrata come contattata.'
            );

            return;
        }

        try {
            $updatedCase =
                $transitionService->transition(
                    productCase:
                        $currentCase,

                    performedBy:
                        $this->authenticatedUser(),

                    targetStatus:
                        ProductCase::STATUS_CONTACTED,
                );
        } catch (
            ProductCaseNotReadyException
        ) {
            $this->redirectWithError(
                $this->freshProductCase(),
                'La pratica non è più completa. Verifica le informazioni bloccanti prima di registrare il contatto.'
            );

            return;
        }

        $this->productCase =
            $updatedCase;

        $this->redirectWithSuccess(
            $updatedCase,
            'Il contatto è stato registrato correttamente.'
        );
    }

    private function freshProductCase(): ProductCase
    {
        return $this->productCase->fresh()
            ?? throw new RuntimeException(
                'La pratica non è più disponibile.'
            );
    }

    private function authenticatedUser(): User
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            throw new RuntimeException(
                'Utente autenticato non disponibile.'
            );
        }

        return $user;
    }

    private function resetMessages(): void
    {
        $this->successMessage = null;
        $this->errorMessage = null;
    }

    private function redirectWithSuccess(
        ProductCase $productCase,
        string $message
    ): void {
        session()->flash(
            'product_case_workflow_success',
            $message
        );

        $this->redirectToCase(
            $productCase
        );
    }

    private function redirectWithError(
        ProductCase $productCase,
        string $message
    ): void {
        session()->flash(
            'product_case_workflow_error',
            $message
        );

        $this->redirectToCase(
            $productCase
        );
    }

    /**
     * Il redirect ricarica stato, readiness e timeline del componente principale.
     */
    private function redirectToCase(
        ProductCase $productCase
    ): void {
        $this->redirectRoute(
            'product-cases.show',
            [
                'productCase' =>
                    $productCase->id,
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
