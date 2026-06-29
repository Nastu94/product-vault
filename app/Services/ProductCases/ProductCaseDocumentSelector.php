<?php

namespace App\Services\ProductCases;

use App\Models\Document;
use App\Models\ProductCase;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

class ProductCaseDocumentSelector
{
    /**
     * @param ProductCaseEventRecorder $eventRecorder
     */
    public function __construct(
        private readonly ProductCaseEventRecorder $eventRecorder
    ) {
    }

    /**
     * Seleziona un documento come evidenza della pratica.
     *
     * Restituisce true quando viene creato un nuovo collegamento.
     * Restituisce false quando il documento era già selezionato.
     */
    public function select(
        ProductCase $productCase,
        Document $document,
        User $selectedBy,
        ?string $notes = null
    ): bool {
        $productCaseId = $productCase->getKey();
        $documentId = $document->getKey();
        $userId = $selectedBy->getKey();

        $this->ensurePersistedIdentifiers(
            productCaseId: $productCaseId,
            documentId: $documentId,
            userId: $userId,
        );

        $notes = $this->validateAndNormalizeNotes(
            $notes
        );

        return DB::transaction(function () use (
            $productCaseId,
            $documentId,
            $userId,
            $notes
        ): bool {
            $context = $this->loadContext(
                productCaseId: $productCaseId,
                documentId: $documentId,
                userId: $userId,
            );

            $productCase = $context['product_case'];
            $document = $context['document'];
            $selectedBy = $context['user'];

            $this->ensureUserCanManageCase(
                productCase: $productCase,
                user: $selectedBy,
            );

            $this->ensureDocumentBelongsToCaseTeam(
                productCase: $productCase,
                document: $document,
            );

            if ($productCase->product === null) {
                throw new RuntimeException(
                    'Il prodotto della pratica non è più disponibile.'
                );
            }

            /*
             * Un documento può essere selezionato per la pratica soltanto
             * se è già collegato al prodotto tramite product_documents.
             */
            $belongsToProduct = DB::table(
                'product_documents'
            )
                ->where(
                    'product_id',
                    $productCase->product_id
                )
                ->where(
                    'document_id',
                    $document->id
                )
                ->exists();

            if (! $belongsToProduct) {
                throw new RuntimeException(
                    'Il documento non è collegato al prodotto della pratica.'
                );
            }

            /*
             * Il lock sulla pratica serializza le selezioni concorrenti.
             * Il vincolo unique resta comunque l'ultima protezione database.
             */
            $alreadySelected = DB::table(
                'product_case_documents'
            )
                ->where(
                    'product_case_id',
                    $productCase->id
                )
                ->where(
                    'document_id',
                    $document->id
                )
                ->exists();

            if ($alreadySelected) {
                /*
                 * Il retry non sovrascrive selector o note originali.
                 * Un eventuale aggiornamento esplicito sarà un'azione separata.
                 */
                return false;
            }

            $productCase->documents()->attach(
                $document->id,
                [
                    'selected_by_user_id' =>
                        $selectedBy->id,

                    'notes' =>
                        $notes,
                ]
            );

            /*
             * Pivot ed evento fanno parte della stessa transazione.
             */
            $this->eventRecorder
                ->recordDocumentSelected(
                    productCase:
                        $productCase,

                    actor:
                        $selectedBy,

                    document:
                        $document,

                    notes:
                        $notes,
                );

            return true;
        });
    }

