<?php

namespace App\Services\ProductCases;

use App\Models\ProductCase;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

final class ProductCaseRequestDraftEditor
{
    /**
     * @param ProductCaseEventRecorder $eventRecorder
     */
    public function __construct(
        private readonly ProductCaseEventRecorder $eventRecorder
    ) {
    }

    public const VERSION =
        'product_case_request_draft_editor_v1';

    public const METADATA_KEY =
        'request_draft_manual_edit';

    public const MAX_LENGTH = 50000;

    /**
     * Salva una modifica manuale della bozza.
     *
     * La bozza viene normalizzata soltanto nelle terminazioni di riga
     * e negli spazi esterni. La formattazione interna viene preservata.
     */
    public function saveManualDraft(
        ProductCase $productCase,
        User $editedBy,
        string $draft
    ): ProductCase {
        $productCaseId =
            $productCase->getKey();

        $userId =
            $editedBy->getKey();

        if ($productCaseId === null) {
            throw new RuntimeException(
                'La pratica deve essere persistita prima di modificare la bozza.'
            );
        }

        if ($userId === null) {
            throw new RuntimeException(
                'L’utente deve essere persistito prima di modificare la bozza.'
            );
        }

        $normalizedDraft =
            $this->normalizeDraft($draft);

        $validated = Validator::make(
            [
                'request_draft' =>
                    $normalizedDraft,
            ],
            [
                'request_draft' => [
                    'required',
                    'string',
                    'max:' . self::MAX_LENGTH,
                ],
            ]
        )->validate();

        return DB::transaction(function () use (
            $productCaseId,
            $userId,
            $validated
        ): ProductCase {
            $productCase = ProductCase::query()
                ->with('team')
                ->lockForUpdate()
                ->find($productCaseId);

            if ($productCase === null) {
                throw new RuntimeException(
                    'La pratica non è più disponibile.'
                );
            }

            $editedBy = User::query()
                ->lockForUpdate()
                ->find($userId);

            if ($editedBy === null) {
                throw new RuntimeException(
                    'L’utente non è più disponibile.'
                );
            }

            $this->ensureUserCanManageCase(
                productCase: $productCase,
                user: $editedBy,
            );

            $this->ensureDraftIsMutable(
                $productCase
            );

            $newDraft =
                $validated['request_draft'];

            $currentDraft =
                is_string(
                    $productCase->request_draft
                )
                    ? $productCase->request_draft
                    : null;

            /*
             * Il salvataggio dello stesso contenuto è un no-op:
             * timestamp e provenance restano invariati.
             */
            if ($currentDraft === $newDraft) {
                return $productCase->refresh();
            }

            $metadata = is_array(
                $productCase->metadata
            )
                ? $productCase->metadata
                : [];

            $previousHash =
                $currentDraft !== null
                && trim($currentDraft) !== ''
                    ? hash(
                        'sha256',
                        $currentDraft
                    )
                    : null;

            $previousSource =
                $this->resolveCurrentSource(
                    metadata: $metadata,
                    currentDraft: $currentDraft,
                    currentHash: $previousHash,
                );

            $manualMetadata =
                $metadata[
                    self::METADATA_KEY
                ] ?? null;

            $manualMetadata =
                is_array($manualMetadata)
                    ? $manualMetadata
                    : [];

            $editCount =
                is_int(
                    $manualMetadata[
                        'edit_count'
                    ] ?? null
                )
                    ? $manualMetadata[
                        'edit_count'
                    ]
                    : 0;

            $newHash = hash(
                'sha256',
                $newDraft
            );

            $now = now();

            $metadata[
                self::METADATA_KEY
            ] = [
                'version' =>
                    self::VERSION,

                'edited_sha256' =>
                    $newHash,

                'edited_by_user_id' =>
                    (int) $editedBy->id,

                'edited_at' =>
                    $now->toISOString(),

                'edit_count' =>
                    $editCount + 1,

                'previous_source' =>
                    $previousSource,

                'previous_sha256' =>
                    $previousHash,
            ];

            $metadata[
                ProductCase
                    ::REQUEST_DRAFT_CURRENT_METADATA_KEY
            ] = [
                'version' =>
                    ProductCase
                        ::REQUEST_DRAFT_CURRENT_METADATA_VERSION,

                'source' =>
                    ProductCase
                        ::REQUEST_DRAFT_SOURCE_MANUAL,

                'sha256' =>
                    $newHash,

                'updated_by_user_id' =>
                    (int) $editedBy->id,

                'updated_at' =>
                    $now->toISOString(),
            ];

            /*
             * request_draft e metadata sono protetti dal mass assignment.
             */
            $productCase->forceFill([
                'request_draft' =>
                    $newDraft,

                'metadata' =>
                    $metadata,
            ]);

            /*
             * request_draft_generated_at non viene modificato:
             * continua a rappresentare l'ultima generazione automatica,
             * non l'ultima modifica manuale.
             */
            $productCase->save();

            /*
             * Il ramo no-op è già terminato prima di questo punto.
             *
             * La modifica e il relativo evento appartengono alla stessa
             * transazione database.
             */
            $this->eventRecorder
                ->recordRequestDraftEdited(
                    productCase:
                        $productCase,

                    actor:
                        $editedBy,

                    previousHash:
                        $previousHash,

                    newHash:
                        $newHash,

                    previousSource:
                        $previousSource,

                    occurredAt:
                        $now,
                );

            return $productCase->refresh();
        });
    }

