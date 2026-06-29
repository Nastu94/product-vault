<?php

namespace App\Services\ProductCases;

use App\Models\ProductCase;
use App\Models\ProductCaseEvent;
use App\Models\User;
use App\Models\Document;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Carbon\CarbonInterface;
use RuntimeException;

final class ProductCaseEventRecorder
{
    public const VERSION =
        'product_case_event_recorder_v1';

    /**
     * Registra l'apertura della pratica.
     */
    public function recordCaseOpened(
        ProductCase $productCase,
        User $actor
    ): ProductCaseEvent {
        if (
            $productCase->status
                !== ProductCase::STATUS_DRAFT
        ) {
            throw new RuntimeException(
                'Una nuova pratica deve essere registrata nello stato draft.'
            );
        }

        return $this->record(
            productCase: $productCase,
            actor: $actor,
            eventType:
                ProductCaseEvent::TYPE_CASE_OPENED,
            title:
                'Pratica aperta',
            description:
                'La pratica è stata aperta dall’utente.',
            source:
                'product_case_creator',
            occurredAt:
                $productCase->opened_at
                ?? now(),
            metadata: [
                'initial_status' =>
                    ProductCase::STATUS_DRAFT,
            ],
        );
    }

    /**
     * Registra una transizione di stato già applicata.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function recordStatusChanged(
        ProductCase $productCase,
        User $actor,
        string $fromStatus,
        string $toStatus,
        CarbonInterface $occurredAt,
        array $metadata = []
    ): ProductCaseEvent {
        if (
            ! in_array(
                $fromStatus,
                ProductCase::STATUSES,
                true
            )
            || ! in_array(
                $toStatus,
                ProductCase::STATUSES,
                true
            )
        ) {
            throw new RuntimeException(
                'La transizione contiene uno stato pratica non valido.'
            );
        }

        if ($fromStatus === $toStatus) {
            throw new RuntimeException(
                'La timeline non può registrare una transizione verso lo stesso stato.'
            );
        }

        if (
            $productCase->status
                !== $toStatus
        ) {
            throw new RuntimeException(
                'Lo stato corrente della pratica non corrisponde alla transizione registrata.'
            );
        }

        $eventMetadata = [
            ...$metadata,

            'from_status' =>
                $fromStatus,

            'to_status' =>
                $toStatus,
        ];

        if (
            $toStatus
                === ProductCase::STATUS_RESOLVED
        ) {
            $eventMetadata['outcome'] =
                $productCase->outcome;

            $eventMetadata[
                'resolution_notes'
            ] = $productCase
                ->resolution_notes;
        }

        return $this->record(
            productCase: $productCase,
            actor: $actor,
            eventType:
                ProductCaseEvent::TYPE_STATUS_CHANGED,
            title:
                'Stato pratica aggiornato',
            description:
                'Stato modificato da '
                . $fromStatus
                . ' a '
                . $toStatus
                . '.',
            source:
                'product_case_status_transition_service',
            occurredAt:
                $occurredAt,
            metadata:
                $eventMetadata,
        );
    }

    /**
     * Registra la selezione di un documento come evidenza.
     */
    public function recordDocumentSelected(
        ProductCase $productCase,
        User $actor,
        Document $document,
        ?string $notes
    ): ProductCaseEvent {
        $this->ensureDocumentBelongsToCase(
            productCase: $productCase,
            document: $document,
        );

        return $this->record(
            productCase: $productCase,
            actor: $actor,
            eventType:
                ProductCaseEvent::TYPE_DOCUMENT_SELECTED,
            title:
                'Documento selezionato',
            description:
                'Un documento è stato selezionato come evidenza della pratica.',
            source:
                'product_case_document_selector',
            occurredAt:
                now(),
            metadata: [
                'document_id' =>
                    (int) $document->id,

                'original_filename' =>
                    $document->original_filename,

                'mime_type' =>
                    $document->mime_type,

                'notes' =>
                    $notes,

                'selected_by_user_id' =>
                    (int) $actor->id,
            ],
        );
    }