    /**
     * Rimuove un documento dalla pratica.
     *
     * Restituisce true quando il collegamento viene eliminato.
     * Restituisce false quando il documento non era selezionato.
     */
    public function deselect(
        ProductCase $productCase,
        Document $document,
        User $deselectedBy
    ): bool {
        $productCaseId = $productCase->getKey();
        $documentId = $document->getKey();
        $userId = $deselectedBy->getKey();

        $this->ensurePersistedIdentifiers(
            productCaseId: $productCaseId,
            documentId: $documentId,
            userId: $userId,
        );

        return DB::transaction(function () use (
            $productCaseId,
            $documentId,
            $userId
        ): bool {
            $context = $this->loadContext(
                productCaseId: $productCaseId,
                documentId: $documentId,
                userId: $userId,
            );

            $productCase = $context['product_case'];
            $document = $context['document'];
            $deselectedBy = $context['user'];

            $this->ensureUserCanManageCase(
                productCase: $productCase,
                user: $deselectedBy,
            );

            $this->ensureDocumentBelongsToCaseTeam(
                productCase: $productCase,
                document: $document,
            );

            /*
             * Recuperiamo la provenance prima di eliminare la pivot.
             */
            $selection = DB::table(
                'product_case_documents'
            )
                ->where(
                    'product_case_id',
                    $productCase->id
                )
                ->where(
                    'document_id',
                    $document->id
                )
                ->first([
                    'selected_by_user_id',
                    'notes',
                ]);

            if ($selection === null) {
                return false;
            }

            $originalSelectedByUserId =
                $selection
                    ->selected_by_user_id
                    !== null
                    ? (int) $selection
                        ->selected_by_user_id
                    : null;

            $selectionNotes =
                is_string(
                    $selection->notes
                )
                    ? $selection->notes
                    : null;

            $deleted = DB::table(
                'product_case_documents'
            )
                ->where(
                    'product_case_id',
                    $productCase->id
                )
                ->where(
                    'document_id',
                    $document->id
                )
                ->delete();

            if ($deleted !== 1) {
                throw new RuntimeException(
                    'Non è stato possibile rimuovere il documento dalla pratica.'
                );
            }

            /*
             * La riga pivot è stata rimossa, ma il relativo snapshot
             * resta disponibile nella timeline.
             */
            $this->eventRecorder
                ->recordDocumentDeselected(
                    productCase:
                        $productCase,

                    actor:
                        $deselectedBy,

                    document:
                        $document,

                    originalSelectedByUserId:
                        $originalSelectedByUserId,

                    notes:
                        $selectionNotes,
                );

            return true;
        });
    }

    /**
     * Carica e blocca le entità coinvolte nell'operazione.
     *
     * @return array{
     *     product_case: ProductCase,
     *     document: Document,
     *     user: User
     * }
     */
    private function loadContext(
        int $productCaseId,
        int $documentId,
        int $userId
    ): array {
        $productCase = ProductCase::query()
            ->with([
                'team',
                'product',
            ])
            ->lockForUpdate()
            ->find($productCaseId);

        if ($productCase === null) {
            throw new RuntimeException(
                'La pratica non è più disponibile.'
            );
        }

        $document = Document::query()
            ->lockForUpdate()
            ->find($documentId);

        if ($document === null) {
            throw new RuntimeException(
                'Il documento non è più disponibile.'
            );
        }

        $user = User::query()
            ->lockForUpdate()
            ->find($userId);

        if ($user === null) {
            throw new RuntimeException(
                'L’utente non è più disponibile.'
            );
        }

        return [
            'product_case' => $productCase,
            'document' => $document,
            'user' => $user,
        ];
    }

    /**
     * Verifica appartenenza e workspace corrente.
     */
    private function ensureUserCanManageCase(
        ProductCase $productCase,
        User $user
    ): void {
        if (
            $productCase->team_id === null
            || $productCase->team === null
        ) {
            throw new RuntimeException(
                'La pratica non appartiene a un team valido.'
            );
        }

        if (
            (int) $user->current_team_id
                !== (int) $productCase->team_id
            || ! $user->belongsToTeam(
                $productCase->team
            )
        ) {
            throw new RuntimeException(
                'L’utente non può gestire i documenti di una pratica appartenente a un altro team.'
            );
        }
    }

    /**
     * Verifica che documento e pratica appartengano allo stesso team.
     */
    private function ensureDocumentBelongsToCaseTeam(
        ProductCase $productCase,
        Document $document
    ): void {
        if (
            (int) $document->team_id
                !== (int) $productCase->team_id
        ) {
            throw new RuntimeException(
                'Il documento appartiene a un team diverso dalla pratica.'
            );
        }
    }

    /**
     * Valida gli identificatori delle entità ricevute.
     */
    private function ensurePersistedIdentifiers(
        mixed $productCaseId,
        mixed $documentId,
        mixed $userId
    ): void {
        if ($productCaseId === null) {
            throw new RuntimeException(
                'La pratica deve essere persistita prima di selezionare documenti.'
            );
        }

        if ($documentId === null) {
            throw new RuntimeException(
                'Il documento deve essere persistito prima della selezione.'
            );
        }

        if ($userId === null) {
            throw new RuntimeException(
                'L’utente deve essere persistito prima della selezione.'
            );
        }
    }

    /**
     * Valida e normalizza la nota del collegamento.
     */
    private function validateAndNormalizeNotes(
        ?string $notes
    ): ?string {
        if ($notes !== null) {
            $notes = trim($notes);

            if ($notes === '') {
                $notes = null;
            }
        }

        return Validator::make(
            [
                'notes' => $notes,
            ],
            [
                'notes' => [
                    'nullable',
                    'string',
                    'max:10000',
                ],
            ]
        )->validate()['notes'] ?? null;
    }
}