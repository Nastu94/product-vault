<?php

namespace App\Services\ProductCases;

use App\Exceptions\ProductCases\ProductCaseRequestDraftProtectedException;
use App\Models\ProductCase;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class ProductCaseRequestDraftGenerator
{
    public const METADATA_KEY =
        'request_draft_generation';

    public function __construct(
        private readonly ProductCaseRequestDraftBuilder $builder,
        private readonly ProductCaseEventRecorder $eventRecorder
    ) {
    }

    /**
     * Genera o rigenera la bozza della pratica.
     *
     * Una bozza generata automaticamente può essere aggiornata soltanto
     * finché il suo contenuto coincide con l'ultimo hash registrato.
     *
     * Se l'utente l'ha modificata, la rigenerazione viene bloccata.
     */
    public function generate(
        ProductCase $productCase,
        User $generatedBy
    ): ProductCase {
        $productCaseId =
            $productCase->getKey();

        $userId =
            $generatedBy->getKey();

        if ($productCaseId === null) {
            throw new RuntimeException(
                'La pratica deve essere persistita prima di generare una bozza.'
            );
        }

        if ($userId === null) {
            throw new RuntimeException(
                'L’utente deve essere persistito prima di generare una bozza.'
            );
        }

        return DB::transaction(function () use (
            $productCaseId,
            $userId
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

            $generatedBy = User::query()
                ->lockForUpdate()
                ->find($userId);

            if ($generatedBy === null) {
                throw new RuntimeException(
                    'L’utente non è più disponibile.'
                );
            }

            $this->ensureUserCanManageCase(
                productCase: $productCase,
                user: $generatedBy,
            );

            $this->ensureDraftIsMutable(
                $productCase
            );

            $metadata = is_array(
                $productCase->metadata
            )
                ? $productCase->metadata
                : [];

            $generationMetadata =
                $metadata[
                    self::METADATA_KEY
                ] ?? null;

            $generationMetadata =
                is_array($generationMetadata)
                    ? $generationMetadata
                    : [];

            $currentMetadata =
                $metadata[
                    ProductCase
                        ::REQUEST_DRAFT_CURRENT_METADATA_KEY
                ] ?? null;

            $currentMetadata =
                is_array($currentMetadata)
                    ? $currentMetadata
                    : [];

            $currentDraft =
                is_string(
                    $productCase->request_draft
                )
                    ? $productCase->request_draft
                    : null;

            $hasCurrentDraft =
                $currentDraft !== null
                && trim($currentDraft) !== '';

            /*
             * Snapshot precedente usato dall'evento lifecycle.
             *
             * Se una bozza esiste e supera il controllo di protezione
             * successivo, è necessariamente una bozza generata.
             */
            $previousHash =
                $hasCurrentDraft
                    ? hash(
                        'sha256',
                        $currentDraft
                    )
                    : null;

            $previousSource =
                $hasCurrentDraft
                    ? ProductCase
                        ::REQUEST_DRAFT_SOURCE_GENERATED
                    : 'empty';

            $isRegeneration =
                $hasCurrentDraft;

            if ($hasCurrentDraft) {
                $storedGeneratedHash =
                    $generationMetadata[
                        'generated_sha256'
                    ] ?? null;

                $currentHash = hash(
                    'sha256',
                    $currentDraft
                );

                /*
                 * Una bozza senza provenance automatica è considerata manuale.
                 *
                 * Anche una bozza generata ma successivamente modificata viene
                 * protetta, perché il contenuto non coincide più con l'hash.
                 */
                if (
                    ! is_string(
                        $storedGeneratedHash
                    )
                    || ! preg_match(
                        '/^[a-f0-9]{64}$/',
                        $storedGeneratedHash
                    )
                    || ! hash_equals(
                        $storedGeneratedHash,
                        $currentHash
                    )
                ) {
                    throw new ProductCaseRequestDraftProtectedException();
                }
            }

            $build = $this->builder->build(
                $productCase
            );

            $draft = $build['body'];
            $draftHash =
                $build['body_sha256'];

            /*
             * Stesse sorgenti e stesso testo: nessuna scrittura.
             * Il timestamp originale viene preservato.
             */
            $currentStateHash =
                $currentMetadata['sha256']
                ?? null;

            if (
                $hasCurrentDraft
                && $currentDraft === $draft
                && isset(
                    $generationMetadata[
                        'generated_sha256'
                    ]
                )
                && is_string(
                    $generationMetadata[
                        'generated_sha256'
                    ]
                )
                && hash_equals(
                    $generationMetadata[
                        'generated_sha256'
                    ],
                    $draftHash
                )
                && (
                    $currentMetadata['source']
                    ?? null
                ) === ProductCase
                    ::REQUEST_DRAFT_SOURCE_GENERATED
                && is_string(
                    $currentStateHash
                )
                && hash_equals(
                    $currentStateHash,
                    $draftHash
                )
            ) {
                return $productCase->refresh();
            }

            $now = now();

            $metadata[
                self::METADATA_KEY
            ] = [
                'version' =>
                    ProductCaseRequestDraftBuilder::VERSION,

                'generated_sha256' =>
                    $draftHash,

                'source_fingerprint' =>
                    $build[
                        'source_fingerprint'
                    ],

                'generated_by_user_id' =>
                    (int) $generatedBy->id,

                'generated_at' =>
                    $now->toISOString(),
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
                        ::REQUEST_DRAFT_SOURCE_GENERATED,

                'sha256' =>
                    $draftHash,

                'updated_by_user_id' =>
                    (int) $generatedBy->id,

                'updated_at' =>
                    $now->toISOString(),
            ];

            /*
             * Timestamp e metadata sono protetti dal mass assignment.
             * Il forceFill è quindi intenzionale e confinato nel service.
             */
            $productCase->forceFill([
                'request_draft' =>
                    $draft,

                'request_draft_generated_at' =>
                    $now,

                'metadata' =>
                    $metadata,
            ]);

            $productCase->save();

            /*
             * Bozza ed evento vengono confermati o annullati insieme.
             *
             * Il ramo idempotente è già terminato prima di questo punto,
             * quindi ogni passaggio qui rappresenta una modifica reale.
             */
            $this->eventRecorder
                ->recordRequestDraftGenerated(
                    productCase:
                        $productCase,

                    actor:
                        $generatedBy,

                    previousHash:
                        $previousHash,

                    newHash:
                        $draftHash,

                    previousSource:
                        $previousSource,

                    sourceFingerprint:
                        $build[
                            'source_fingerprint'
                        ],

                    occurredAt:
                        $now,

                    isRegeneration:
                        $isRegeneration,
                );

            return $productCase->refresh();
        });
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
                'L’utente non può generare la bozza di una pratica appartenente a un altro team.'
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
                'La bozza può essere generata soltanto prima che il contatto venga registrato.'
            );
        }
    }
}