    /**
     * Registra la rimozione di un documento dalle evidenze.
     */
    public function recordDocumentDeselected(
        ProductCase $productCase,
        User $actor,
        Document $document,
        ?int $originalSelectedByUserId,
        ?string $notes
    ): ProductCaseEvent {
        $this->ensureDocumentBelongsToCase(
            productCase: $productCase,
            document: $document,
        );

        return $this->record(
            productCase: $productCase,
            actor: $actor,
            eventType:
                ProductCaseEvent::TYPE_DOCUMENT_DESELECTED,
            title:
                'Documento rimosso',
            description:
                'Un documento è stato rimosso dalle evidenze della pratica.',
            source:
                'product_case_document_selector',
            occurredAt:
                now(),
            metadata: [
                'document_id' =>
                    (int) $document->id,

                'original_filename' =>
                    $document->original_filename,

                'mime_type' =>
                    $document->mime_type,

                /*
                 * Snapshot della precedente selezione, conservato anche
                 * dopo l'eliminazione della riga pivot.
                 */
                'original_selected_by_user_id' =>
                    $originalSelectedByUserId,

                'notes' =>
                    $notes,

                'deselected_by_user_id' =>
                    (int) $actor->id,
            ],
        );
    }

    /**
     * Registra l'aggiunta di una fotografia privata.
     */
    public function recordPhotoAdded(
        ProductCase $productCase,
        User $actor,
        Media $media
    ): ProductCaseEvent {
        $this->ensureIssuePhotoBelongsToCase(
            productCase: $productCase,
            media: $media,
        );

        return $this->record(
            productCase: $productCase,
            actor: $actor,
            eventType:
                ProductCaseEvent::TYPE_PHOTO_ADDED,
            title:
                'Fotografia aggiunta',
            description:
                'Una fotografia è stata aggiunta alle evidenze della pratica.',
            source:
                'product_case_photo_manager',
            occurredAt:
                $media->created_at
                ?? now(),
            metadata:
                $this->photoMetadata(
                    media: $media,
                    actorField:
                        'uploaded_by_user_id',
                    actorId:
                        (int) $actor->id,
                ),
        );
    }

    /**
     * Registra la rimozione di una fotografia privata.
     *
     * L'evento deve essere creato prima di eliminare il media, così i suoi
     * dati restano disponibili nello snapshot della timeline.
     */
    public function recordPhotoRemoved(
        ProductCase $productCase,
        User $actor,
        Media $media
    ): ProductCaseEvent {
        $this->ensureIssuePhotoBelongsToCase(
            productCase: $productCase,
            media: $media,
        );

        return $this->record(
            productCase: $productCase,
            actor: $actor,
            eventType:
                ProductCaseEvent::TYPE_PHOTO_REMOVED,
            title:
                'Fotografia rimossa',
            description:
                'Una fotografia è stata rimossa dalle evidenze della pratica.',
            source:
                'product_case_photo_manager',
            occurredAt:
                now(),
            metadata:
                $this->photoMetadata(
                    media: $media,
                    actorField:
                        'removed_by_user_id',
                    actorId:
                        (int) $actor->id,
                ),
        );
    }

    /**
     * Registra la prima generazione o la rigenerazione automatica
     * della bozza di richiesta.
     */
    public function recordRequestDraftGenerated(
        ProductCase $productCase,
        User $actor,
        ?string $previousHash,
        string $newHash,
        string $previousSource,
        string $sourceFingerprint,
        CarbonInterface $occurredAt,
        bool $isRegeneration
    ): ProductCaseEvent {
        $this->ensureValidPreviousDraftSource(
            $previousSource
        );

        $this->ensureValidSha256(
            hash: $previousHash,
            field: 'previous_sha256',
            nullable: true,
        );

        $this->ensureValidSha256(
            hash: $newHash,
            field: 'new_sha256',
        );

        $this->ensureValidSha256(
            hash: $sourceFingerprint,
            field: 'source_fingerprint',
        );

        $this->ensureCurrentDraftState(
            productCase: $productCase,
            expectedSource:
                ProductCase::REQUEST_DRAFT_SOURCE_GENERATED,
            expectedHash: $newHash,
        );

        if (
            $isRegeneration
            && $previousHash === null
        ) {
            throw new RuntimeException(
                'Una rigenerazione della bozza deve contenere l’hash precedente.'
            );
        }

        if (
            ! $isRegeneration
            && $previousHash !== null
        ) {
            throw new RuntimeException(
                'La prima generazione della bozza non può contenere un hash precedente.'
            );
        }

        $eventType = $isRegeneration
            ? ProductCaseEvent
                ::TYPE_REQUEST_DRAFT_REGENERATED
            : ProductCaseEvent
                ::TYPE_REQUEST_DRAFT_GENERATED;

        $title = $isRegeneration
            ? 'Bozza rigenerata'
            : 'Bozza generata';

        $description = $isRegeneration
            ? 'La bozza di richiesta è stata rigenerata automaticamente.'
            : 'La bozza di richiesta è stata generata automaticamente.';

        return $this->record(
            productCase: $productCase,
            actor: $actor,
            eventType: $eventType,
            title: $title,
            description: $description,
            source:
                'product_case_request_draft_generator',
            occurredAt: $occurredAt,
            metadata: [
                'generation_kind' =>
                    $isRegeneration
                        ? 'regeneration'
                        : 'initial',

                'previous_source' =>
                    $previousSource,

                'current_source' =>
                    ProductCase
                        ::REQUEST_DRAFT_SOURCE_GENERATED,

                'previous_sha256' =>
                    $previousHash,

                'new_sha256' =>
                    $newHash,

                'source_fingerprint' =>
                    $sourceFingerprint,

                'generated_by_user_id' =>
                    (int) $actor->id,
            ],
        );
    }