    /**
     * Determina la provenienza verificando che l'hash salvato
     * corrisponda realmente al contenuto corrente.
     *
     * @param  array<string, mixed>  $metadata
     */
    private function resolveCurrentSource(
        array $metadata,
        ?string $currentDraft,
        ?string $currentHash
    ): string {
        if (
            $currentDraft === null
            || trim($currentDraft) === ''
            || $currentHash === null
        ) {
            return 'empty';
        }

        $currentMetadata = $metadata[
            ProductCase
                ::REQUEST_DRAFT_CURRENT_METADATA_KEY
        ] ?? null;

        $currentMetadata =
            is_array($currentMetadata)
                ? $currentMetadata
                : [];

        $storedSource =
            $currentMetadata['source']
            ?? null;

        $storedHash =
            $currentMetadata['sha256']
            ?? null;

        if (
            is_string($storedSource)
            && in_array(
                $storedSource,
                [
                    ProductCase
                        ::REQUEST_DRAFT_SOURCE_GENERATED,

                    ProductCase
                        ::REQUEST_DRAFT_SOURCE_MANUAL,
                ],
                true
            )
            && is_string($storedHash)
            && hash_equals(
                $storedHash,
                $currentHash
            )
        ) {
            return $storedSource;
        }

        /*
         * Compatibilità con bozze generate prima dell'introduzione
         * del metadata request_draft_current.
         */
        $generatedHash = data_get(
            $metadata,
            ProductCaseRequestDraftGenerator
                ::METADATA_KEY
                . '.generated_sha256'
        );

        if (
            is_string($generatedHash)
            && hash_equals(
                $generatedHash,
                $currentHash
            )
        ) {
            return ProductCase
                ::REQUEST_DRAFT_SOURCE_GENERATED;
        }

        return 'untracked';
    }

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
                'L’utente non può modificare la bozza di una pratica appartenente a un altro team.'
            );
        }
    }

    private function ensureDraftIsMutable(
        ProductCase $productCase
    ): void {
        if (
            ! in_array(
                $productCase->status,
                ProductCase::STATUSES,
                true
            )
        ) {
            throw new RuntimeException(
                'Lo stato corrente della pratica non è valido.'
            );
        }

        if (
            ! in_array(
                $productCase->status,
                [
                    ProductCase::STATUS_DRAFT,
                    ProductCase::STATUS_READY_TO_CONTACT,
                ],
                true
            )
        ) {
            throw new RuntimeException(
                'La bozza può essere modificata soltanto prima che il contatto venga registrato.'
            );
        }
    }

    private function normalizeDraft(
        string $draft
    ): string {
        $draft = str_replace(
            [
                "\r\n",
                "\r",
            ],
            "\n",
            $draft
        );

        return trim($draft);
    }
}