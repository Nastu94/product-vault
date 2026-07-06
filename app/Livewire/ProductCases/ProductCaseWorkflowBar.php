<?php

namespace App\Livewire\ProductCases;

use App\Exceptions\ProductCases\ProductCaseNotReadyException;
use App\Models\ProductCase;
use App\Models\User;
use App\Services\ProductCases\ProductCaseStatusTransitionService;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Livewire\Component;
use RuntimeException;

final class ProductCaseWorkflowBar extends Component
{
    use AuthorizesRequests;

    public ProductCase $productCase;

    public ?string $successMessage = null;

    public ?string $errorMessage = null;

    public bool $isResolving = false;

    public string $resolutionOutcome = '';

    public ?string $resolutionNotes = null;

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
        $this->resetResolutionForm();

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
        $this->resetResolutionForm();

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

    /**
     * Apre la registrazione esplicita dell’esito della pratica.
     */
    public function startResolution(): void
    {
        $currentCase =
            $this->freshProductCase();

        $this->authorize(
            'update',
            $currentCase
        );

        $this->resetValidation();
        $this->resetMessages();
        $this->resetResolutionForm();

        if (
            $currentCase->status
                !== ProductCase::STATUS_CONTACTED
        ) {
            $this->redirectWithError(
                $currentCase,
                'Soltanto una pratica contattata può essere registrata come risolta.'
            );

            return;
        }

        $this->productCase =
            $currentCase;

        $this->isResolving = true;
    }

    /**
     * Chiude il form senza registrare alcun esito.
     */
    public function cancelResolution(): void
    {
        $currentCase =
            $this->freshProductCase();

        $this->authorize(
            'update',
            $currentCase
        );

        $this->resetValidation();
        $this->resetMessages();
        $this->resetResolutionForm();
    }

    /**
     * Registra esito e note tramite il service di transizione.
     */
    public function resolveProductCase(
        ProductCaseStatusTransitionService $transitionService
    ): void {
        if (! $this->isResolving) {
            throw new RuntimeException(
                'Il form di risoluzione non è aperto.'
            );
        }

        $currentCase =
            $this->freshProductCase();

        $this->authorize(
            'update',
            $currentCase
        );

        $this->resetMessages();

        if (
            $currentCase->status
                !== ProductCase::STATUS_CONTACTED
        ) {
            $this->resetResolutionForm();

            $this->redirectWithError(
                $currentCase,
                'Soltanto una pratica contattata può essere registrata come risolta.'
            );

            return;
        }

        $validated = Validator::make(
            [
                'resolutionOutcome' =>
                    $this->resolutionOutcome,

                'resolutionNotes' =>
                    $this->resolutionNotes,
            ],
            [
                'resolutionOutcome' => [
                    'required',
                    'string',
                    Rule::in(
                        ProductCase::OUTCOMES
                    ),
                ],

                'resolutionNotes' => [
                    'nullable',
                    'string',
                    'max:20000',
                ],
            ],
            [
                'resolutionOutcome.required' =>
                    'Seleziona l’esito della pratica.',

                'resolutionOutcome.in' =>
                    'Seleziona un esito valido.',

                'resolutionNotes.max' =>
                    'Le note di risoluzione sono troppo lunghe.',
            ]
        )->validate();

        $updatedCase =
            $transitionService->transition(
                productCase:
                    $currentCase,

                performedBy:
                    $this->authenticatedUser(),

                targetStatus:
                    ProductCase::STATUS_RESOLVED,

                attributes: [
                    'outcome' =>
                        $validated[
                            'resolutionOutcome'
                        ],

                    'resolution_notes' =>
                        $validated[
                            'resolutionNotes'
                        ] ?? null,
                ],
            );

        $this->productCase =
            $updatedCase;

        $this->resetValidation();
        $this->resetResolutionForm();

        $this->redirectWithSuccess(
            $updatedCase,
            'La pratica è stata registrata come risolta.'
        );
    }

    /**
     * Chiude definitivamente una pratica già risolta.
     */
    public function closeProductCase(
        ProductCaseStatusTransitionService $transitionService
    ): void {
        $currentCase =
            $this->freshProductCase();

        $this->authorize(
            'update',
            $currentCase
        );

        $this->resetMessages();
        $this->resetResolutionForm();

        if (
            $currentCase->status
                !== ProductCase::STATUS_RESOLVED
        ) {
            $this->redirectWithError(
                $currentCase,
                'Soltanto una pratica risolta può essere chiusa.'
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
                    ProductCase::STATUS_CLOSED,
            );

        $this->productCase =
            $updatedCase;

        $this->redirectWithSuccess(
            $updatedCase,
            'La pratica è stata chiusa definitivamente.'
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

    private function resetResolutionForm(): void
    {
        $this->isResolving = false;
        $this->resolutionOutcome = '';
        $this->resolutionNotes = null;
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