    /**
     * Registra una modifica manuale effettiva della bozza.
     */
    public function recordRequestDraftEdited(
        ProductCase $productCase,
        User $actor,
        ?string $previousHash,
        string $newHash,
        string $previousSource,
        CarbonInterface $occurredAt
    ): ProductCaseEvent {
        $this->ensureValidPreviousDraftSource(
            $previousSource
        );

        $this->ensureValidSha256(
            hash: $previousHash,
            field: 'previous_sha256',
            nullable: true,
        );

        $this->ensureValidSha256(
            hash: $newHash,
            field: 'new_sha256',
        );

        $this->ensureCurrentDraftState(
            productCase: $productCase,
            expectedSource:
                ProductCase::REQUEST_DRAFT_SOURCE_MANUAL,
            expectedHash: $newHash,
        );

        if (
            $previousSource === 'empty'
            && $previousHash !== null
        ) {
            throw new RuntimeException(
                'Una bozza precedentemente vuota non può avere un hash precedente.'
            );
        }

        return $this->record(
            productCase: $productCase,
            actor: $actor,
            eventType:
                ProductCaseEvent::TYPE_REQUEST_DRAFT_EDITED,
            title:
                'Bozza modificata',
            description:
                'La bozza di richiesta è stata modificata manualmente dall’utente.',
            source:
                'product_case_request_draft_editor',
            occurredAt:
                $occurredAt,
            metadata: [
                'previous_source' =>
                    $previousSource,

                'current_source' =>
                    ProductCase
                        ::REQUEST_DRAFT_SOURCE_MANUAL,

                'previous_sha256' =>
                    $previousHash,

                'new_sha256' =>
                    $newHash,

                'edited_by_user_id' =>
                    (int) $actor->id,
            ],
        );
    }

    /**
     * Verifica che la bozza persistita corrisponda all'evento.
     */
    private function ensureCurrentDraftState(
        ProductCase $productCase,
        string $expectedSource,
        string $expectedHash
    ): void {
        $currentDraft =
            is_string(
                $productCase->request_draft
            )
                ? $productCase->request_draft
                : null;

        if (
            $currentDraft === null
            || trim($currentDraft) === ''
        ) {
            throw new RuntimeException(
                'La pratica non contiene una bozza valida da registrare nella timeline.'
            );
        }

        $actualHash = hash(
            'sha256',
            $currentDraft
        );

        if (
            ! hash_equals(
                $expectedHash,
                $actualHash
            )
        ) {
            throw new RuntimeException(
                'L’hash della bozza non corrisponde al contenuto persistito.'
            );
        }

        $metadata = is_array(
            $productCase->metadata
        )
            ? $productCase->metadata
            : [];

        $storedSource = data_get(
            $metadata,
            ProductCase
                ::REQUEST_DRAFT_CURRENT_METADATA_KEY
                . '.source'
        );

        $storedHash = data_get(
            $metadata,
            ProductCase
                ::REQUEST_DRAFT_CURRENT_METADATA_KEY
                . '.sha256'
        );

        if (
            $storedSource !== $expectedSource
            || ! is_string($storedHash)
            || ! hash_equals(
                $expectedHash,
                $storedHash
            )
        ) {
            throw new RuntimeException(
                'La provenance corrente della bozza non corrisponde all’evento.'
            );
        }
    }

    private function ensureValidPreviousDraftSource(
        string $source
    ): void {
        if (
            ! in_array(
                $source,
                [
                    'empty',
                    'untracked',
                    ProductCase
                        ::REQUEST_DRAFT_SOURCE_GENERATED,
                    ProductCase
                        ::REQUEST_DRAFT_SOURCE_MANUAL,
                ],
                true
            )
        ) {
            throw new RuntimeException(
                'La provenienza precedente della bozza non è valida.'
            );
        }
    }

