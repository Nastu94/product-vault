<?php

namespace App\Console\Commands\ProductVault;

use App\Models\Product;
use App\Models\ProductCase;
use App\Models\User;
use App\Exceptions\ProductCases\ProductCaseNotReadyException;
use App\Services\ProductCases\ProductCaseDocumentSelector;
use App\Services\ProductCases\ProductCaseCreator;
use App\Services\ProductCases\ProductCaseStatusTransitionService;
use App\Policies\ProductCasePolicy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use RuntimeException;
use Throwable;

class TestProductCaseWorkflowCommand extends Command
{
    /**
     * Nome del comando.
     *
     * Il comando verrà esteso nelle micro-patch successive con transizioni,
     * documenti, coperture, allegati e chiusura della pratica.
     *
     * @var string
     */
    protected $signature =
        'product-vault:test-product-case-workflow';

    /**
     * Descrizione del comando.
     *
     * @var string
     */
    protected $description =
        'Verifica con rollback la creazione controllata delle pratiche prodotto.';

    /**
     * Esegue il test senza lasciare dati persistiti.
     */
    public function handle(
        ProductCaseCreator $creator,
        ProductCaseDocumentSelector $documentSelector,
        ProductCaseStatusTransitionService $transitionService
    ): int {
        $rows = [];
        $failures = [];

        $createdCaseId = null;

        $casesBefore = ProductCase::query()->count();
        $teamsBefore = DB::table('teams')->count();

        $caseDocumentLinksBefore = DB::table(
            'product_case_documents'
        )->count();

        $permissionRegistrar =
            app(PermissionRegistrar::class);

        $assertSame = function (
            string $scenario,
            string $assertion,
            mixed $expected,
            mixed $actual
        ) use (&$rows, &$failures): void {
            $passed = $expected === $actual;

            $rows[] = [
                $scenario,
                $assertion,
                $passed ? 'OK' : 'FAIL',
            ];

            if (! $passed) {
                $failures[] = [
                    'scenario' => $scenario,
                    'assertion' => $assertion,
                    'expected' => $expected,
                    'actual' => $actual,
                ];
            }
        };

        DB::beginTransaction();

        try {
            $product = Product::query()
                ->with([
                    'team',
                    'documents',
                    'warranties',
                ])
                ->whereNotNull('team_id')
                ->whereHas('documents')
                ->whereHas(
                    'warranties',
                    fn ($query) => $query
                        ->whereNotNull('starts_at')
                        ->whereNotNull('ends_at')
                )
                ->orderBy('id')
                ->first();

            if (
                $product === null
                || $product->team === null
                || $product->documents->isEmpty()
            ) {
                throw new RuntimeException(
                    'Nessun prodotto con team, documenti e garanzia completa utilizzabile per il test.'
                );
            }

            /*
             * Usiamo il proprietario del team del prodotto.
             *
             * Il suo current_team_id viene allineato all'interno della
             * transazione e verrà quindi ripristinato dal rollback.
             */
            $user = User::query()
                ->find($product->team->user_id);

            if ($user === null) {
                throw new RuntimeException(
                    'Nessun utente utilizzabile per il test.'
                );
            }

            User::query()
                ->whereKey($user->id)
                ->update([
                    'current_team_id' => $product->team_id,
                ]);

            $user->refresh();

            $document =
                $product->documents->first();

            if ($document === null) {
                throw new RuntimeException(
                    'Documento prodotto non disponibile.'
                );
            }

            /*
             * Nei comandi CLI non viene eseguito il middleware web che imposta
             * il team corrente per Spatie Permission. Replichiamo quindi
             * esplicitamente quel contesto.
             */
            $permissionRegistrar->setPermissionsTeamId(
                $product->team_id
            );

            $user->unsetRelation('roles');
            $user->unsetRelation('permissions');

            $requiredPermissions = [
                'product_cases.view',
                'product_cases.create',
                'product_cases.update',
                'product_cases.close',
                'product_cases.delete',
            ];

            $registeredPermissions = Permission::query()
                ->where('guard_name', 'web')
                ->whereIn('name', $requiredPermissions)
                ->pluck('name')
                ->all();

            sort($requiredPermissions);
            sort($registeredPermissions);

            $assertSame(
                'authorization',
                'permission catalog registered',
                $requiredPermissions,
                $registeredPermissions
            );

            $policy = Gate::getPolicyFor(
                ProductCase::class
            );

            $assertSame(
                'authorization',
                'policy auto-discovered',
                true,
                $policy instanceof ProductCasePolicy
            );

            $gate = Gate::forUser($user);

            $assertSame(
                'authorization',
                'owner can view case list',
                true,
                $gate->allows(
                    'viewAny',
                    ProductCase::class
                )
            );

            $assertSame(
                'authorization',
                'owner can create for current product',
                true,
                $gate->allows(
                    'create',
                    [
                        ProductCase::class,
                        $product,
                    ]
                )
            );

            /*
             * Creazione valida.
             *
             * Inseriamo anche campi di sistema contraffatti per verificare
             * che vengano ignorati dal creator.
             */
            $productCase = $creator->create(
                product: $product,
                openedBy: $user,
                attributes: [
                    'team_id' => 999999,
                    'product_id' => 999999,
                    'status' => ProductCase::STATUS_CLOSED,
                    'original_description' =>
                        'Descrizione contraffatta',
                    'outcome' =>
                        ProductCase::OUTCOME_REFUNDED,

                    'title' =>
                        '  Monitor che non si accende  ',
                    'description' =>
                        '  Il monitor non si accende dopo il collegamento.  ',
                    'occurred_on' => today()->toDateString(),
                    'usability_status' =>
                        ProductCase::USABILITY_UNUSABLE,
                    'accidental_damage_declared' => false,
                    'accidental_damage_notes' => '   ',
                ],
            );

            $createdCaseId = (int) $productCase->id;

            $gate = Gate::forUser($user);

            $assertSame(
                'authorization',
                'owner can view case',
                true,
                $gate->allows(
                    'view',
                    $productCase
                )
            );

            $assertSame(
                'authorization',
                'owner can update case',
                true,
                $gate->allows(
                    'update',
                    $productCase
                )
            );

            $assertSame(
                'authorization',
                'owner can close case',
                true,
                $gate->allows(
                    'close',
                    $productCase
                )
            );

            $assertSame(
                'authorization',
                'owner can delete case',
                true,
                $gate->allows(
                    'delete',
                    $productCase
                )
            );

            $assertSame(
                'valid_creation',
                'one case created',
                $casesBefore + 1,
                ProductCase::query()->count()
            );

            $assertSame(
                'valid_creation',
                'team derived from product',
                (int) $product->team_id,
                (int) $productCase->team_id
            );

            $assertSame(
                'valid_creation',
                'product assigned',
                (int) $product->id,
                (int) $productCase->product_id
            );

            $assertSame(
                'valid_creation',
                'opening user assigned',
                (int) $user->id,
                (int) $productCase->opened_by_user_id
            );

            $assertSame(
                'valid_creation',
                'status forced to draft',
                ProductCase::STATUS_DRAFT,
                $productCase->status
            );

            $assertSame(
                'valid_creation',
                'title normalized',
                'Monitor che non si accende',
                $productCase->title
            );

            $assertSame(
                'valid_creation',
                'original description derived',
                'Il monitor non si accende dopo il collegamento.',
                $productCase->original_description
            );

            $assertSame(
                'valid_creation',
                'current description derived',
                'Il monitor non si accende dopo il collegamento.',
                $productCase->description
            );

            $assertSame(
                'valid_creation',
                'occurrence date persisted',
                today()->toDateString(),
                $productCase->occurred_on?->toDateString()
            );

            $assertSame(
                'valid_creation',
                'usability persisted',
                ProductCase::USABILITY_UNUSABLE,
                $productCase->usability_status
            );

            $assertSame(
                'valid_creation',
                'false accidental damage preserved',
                false,
                $productCase->accidental_damage_declared
            );

            $assertSame(
                'valid_creation',
                'empty accidental notes normalized',
                null,
                $productCase->accidental_damage_notes
            );

            $assertSame(
                'valid_creation',
                'opened timestamp present',
                true,
                $productCase->opened_at !== null
            );

            $assertSame(
                'valid_creation',
                'outcome starts empty',
                null,
                $productCase->outcome
            );

            $assertSame(
                'valid_creation',
                'later workflow timestamps empty',
                true,
                $productCase->contacted_at === null
                    && $productCase->resolved_at === null
                    && $productCase->closed_at === null
                    && $productCase->cancelled_at === null
            );

            /*
             * La descrizione corrente può essere aggiornata con fill().
             * Quella originale non è mass assignable e resta invariata.
             */
            $productCase->fill([
                'description' =>
                    'Descrizione corrente aggiornata.',
                'original_description' =>
                    'Tentativo di sovrascrittura.',
                'resolution_notes' =>
                    'Tentativo anticipato di risoluzione.',
            ])->save();

            $productCase->refresh();

            $assertSame(
                'original_description',
                'original description preserved',
                'Il monitor non si accende dopo il collegamento.',
                $productCase->original_description
            );

            $assertSame(
                'original_description',
                'current description updated',
                'Descrizione corrente aggiornata.',
                $productCase->description
            );

            $assertSame(
                'state_machine',
                'resolution notes protected before resolved',
                null,
                $productCase->resolution_notes
            );

            $caseCountAfterValidCreation =
                ProductCase::query()->count();

            /*
             * Titolo vuoto: la validazione deve bloccare la creazione.
             */
            $blankTitleRejected = false;

            try {
                $creator->create(
                    product: $product,
                    openedBy: $user,
                    attributes: [
                        'title' => '   ',
                        'description' =>
                            'Descrizione valida.',
                    ],
                );
            } catch (ValidationException $exception) {
                $blankTitleRejected =
                    array_key_exists(
                        'title',
                        $exception->errors()
                    );
            }

            $assertSame(
                'validation',
                'blank title rejected',
                true,
                $blankTitleRejected
            );

            $assertSame(
                'validation',
                'blank title creates no case',
                $caseCountAfterValidCreation,
                ProductCase::query()->count()
            );

            /*
             * Stato di utilizzabilità fuori vocabolario.
             */
            $invalidUsabilityRejected = false;

            try {
                $creator->create(
                    product: $product,
                    openedBy: $user,
                    attributes: [
                        'title' =>
                            'Pratica con stato non valido',
                        'description' =>
                            'Descrizione valida.',
                        'usability_status' =>
                            'completely_broken',
                    ],
                );
            } catch (ValidationException $exception) {
                $invalidUsabilityRejected =
                    array_key_exists(
                        'usability_status',
                        $exception->errors()
                    );
            }

            $assertSame(
                'validation',
                'invalid usability rejected',
                true,
                $invalidUsabilityRejected
            );

            $assertSame(
                'validation',
                'invalid usability creates no case',
                $caseCountAfterValidCreation,
                ProductCase::query()->count()
            );

            /*
             * La data del problema non può essere futura.
             */
            $futureDateRejected = false;

            try {
                $creator->create(
                    product: $product,
                    openedBy: $user,
                    attributes: [
                        'title' =>
                            'Pratica con data futura',
                        'description' =>
                            'Descrizione valida.',
                        'occurred_on' =>
                            today()
                                ->addDay()
                                ->toDateString(),
                    ],
                );
            } catch (ValidationException $exception) {
                $futureDateRejected =
                    array_key_exists(
                        'occurred_on',
                        $exception->errors()
                    );
            }

            $assertSame(
                'validation',
                'future occurrence date rejected',
                true,
                $futureDateRejected
            );

            $assertSame(
                'validation',
                'future date creates no case',
                $caseCountAfterValidCreation,
                ProductCase::query()->count()
            );

            /*
             |--------------------------------------------------------------------------
             | Macchina a stati
             |--------------------------------------------------------------------------
             */

            $assertSame(
                'state_machine',
                'draft allowed targets',
                [
                    ProductCase::STATUS_READY_TO_CONTACT,
                    ProductCase::STATUS_CANCELLED,
                ],
                $transitionService->allowedTargets(
                    $productCase
                )
            );

            /*
             * La pratica è completa sul problema e sulla garanzia,
             * ma non ha ancora un documento selezionato.
             */
            $initialReadinessException = null;

            try {
                $transitionService->transition(
                    productCase: $productCase,
                    performedBy: $user,
                    targetStatus:
                        ProductCase::STATUS_READY_TO_CONTACT,
                );
            } catch (
                ProductCaseNotReadyException $exception
            ) {
                $initialReadinessException =
                    $exception;
            }

            $assertSame(
                'readiness_transition',
                'incomplete draft rejected',
                true,
                $initialReadinessException
                    instanceof ProductCaseNotReadyException
            );

            $assertSame(
                'readiness_transition',
                'stable initial blocker codes',
                [
                    'selected_document',
                ],
                $initialReadinessException
                    ?->blockingCodes()
            );

            $assertSame(
                'readiness_transition',
                'stable readiness message',
                'La pratica non è pronta per il contatto. Informazioni bloccanti: selected_document.',
                $initialReadinessException
                    ?->getMessage()
            );

            $productCase->refresh();

            $assertSame(
                'readiness_transition',
                'rejected readiness keeps draft',
                ProductCase::STATUS_DRAFT,
                $productCase->status
            );

            /*
             * Selezioniamo un documento valido.
             */
            $documentSelected =
                $documentSelector->select(
                    productCase: $productCase,
                    document: $document,
                    selectedBy: $user,
                );

            $assertSame(
                'readiness_transition',
                'document selected for readiness',
                true,
                $documentSelected
            );

            /*
             * draft non può comunque saltare direttamente a contacted.
             */
            $directContactExceptionMessage = null;

            try {
                $transitionService->transition(
                    productCase: $productCase,
                    performedBy: $user,
                    targetStatus:
                        ProductCase::STATUS_CONTACTED,
                );
            } catch (RuntimeException $exception) {
                $directContactExceptionMessage =
                    $exception->getMessage();
            }

            $assertSame(
                'state_machine',
                'draft to contacted rejected',
                'Transizione pratica non consentita: draft -> contacted.',
                $directContactExceptionMessage
            );

            $productCase->refresh();

            $assertSame(
                'state_machine',
                'illegal transition keeps draft',
                ProductCase::STATUS_DRAFT,
                $productCase->status
            );

            /*
             * draft -> ready_to_contact con readiness completa.
             */
            $productCase =
                $transitionService->transition(
                    productCase: $productCase,
                    performedBy: $user,
                    targetStatus:
                        ProductCase::STATUS_READY_TO_CONTACT,
                );

            $assertSame(
                'readiness_transition',
                'complete draft becomes ready',
                ProductCase::STATUS_READY_TO_CONTACT,
                $productCase->status
            );

            $assertSame(
                'state_machine',
                'ready transition has no operative timestamp',
                true,
                $productCase->contacted_at === null
                    && $productCase->resolved_at === null
                    && $productCase->closed_at === null
                    && $productCase->cancelled_at === null
            );

            /*
             * La richiesta dello stesso stato non è valida.
             */
            $sameStatusExceptionMessage = null;

            try {
                $transitionService->transition(
                    productCase: $productCase,
                    performedBy: $user,
                    targetStatus:
                        ProductCase::STATUS_READY_TO_CONTACT,
                );
            } catch (RuntimeException $exception) {
                $sameStatusExceptionMessage =
                    $exception->getMessage();
            }

            $assertSame(
                'state_machine',
                'same status transition rejected',
                'Transizione pratica non consentita: ready_to_contact -> ready_to_contact.',
                $sameStatusExceptionMessage
            );

            /*
             * ready_to_contact può sempre tornare in draft.
             */
            $productCase =
                $transitionService->transition(
                    productCase: $productCase,
                    performedBy: $user,
                    targetStatus:
                        ProductCase::STATUS_DRAFT,
                );

            $assertSame(
                'state_machine',
                'ready can return to draft',
                ProductCase::STATUS_DRAFT,
                $productCase->status
            );

            /*
             * La readiness viene ricalcolata: rimuovendo il documento,
             * un nuovo ingresso in ready_to_contact deve essere bloccato.
             */
            $documentRemovedInDraft =
                $documentSelector->deselect(
                    productCase: $productCase,
                    document: $document,
                    deselectedBy: $user,
                );

            $assertSame(
                'readiness_transition',
                'document removed in draft',
                true,
                $documentRemovedInDraft
            );

            $draftRetryException = null;

            try {
                $transitionService->transition(
                    productCase: $productCase,
                    performedBy: $user,
                    targetStatus:
                        ProductCase::STATUS_READY_TO_CONTACT,
                );
            } catch (
                ProductCaseNotReadyException $exception
            ) {
                $draftRetryException =
                    $exception;
            }

            $assertSame(
                'readiness_transition',
                'derived blocker rejects draft retry',
                [
                    'selected_document',
                ],
                $draftRetryException
                    ?->blockingCodes()
            );

            $productCase->refresh();

            $assertSame(
                'readiness_transition',
                'draft retry leaves status unchanged',
                ProductCase::STATUS_DRAFT,
                $productCase->status
            );

            /*
             * Ripristiniamo l'evidenza e torniamo in ready_to_contact.
             */
            $documentReselected =
                $documentSelector->select(
                    productCase: $productCase,
                    document: $document,
                    selectedBy: $user,
                );

            $assertSame(
                'readiness_transition',
                'document reselected',
                true,
                $documentReselected
            );

            $productCase =
                $transitionService->transition(
                    productCase: $productCase,
                    performedBy: $user,
                    targetStatus:
                        ProductCase::STATUS_READY_TO_CONTACT,
                );

            /*
             * Se la pratica perde completezza mentre è ready_to_contact,
             * non può essere marcata come contacted.
             */
            $documentRemovedBeforeContact =
                $documentSelector->deselect(
                    productCase: $productCase,
                    document: $document,
                    deselectedBy: $user,
                );

            $assertSame(
                'readiness_transition',
                'document removed before contact',
                true,
                $documentRemovedBeforeContact
            );

            $contactReadinessException = null;

            try {
                $transitionService->transition(
                    productCase: $productCase,
                    performedBy: $user,
                    targetStatus:
                        ProductCase::STATUS_CONTACTED,
                );
            } catch (
                ProductCaseNotReadyException $exception
            ) {
                $contactReadinessException =
                    $exception;
            }

            $assertSame(
                'readiness_transition',
                'incomplete ready case cannot be contacted',
                [
                    'selected_document',
                ],
                $contactReadinessException
                    ?->blockingCodes()
            );

            $productCase->refresh();

            $assertSame(
                'readiness_transition',
                'failed contact keeps ready status',
                ProductCase::STATUS_READY_TO_CONTACT,
                $productCase->status
            );

            /*
             * Ripristinando il documento il contatto può essere registrato.
             */
            $documentReselectedForContact =
                $documentSelector->select(
                    productCase: $productCase,
                    document: $document,
                    selectedBy: $user,
                );

            $assertSame(
                'readiness_transition',
                'document restored before contact',
                true,
                $documentReselectedForContact
            );

            $productCase =
                $transitionService->transition(
                    productCase: $productCase,
                    performedBy: $user,
                    targetStatus:
                        ProductCase::STATUS_CONTACTED,
                );

            $assertSame(
                'state_machine',
                'ready becomes contacted',
                ProductCase::STATUS_CONTACTED,
                $productCase->status
            );

            $assertSame(
                'state_machine',
                'contact timestamp recorded',
                true,
                $productCase->contacted_at !== null
            );

            /*
             * Dopo contacted non applichiamo controlli retroattivi:
             * l'evidenza può cambiare senza riscrivere la storia operativa.
             */
            $documentRemovedAfterContact =
                $documentSelector->deselect(
                    productCase: $productCase,
                    document: $document,
                    deselectedBy: $user,
                );

            $assertSame(
                'readiness_transition',
                'document can change after contact',
                true,
                $documentRemovedAfterContact
            );

            $assertSame(
                'readiness_transition',
                'contacted status remains recorded',
                ProductCase::STATUS_CONTACTED,
                $productCase->status
            );

            $contactedAt =
                $productCase->contacted_at?->toISOString();

            /*
             * Non è possibile registrare un esito durante cancellation.
             */
            $prematureOutcomeRejected = false;

            try {
                $transitionService->transition(
                    productCase: $productCase,
                    performedBy: $user,
                    targetStatus:
                        ProductCase::STATUS_CANCELLED,
                    attributes: [
                        'outcome' =>
                            ProductCase::OUTCOME_ABANDONED,
                    ],
                );
            } catch (ValidationException $exception) {
                $prematureOutcomeRejected =
                    array_key_exists(
                        'outcome',
                        $exception->errors()
                    );
            }

            $assertSame(
                'state_machine',
                'outcome outside resolved rejected',
                true,
                $prematureOutcomeRejected
            );

            $productCase->refresh();

            $assertSame(
                'state_machine',
                'rejected outcome keeps contacted',
                ProductCase::STATUS_CONTACTED,
                $productCase->status
            );

            /*
             * contacted -> resolved richiede un outcome.
             */
            $missingOutcomeRejected = false;

            try {
                $transitionService->transition(
                    productCase: $productCase,
                    performedBy: $user,
                    targetStatus:
                        ProductCase::STATUS_RESOLVED,
                );
            } catch (ValidationException $exception) {
                $missingOutcomeRejected =
                    array_key_exists(
                        'outcome',
                        $exception->errors()
                    );
            }

            $assertSame(
                'state_machine',
                'resolved requires outcome',
                true,
                $missingOutcomeRejected
            );

            $invalidOutcomeRejected = false;

            try {
                $transitionService->transition(
                    productCase: $productCase,
                    performedBy: $user,
                    targetStatus:
                        ProductCase::STATUS_RESOLVED,
                    attributes: [
                        'outcome' =>
                            'automatic_legal_victory',
                    ],
                );
            } catch (ValidationException $exception) {
                $invalidOutcomeRejected =
                    array_key_exists(
                        'outcome',
                        $exception->errors()
                    );
            }

            $assertSame(
                'state_machine',
                'invalid outcome rejected',
                true,
                $invalidOutcomeRejected
            );

            /*
             * Risoluzione valida.
             */
            $productCase =
                $transitionService->transition(
                    productCase: $productCase,
                    performedBy: $user,
                    targetStatus:
                        ProductCase::STATUS_RESOLVED,
                    attributes: [
                        'outcome' =>
                            ProductCase::OUTCOME_REPAIRED,
                        'resolution_notes' =>
                            '  Pannello sostituito.  ',
                    ],
                );

            $assertSame(
                'state_machine',
                'contacted becomes resolved',
                ProductCase::STATUS_RESOLVED,
                $productCase->status
            );

            $assertSame(
                'state_machine',
                'resolved outcome persisted',
                ProductCase::OUTCOME_REPAIRED,
                $productCase->outcome
            );

            $assertSame(
                'state_machine',
                'resolution notes normalized',
                'Pannello sostituito.',
                $productCase->resolution_notes
            );

            $assertSame(
                'state_machine',
                'resolved timestamp recorded',
                true,
                $productCase->resolved_at !== null
            );

            $assertSame(
                'state_machine',
                'contact timestamp preserved',
                $contactedAt,
                $productCase->contacted_at?->toISOString()
            );

            /*
             * resolved non può essere annullata.
             */
            $resolvedCancellationMessage = null;

            try {
                $transitionService->transition(
                    productCase: $productCase,
                    performedBy: $user,
                    targetStatus:
                        ProductCase::STATUS_CANCELLED,
                );
            } catch (RuntimeException $exception) {
                $resolvedCancellationMessage =
                    $exception->getMessage();
            }

            $assertSame(
                'state_machine',
                'resolved to cancelled rejected',
                'Transizione pratica non consentita: resolved -> cancelled.',
                $resolvedCancellationMessage
            );

            /*
             * resolved -> closed.
             */
            $productCase =
                $transitionService->transition(
                    productCase: $productCase,
                    performedBy: $user,
                    targetStatus:
                        ProductCase::STATUS_CLOSED,
                );

            $assertSame(
                'state_machine',
                'resolved becomes closed',
                ProductCase::STATUS_CLOSED,
                $productCase->status
            );

            $assertSame(
                'state_machine',
                'closed timestamp recorded',
                true,
                $productCase->closed_at !== null
            );

            $assertSame(
                'state_machine',
                'closed keeps resolution data',
                true,
                $productCase->outcome
                    === ProductCase::OUTCOME_REPAIRED
                    && $productCase->resolved_at !== null
                    && $productCase->contacted_at !== null
            );

            $assertSame(
                'state_machine',
                'closed has no allowed targets',
                [],
                $transitionService->allowedTargets(
                    $productCase
                )
            );

            $closedTransitionMessage = null;

            try {
                $transitionService->transition(
                    productCase: $productCase,
                    performedBy: $user,
                    targetStatus:
                        ProductCase::STATUS_DRAFT,
                );
            } catch (RuntimeException $exception) {
                $closedTransitionMessage =
                    $exception->getMessage();
            }

            $assertSame(
                'state_machine',
                'closed is terminal',
                'Transizione pratica non consentita: closed -> draft.',
                $closedTransitionMessage
            );

            /*
             * Percorso alternativo di cancellazione.
             *
             * La pratica temporanea viene eliminata prima degli scenari
             * cross-team, così il conteggio resta quello originario.
             */
            $cancelledCase = $creator->create(
                product: $product,
                openedBy: $user,
                attributes: [
                    'title' =>
                        'Pratica annullata di test',
                    'description' =>
                        'Pratica creata per verificare cancellation.',
                ],
            );

            $cancelledCase =
                $transitionService->transition(
                    productCase: $cancelledCase,
                    performedBy: $user,
                    targetStatus:
                        ProductCase::STATUS_CANCELLED,
                );

            $assertSame(
                'state_machine',
                'draft can be cancelled',
                ProductCase::STATUS_CANCELLED,
                $cancelledCase->status
            );

            $assertSame(
                'state_machine',
                'cancel timestamp recorded',
                true,
                $cancelledCase->cancelled_at !== null
            );

            $assertSame(
                'state_machine',
                'cancelled case has no outcome',
                null,
                $cancelledCase->outcome
            );

            $assertSame(
                'state_machine',
                'cancelled has no allowed targets',
                [],
                $transitionService->allowedTargets(
                    $cancelledCase
                )
            );

            $cancelledTransitionMessage = null;

            try {
                $transitionService->transition(
                    productCase: $cancelledCase,
                    performedBy: $user,
                    targetStatus:
                        ProductCase::STATUS_READY_TO_CONTACT,
                );
            } catch (RuntimeException $exception) {
                $cancelledTransitionMessage =
                    $exception->getMessage();
            }

            $assertSame(
                'state_machine',
                'cancelled is terminal',
                'Transizione pratica non consentita: cancelled -> ready_to_contact.',
                $cancelledTransitionMessage
            );

            $cancelledCase->forceDelete();

            $assertSame(
                'state_machine',
                'temporary cancelled case removed',
                $caseCountAfterValidCreation,
                ProductCase::query()->count()
            );

            /*
             * Creiamo temporaneamente un altro workspace posseduto dallo
             * stesso utente e lo rendiamo corrente.
             *
             * L'utente continua ad appartenere al team originale, ma non deve
             * poter creare una pratica mentre è attivo un team differente.
             */
            $otherTeamId = DB::table('teams')->insertGetId([
                'user_id' => $user->id,
                'name' =>
                    'Product Case Test '
                    . Str::uuid(),
                'personal_team' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            User::query()
                ->whereKey($user->id)
                ->update([
                    'current_team_id' => $otherTeamId,
                ]);

            $user->refresh();

            /*
             * Simula il middleware dopo il cambio di workspace.
             */
            $permissionRegistrar->setPermissionsTeamId(
                $otherTeamId
            );

            $user->unsetRelation('roles');
            $user->unsetRelation('permissions');

            $crossTeamGate = Gate::forUser($user);

            $assertSame(
                'authorization',
                'other team cannot view case list',
                false,
                $crossTeamGate->allows(
                    'viewAny',
                    ProductCase::class
                )
            );

            $assertSame(
                'authorization',
                'other team cannot create for product',
                false,
                $crossTeamGate->allows(
                    'create',
                    [
                        ProductCase::class,
                        $product,
                    ]
                )
            );

            $assertSame(
                'authorization',
                'other team cannot view case',
                false,
                $crossTeamGate->allows(
                    'view',
                    $productCase
                )
            );

            $assertSame(
                'authorization',
                'other team cannot update case',
                false,
                $crossTeamGate->allows(
                    'update',
                    $productCase
                )
            );

            $assertSame(
                'authorization',
                'other team cannot close case',
                false,
                $crossTeamGate->allows(
                    'close',
                    $productCase
                )
            );

            $assertSame(
                'authorization',
                'other team cannot delete case',
                false,
                $crossTeamGate->allows(
                    'delete',
                    $productCase
                )
            );

            $crossTeamTransitionMessage = null;

            try {
                $transitionService->transition(
                    productCase: $productCase,
                    performedBy: $user,
                    targetStatus:
                        ProductCase::STATUS_DRAFT,
                );
            } catch (RuntimeException $exception) {
                $crossTeamTransitionMessage =
                    $exception->getMessage();
            }

            $assertSame(
                'team_isolation',
                'cross-team transition rejected',
                'L’utente non può modificare una pratica appartenente a un altro team.',
                $crossTeamTransitionMessage
            );

            $productCase->refresh();

            $assertSame(
                'team_isolation',
                'cross-team transition keeps closed',
                ProductCase::STATUS_CLOSED,
                $productCase->status
            );

            $crossTeamExceptionMessage = null;

            try {
                $creator->create(
                    product: $product,
                    openedBy: $user,
                    attributes: [
                        'title' =>
                            'Tentativo da altro workspace',
                        'description' =>
                            'Questa pratica non deve essere creata.',
                    ],
                );
            } catch (RuntimeException $exception) {
                $crossTeamExceptionMessage =
                    $exception->getMessage();
            }

            $assertSame(
                'team_isolation',
                'cross-team creation rejected',
                'L’utente non può aprire una pratica per il team del prodotto.',
                $crossTeamExceptionMessage
            );

            $assertSame(
                'team_isolation',
                'cross-team attempt creates no case',
                $caseCountAfterValidCreation,
                ProductCase::query()->count()
            );
        } catch (Throwable $exception) {
            $rows[] = [
                'runtime',
                'workflow test completed',
                'FAIL',
            ];

            $failures[] = [
                'scenario' => 'runtime',
                'assertion' => 'workflow test completed',
                'expected' => 'no exception',
                'actual' =>
                    $exception::class
                    . ': '
                    . $exception->getMessage(),
            ];
        } finally {
            DB::rollBack();

            $permissionRegistrar->setPermissionsTeamId(
                null
            );

            $permissionRegistrar
                ->forgetCachedPermissions();
        }

        /*
         * Il comando non deve lasciare pratiche o team temporanei.
         */
        $assertSame(
            'rollback',
            'case count restored',
            $casesBefore,
            ProductCase::query()->count()
        );

        if ($createdCaseId !== null) {
            $assertSame(
                'rollback',
                'created case removed',
                false,
                ProductCase::query()
                    ->whereKey($createdCaseId)
                    ->exists()
            );
        }

        $assertSame(
            'rollback',
            'team count restored',
            $teamsBefore,
            DB::table('teams')->count()
        );

        $assertSame(
            'rollback',
            'case document links restored',
            $caseDocumentLinksBefore,
            DB::table(
                'product_case_documents'
            )->count()
        );

        $this->table(
            [
                'Scenario',
                'Assertion',
                'Status',
            ],
            $rows
        );

        if ($failures !== []) {
            foreach ($failures as $failure) {
                $this->error(
                    $failure['scenario']
                    . ' / '
                    . $failure['assertion']
                );

                $this->line(
                    'Expected: '
                    . var_export(
                        $failure['expected'],
                        true
                    )
                );

                $this->line(
                    'Actual: '
                    . var_export(
                        $failure['actual'],
                        true
                    )
                );
            }

            return self::FAILURE;
        }

        $this->info(
            'Product case workflow checks passed.'
        );

        return self::SUCCESS;
    }
}
