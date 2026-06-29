<?php

namespace App\Services\ProductCases;

use App\Models\ProductCase;
use App\Models\ProductCaseEvent;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

final class ProductCaseTimelineResolver
{
    public const VERSION =
        'product_case_timeline_v1';

    /**
     * @var list<string>
     */
    private const DRAFT_EVENT_TYPES = [
        ProductCaseEvent::TYPE_REQUEST_DRAFT_GENERATED,
        ProductCaseEvent::TYPE_REQUEST_DRAFT_REGENERATED,
        ProductCaseEvent::TYPE_REQUEST_DRAFT_EDITED,
    ];

    /**
     * Costruisce una rappresentazione normalizzata e read-only
     * della timeline della pratica.
     *
     * @return array<string, mixed>
     */
    public function resolve(
        ProductCase $productCase
    ): array {
        if (! $productCase->exists) {
            throw new RuntimeException(
                'La pratica deve essere persistita prima di leggerne la timeline.'
            );
        }

        $productCase->loadMissing(
            'product'
        );

        $product = $productCase->product;

        if ($product === null) {
            throw new RuntimeException(
                'Il prodotto della pratica non è disponibile.'
            );
        }

        if (
            $productCase->team_id === null
            || $product->team_id === null
            || (int) $productCase->team_id
                !== (int) $product->team_id
        ) {
            throw new RuntimeException(
                'La pratica e il prodotto non appartengono allo stesso team.'
            );
        }

        /*
         * Usiamo una query dedicata invece della relazione eventualmente
         * già caricata, così l'ordinamento resta sempre deterministico.
         */
        $events = $productCase
            ->events()
            ->with('actor')
            ->get();

        $selectedDocumentIds =
            DB::table(
                'product_case_documents'
            )
                ->where(
                    'product_case_id',
                    $productCase->id
                )
                ->pluck(
                    'document_id'
                )
                ->map(
                    fn (mixed $id): int =>
                        (int) $id
                )
                ->flip();

        $availablePhotoIds =
            Media::query()
                ->where(
                    'model_type',
                    $productCase
                        ->getMorphClass()
                )
                ->where(
                    'model_id',
                    $productCase->id
                )
                ->where(
                    'collection_name',
                    ProductCase
                        ::MEDIA_COLLECTION_ISSUE_PHOTOS
                )
                ->pluck('id')
                ->map(
                    fn (mixed $id): int =>
                        (int) $id
                )
                ->flip();

        $latestDraftEventId =
            $events
                ->whereIn(
                    'event_type',
                    self::DRAFT_EVENT_TYPES
                )
                ->last()
                ?->id;

        $currentDraftHash =
            $this->currentDraftHash(
                $productCase
            );

        $normalizedEvents = $events
            ->map(
                fn (
                    ProductCaseEvent $event
                ): array => $this->normalizeEvent(
                    event: $event,
                    selectedDocumentIds:
                        $selectedDocumentIds,
                    availablePhotoIds:
                        $availablePhotoIds,
                    latestDraftEventId:
                        $latestDraftEventId !== null
                            ? (int) $latestDraftEventId
                            : null,
                    currentDraftHash:
                        $currentDraftHash,
                )
            )
            ->values()
            ->all();

        $countsByCategory = [];

        foreach ($normalizedEvents as $event) {
            $category =
                $event['category'];

            $countsByCategory[$category] =
                ($countsByCategory[$category] ?? 0)
                + 1;
        }

        ksort($countsByCategory);

        return [
            'version' =>
                self::VERSION,

            'product_case_id' =>
                (int) $productCase->id,

            'current_status' =>
                $productCase->status,

            'current_status_label' =>
                $this->statusLabel(
                    $productCase->status
                ),

            'event_count' =>
                count($normalizedEvents),

            'counts_by_category' =>
                $countsByCategory,

            'first_occurred_at' =>
                $normalizedEvents[0][
                    'occurred_at'
                ] ?? null,

            'last_occurred_at' =>
                $normalizedEvents !== []
                    ? $normalizedEvents[
                        array_key_last(
                            $normalizedEvents
                        )
                    ]['occurred_at']
                    : null,

            'events' =>
                $normalizedEvents,
        ];
    }