    private function ensureValidSha256(
        ?string $hash,
        string $field,
        bool $nullable = false
    ): void {
        if (
            $nullable
            && $hash === null
        ) {
            return;
        }

        if (
            ! is_string($hash)
            || preg_match(
                '/^[a-f0-9]{64}$/',
                $hash
            ) !== 1
        ) {
            throw new RuntimeException(
                'Il campo '
                . $field
                . ' non contiene un hash SHA-256 valido.'
            );
        }
    }

    /**
     * Verifica che il documento appartenga allo stesso team della pratica.
     */
    private function ensureDocumentBelongsToCase(
        ProductCase $productCase,
        Document $document
    ): void {
        if (! $document->exists) {
            throw new RuntimeException(
                'Il documento deve essere persistito prima di registrare l’evento.'
            );
        }

        if (
            (int) $document->team_id
                !== (int) $productCase->team_id
        ) {
            throw new RuntimeException(
                'Il documento dell’evento appartiene a un team diverso dalla pratica.'
            );
        }
    }

    /**
     * Verifica proprietà e collection della fotografia.
     */
    private function ensureIssuePhotoBelongsToCase(
        ProductCase $productCase,
        Media $media
    ): void {
        if (! $media->exists) {
            throw new RuntimeException(
                'La fotografia deve essere persistita prima di registrare l’evento.'
            );
        }

        if (
            $media->model_type
                !== $productCase->getMorphClass()
            || (int) $media->model_id
                !== (int) $productCase->id
            || $media->collection_name
                !== ProductCase::MEDIA_COLLECTION_ISSUE_PHOTOS
        ) {
            throw new RuntimeException(
                'La fotografia dell’evento non appartiene alla pratica.'
            );
        }
    }

    /**
     * Crea lo snapshot tecnico della fotografia.
     *
     * @return array<string, mixed>
     */
    private function photoMetadata(
        Media $media,
        string $actorField,
        int $actorId
    ): array {
        return [
            'media_id' =>
                (int) $media->id,

            'collection_name' =>
                $media->collection_name,

            'disk' =>
                $media->disk,

            'name' =>
                $media->name,

            'file_name' =>
                $media->file_name,

            'mime_type' =>
                $media->mime_type,

            'size' =>
                (int) $media->size,

            'original_filename' =>
                $media->getCustomProperty(
                    'original_filename'
                ),

            'sha256' =>
                $media->getCustomProperty(
                    'sha256'
                ),

            $actorField =>
                $actorId,
        ];
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function record(
        ProductCase $productCase,
        User $actor,
        string $eventType,
        string $title,
        string $description,
        string $source,
        CarbonInterface $occurredAt,
        array $metadata
    ): ProductCaseEvent {
        if (! $productCase->exists) {
            throw new RuntimeException(
                'La pratica deve essere persistita prima di registrare eventi.'
            );
        }

        if (! $actor->exists) {
            throw new RuntimeException(
                'L’utente deve essere persistito prima di registrare eventi.'
            );
        }

        if (
            ! in_array(
                $eventType,
                ProductCaseEvent::EVENT_TYPES,
                true
            )
        ) {
            throw new RuntimeException(
                'Il tipo di evento della pratica non è valido.'
            );
        }

        $productCase->loadMissing(
            'team'
        );

        if (
            $productCase->team_id === null
            || $productCase->team === null
        ) {
            throw new RuntimeException(
                'La pratica non appartiene a un team valido.'
            );
        }

        if (
            (int) $actor->current_team_id
                !== (int) $productCase->team_id
            || ! $actor->belongsToTeam(
                $productCase->team
            )
        ) {
            throw new RuntimeException(
                'L’utente non può registrare eventi per una pratica appartenente a un altro team.'
            );
        }

        $event = new ProductCaseEvent();

        $event->forceFill([
            'product_case_id' =>
                $productCase->id,

            'actor_user_id' =>
                $actor->id,

            'event_type' =>
                $eventType,

            'title' =>
                $title,

            'description' =>
                $description,

            'source' =>
                $source,

            'occurred_at' =>
                $occurredAt,

            'metadata' => [
                ...$metadata,

                'recorder' =>
                    self::VERSION,
            ],
        ]);

        $event->save();

        return $event->refresh();
    }
}