    /**
     * @param  Collection<int, int>  $selectedDocumentIds
     * @param  Collection<int, int>  $availablePhotoIds
     * @return array<string, mixed>
     */
    private function normalizeEvent(
        ProductCaseEvent $event,
        Collection $selectedDocumentIds,
        Collection $availablePhotoIds,
        ?int $latestDraftEventId,
        ?string $currentDraftHash
    ): array {
        $metadata = is_array(
            $event->metadata
        )
            ? $event->metadata
            : [];

        $category =
            $this->category(
                $event->event_type
            );

        $details =
            $this->details(
                eventType:
                    $event->event_type,
                metadata:
                    $metadata,
            );

        $reference =
            $this->reference(
                event:
                    $event,
                metadata:
                    $metadata,
                selectedDocumentIds:
                    $selectedDocumentIds,
                availablePhotoIds:
                    $availablePhotoIds,
                latestDraftEventId:
                    $latestDraftEventId,
                currentDraftHash:
                    $currentDraftHash,
            );

        return [
            'id' =>
                (int) $event->id,

            'type' =>
                $event->event_type,

            'is_known_type' =>
                in_array(
                    $event->event_type,
                    ProductCaseEvent::EVENT_TYPES,
                    true
                ),

            'category' =>
                $category,

            'category_label' =>
                $this->categoryLabel(
                    $category
                ),

            'label' =>
                $this->eventLabel(
                    eventType:
                        $event->event_type,
                    fallback:
                        $event->title,
                ),

            'summary' =>
                $this->summary(
                    event:
                        $event,
                    details:
                        $details,
                    reference:
                        $reference,
                ),

            'description' =>
                $this->nullableText(
                    $event->description
                ),

            'source' =>
                $this->nullableText(
                    $event->source
                ),

            'recorder_version' =>
                $this->nullableText(
                    $metadata['recorder']
                    ?? null
                ),

            'occurred_at' =>
                $event->occurred_at
                    ?->toISOString(),

            'actor' =>
                $this->actor($event),

            'reference' =>
                $reference,

            'details' =>
                $details,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function actor(
        ProductCaseEvent $event
    ): array {
        if ($event->actor !== null) {
            return [
                'id' =>
                    (int) $event->actor->id,

                'name' =>
                    $this->text(
                        $event->actor->name,
                        'Utente'
                    ),

                'kind' =>
                    'user',

                'is_available' =>
                    true,
            ];
        }

        if ($event->actor_user_id !== null) {
            return [
                'id' =>
                    (int) $event->actor_user_id,

                'name' =>
                    'Utente non disponibile',

                'kind' =>
                    'user',

                'is_available' =>
                    false,
            ];
        }

        return [
            'id' =>
                null,

            'name' =>
                'Sistema',

            'kind' =>
                'system',

            'is_available' =>
                true,
        ];
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function details(
        string $eventType,
        array $metadata
    ): array {
        return match ($eventType) {
            ProductCaseEvent::TYPE_CASE_OPENED =>
                $this->openingDetails(
                    $metadata
                ),

            ProductCaseEvent::TYPE_STATUS_CHANGED =>
                $this->statusDetails(
                    $metadata
                ),

            ProductCaseEvent::TYPE_DOCUMENT_SELECTED,
            ProductCaseEvent::TYPE_DOCUMENT_DESELECTED =>
                $this->documentDetails(
                    eventType:
                        $eventType,
                    metadata:
                        $metadata,
                ),

            ProductCaseEvent::TYPE_PHOTO_ADDED,
            ProductCaseEvent::TYPE_PHOTO_REMOVED =>
                $this->photoDetails(
                    eventType:
                        $eventType,
                    metadata:
                        $metadata,
                ),

            ProductCaseEvent::TYPE_REQUEST_DRAFT_GENERATED,
            ProductCaseEvent::TYPE_REQUEST_DRAFT_REGENERATED,
            ProductCaseEvent::TYPE_REQUEST_DRAFT_EDITED =>
                $this->draftDetails(
                    eventType:
                        $eventType,
                    metadata:
                        $metadata,
                ),

            default => [],
        };
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function openingDetails(
        array $metadata
    ): array {
        $initialStatus =
            $this->nullableText(
                $metadata[
                    'initial_status'
                ] ?? null
            );

        return [
            'initial_status' =>
                $initialStatus,

            'initial_status_label' =>
                $this->statusLabel(
                    $initialStatus
                ),
        ];
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function statusDetails(
        array $metadata
    ): array {
        $fromStatus =
            $this->nullableText(
                $metadata[
                    'from_status'
                ] ?? null
            );

        $toStatus =
            $this->nullableText(
                $metadata[
                    'to_status'
                ] ?? null
            );

        $outcome =
            $this->nullableText(
                $metadata[
                    'outcome'
                ] ?? null
            );

        return [
            'from_status' =>
                $fromStatus,

            'from_status_label' =>
                $this->statusLabel(
                    $fromStatus
                ),

            'to_status' =>
                $toStatus,

            'to_status_label' =>
                $this->statusLabel(
                    $toStatus
                ),

            'outcome' =>
                $outcome,

            'outcome_label' =>
                $this->outcomeLabel(
                    $outcome
                ),

            'resolution_notes' =>
                $this->nullableText(
                    $metadata[
                        'resolution_notes'
                    ] ?? null
                ),
        ];
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function documentDetails(
        string $eventType,
        array $metadata
    ): array {
        return [
            'action' =>
                $eventType
                    === ProductCaseEvent
                        ::TYPE_DOCUMENT_SELECTED
                    ? 'selected'
                    : 'deselected',

            'document_id' =>
                $this->integer(
                    $metadata[
                        'document_id'
                    ] ?? null
                ),

            'original_filename' =>
                $this->nullableText(
                    $metadata[
                        'original_filename'
                    ] ?? null
                ),

            'mime_type' =>
                $this->nullableText(
                    $metadata[
                        'mime_type'
                    ] ?? null
                ),

            'notes' =>
                $this->nullableText(
                    $metadata[
                        'notes'
                    ] ?? null
                ),

            'selected_by_user_id' =>
                $this->integer(
                    $metadata[
                        'selected_by_user_id'
                    ] ?? $metadata[
                        'original_selected_by_user_id'
                    ] ?? null
                ),

            'deselected_by_user_id' =>
                $this->integer(
                    $metadata[
                        'deselected_by_user_id'
                    ] ?? null
                ),
        ];
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function photoDetails(
        string $eventType,
        array $metadata
    ): array {
        return [
            'action' =>
                $eventType
                    === ProductCaseEvent
                        ::TYPE_PHOTO_ADDED
                    ? 'added'
                    : 'removed',

            'media_id' =>
                $this->integer(
                    $metadata[
                        'media_id'
                    ] ?? null
                ),

            'original_filename' =>
                $this->nullableText(
                    $metadata[
                        'original_filename'
                    ] ?? null
                ),

            'mime_type' =>
                $this->nullableText(
                    $metadata[
                        'mime_type'
                    ] ?? null
                ),

            'size' =>
                $this->integer(
                    $metadata[
                        'size'
                    ] ?? null
                ),

            'sha256' =>
                $this->sha256(
                    $metadata[
                        'sha256'
                    ] ?? null
                ),

            'uploaded_by_user_id' =>
                $this->integer(
                    $metadata[
                        'uploaded_by_user_id'
                    ] ?? null
                ),

            'removed_by_user_id' =>
                $this->integer(
                    $metadata[
                        'removed_by_user_id'
                    ] ?? null
                ),
        ];
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function draftDetails(
        string $eventType,
        array $metadata
    ): array {
        return [
            'action' =>
                match ($eventType) {
                    ProductCaseEvent
                        ::TYPE_REQUEST_DRAFT_GENERATED =>
                            'generated',

                    ProductCaseEvent
                        ::TYPE_REQUEST_DRAFT_REGENERATED =>
                            'regenerated',

                    default =>
                        'edited',
                },

            'previous_source' =>
                $this->nullableText(
                    $metadata[
                        'previous_source'
                    ] ?? null
                ),

            'current_source' =>
                $this->nullableText(
                    $metadata[
                        'current_source'
                    ] ?? null
                ),

            'previous_sha256' =>
                $this->sha256(
                    $metadata[
                        'previous_sha256'
                    ] ?? null
                ),

            'new_sha256' =>
                $this->sha256(
                    $metadata[
                        'new_sha256'
                    ] ?? null
                ),

            'source_fingerprint' =>
                $this->sha256(
                    $metadata[
                        'source_fingerprint'
                    ] ?? null
                ),

            'generation_kind' =>
                $this->nullableText(
                    $metadata[
                        'generation_kind'
                    ] ?? null
                ),

            'generated_by_user_id' =>
                $this->integer(
                    $metadata[
                        'generated_by_user_id'
                    ] ?? null
                ),

            'edited_by_user_id' =>
                $this->integer(
                    $metadata[
                        'edited_by_user_id'
                    ] ?? null
                ),
        ];
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @param  Collection<int, int>  $selectedDocumentIds
     * @param  Collection<int, int>  $availablePhotoIds
     * @return array<string, mixed>|null
     */
    private function reference(
        ProductCaseEvent $event,
        array $metadata,
        Collection $selectedDocumentIds,
        Collection $availablePhotoIds,
        ?int $latestDraftEventId,
        ?string $currentDraftHash
    ): ?array {
        if (
            in_array(
                $event->event_type,
                [
                    ProductCaseEvent
                        ::TYPE_DOCUMENT_SELECTED,

                    ProductCaseEvent
                        ::TYPE_DOCUMENT_DESELECTED,
                ],
                true
            )
        ) {
            $documentId =
                $this->integer(
                    $metadata[
                        'document_id'
                    ] ?? null
                );

            if ($documentId === null) {
                return null;
            }

            return [
                'type' =>
                    'document',

                'id' =>
                    $documentId,

                'label' =>
                    $this->text(
                        $metadata[
                            'original_filename'
                        ] ?? null,
                        'Documento #'
                            . $documentId
                    ),

                'state' =>
                    $selectedDocumentIds
                        ->has(
                            $documentId
                        )
                            ? 'selected'
                            : 'removed',
            ];
        }

        if (
            in_array(
                $event->event_type,
                [
                    ProductCaseEvent
                        ::TYPE_PHOTO_ADDED,

                    ProductCaseEvent
                        ::TYPE_PHOTO_REMOVED,
                ],
                true
            )
        ) {
            $mediaId =
                $this->integer(
                    $metadata[
                        'media_id'
                    ] ?? null
                );

            if ($mediaId === null) {
                return null;
            }

            return [
                'type' =>
                    'issue_photo',

                'id' =>
                    $mediaId,

                'label' =>
                    $this->text(
                        $metadata[
                            'original_filename'
                        ] ?? null,
                        'Fotografia #'
                            . $mediaId
                    ),

                'state' =>
                    $availablePhotoIds
                        ->has(
                            $mediaId
                        )
                            ? 'available'
                            : 'removed',
            ];
        }

        if (
            in_array(
                $event->event_type,
                self::DRAFT_EVENT_TYPES,
                true
            )
        ) {
            $newHash =
                $this->sha256(
                    $metadata[
                        'new_sha256'
                    ] ?? null
                );

            $isCurrent =
                $latestDraftEventId
                    === (int) $event->id
                && $newHash !== null
                && $currentDraftHash !== null
                && hash_equals(
                    $newHash,
                    $currentDraftHash
                );

            return [
                'type' =>
                    'request_draft',

                'id' =>
                    null,

                'label' =>
                    'Bozza di richiesta',

                'state' =>
                    $isCurrent
                        ? 'current'
                        : 'superseded',
            ];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $details
     * @param  array<string, mixed>|null  $reference
     */
    private function summary(
        ProductCaseEvent $event,
        array $details,
        ?array $reference
    ): string {
        return match ($event->event_type) {
            ProductCaseEvent::TYPE_CASE_OPENED =>
                'Pratica aperta',

            ProductCaseEvent::TYPE_STATUS_CHANGED =>
                'Stato: '
                . $this->text(
                    $details[
                        'from_status_label'
                    ] ?? null
                )
                . ' → '
                . $this->text(
                    $details[
                        'to_status_label'
                    ] ?? null
                ),

            ProductCaseEvent::TYPE_DOCUMENT_SELECTED =>
                'Documento selezionato: '
                . $this->text(
                    $reference['label']
                    ?? null
                ),

            ProductCaseEvent::TYPE_DOCUMENT_DESELECTED =>
                'Documento rimosso: '
                . $this->text(
                    $reference['label']
                    ?? null
                ),

            ProductCaseEvent::TYPE_PHOTO_ADDED =>
                'Fotografia aggiunta: '
                . $this->text(
                    $reference['label']
                    ?? null
                ),

            ProductCaseEvent::TYPE_PHOTO_REMOVED =>
                'Fotografia rimossa: '
                . $this->text(
                    $reference['label']
                    ?? null
                ),

            ProductCaseEvent::TYPE_REQUEST_DRAFT_GENERATED =>
                'Bozza generata automaticamente',

            ProductCaseEvent::TYPE_REQUEST_DRAFT_REGENERATED =>
                'Bozza rigenerata automaticamente',

            ProductCaseEvent::TYPE_REQUEST_DRAFT_EDITED =>
                'Bozza modificata manualmente',

            default =>
                $this->text(
                    $event->title,
                    'Evento pratica'
                ),
        };
    }

    private function category(
        string $eventType
    ): string {
        return match ($eventType) {
            ProductCaseEvent::TYPE_CASE_OPENED,
            ProductCaseEvent::TYPE_STATUS_CHANGED =>
                'workflow',

            ProductCaseEvent::TYPE_DOCUMENT_SELECTED,
            ProductCaseEvent::TYPE_DOCUMENT_DESELECTED,
            ProductCaseEvent::TYPE_PHOTO_ADDED,
            ProductCaseEvent::TYPE_PHOTO_REMOVED =>
                'evidence',

            ProductCaseEvent::TYPE_REQUEST_DRAFT_GENERATED,
            ProductCaseEvent::TYPE_REQUEST_DRAFT_REGENERATED,
            ProductCaseEvent::TYPE_REQUEST_DRAFT_EDITED =>
                'request_draft',

            default =>
                'other',
        };
    }

    private function categoryLabel(
        string $category
    ): string {
        return match ($category) {
            'workflow' =>
                'Workflow',

            'evidence' =>
                'Evidenze',

            'request_draft' =>
                'Bozza di richiesta',

            default =>
                'Altro',
        };
    }

    private function eventLabel(
        string $eventType,
        mixed $fallback
    ): string {
        return match ($eventType) {
            ProductCaseEvent::TYPE_CASE_OPENED =>
                'Pratica aperta',

            ProductCaseEvent::TYPE_STATUS_CHANGED =>
                'Stato aggiornato',

            ProductCaseEvent::TYPE_DOCUMENT_SELECTED =>
                'Documento selezionato',

            ProductCaseEvent::TYPE_DOCUMENT_DESELECTED =>
                'Documento rimosso',

            ProductCaseEvent::TYPE_PHOTO_ADDED =>
                'Fotografia aggiunta',

            ProductCaseEvent::TYPE_PHOTO_REMOVED =>
                'Fotografia rimossa',

            ProductCaseEvent::TYPE_REQUEST_DRAFT_GENERATED =>
                'Bozza generata',

            ProductCaseEvent::TYPE_REQUEST_DRAFT_REGENERATED =>
                'Bozza rigenerata',

            ProductCaseEvent::TYPE_REQUEST_DRAFT_EDITED =>
                'Bozza modificata',

            default =>
                $this->text(
                    $fallback,
                    'Evento pratica'
                ),
        };
    }

    private function statusLabel(
        mixed $status
    ): string {
        return match ($status) {
            ProductCase::STATUS_DRAFT =>
                'Bozza',

            ProductCase::STATUS_READY_TO_CONTACT =>
                'Pronta per il contatto',

            ProductCase::STATUS_CONTACTED =>
                'Contattato',

            ProductCase::STATUS_RESOLVED =>
                'Risolta',

            ProductCase::STATUS_CLOSED =>
                'Chiusa',

            ProductCase::STATUS_CANCELLED =>
                'Annullata',

            default =>
                'Stato non disponibile',
        };
    }

    private function outcomeLabel(
        mixed $outcome
    ): ?string {
        if ($outcome === null) {
            return null;
        }

        return match ($outcome) {
            ProductCase::OUTCOME_REPAIRED =>
                'Riparato',

            ProductCase::OUTCOME_REPLACED =>
                'Sostituito',

            ProductCase::OUTCOME_REFUNDED =>
                'Rimborsato',

            ProductCase::OUTCOME_REJECTED =>
                'Richiesta rifiutata',

            ProductCase::OUTCOME_ABANDONED =>
                'Abbandonata',

            ProductCase::OUTCOME_OTHER =>
                'Altro',

            default =>
                'Esito non disponibile',
        };
    }

    private function currentDraftHash(
        ProductCase $productCase
    ): ?string {
        if (
            ! is_string(
                $productCase->request_draft
            )
            || trim(
                $productCase->request_draft
            ) === ''
        ) {
            return null;
        }

        return hash(
            'sha256',
            $productCase->request_draft
        );
    }

    private function integer(
        mixed $value
    ): ?int {
        if (
            is_int($value)
            || (
                is_string($value)
                && ctype_digit($value)
            )
        ) {
            return (int) $value;
        }

        return null;
    }

    private function sha256(
        mixed $value
    ): ?string {
        if (
            ! is_string($value)
            || preg_match(
                '/^[a-f0-9]{64}$/',
                $value
            ) !== 1
        ) {
            return null;
        }

        return $value;
    }

    private function text(
        mixed $value,
        string $fallback =
            'Non disponibile'
    ): string {
        return $this->nullableText(
            $value
        ) ?? $fallback;
    }

    private function nullableText(
        mixed $value
    ): ?string {
        if (
            ! is_string($value)
            && ! is_int($value)
            && ! is_float($value)
        ) {
            return null;
        }

        $value = trim(
            (string) $value
        );

        return $value !== ''
            ? $value
            : null;
    }
}