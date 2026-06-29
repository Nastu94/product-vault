<?php

namespace App\Services\ProductCases;

use App\Models\ProductCase;
use App\Models\ProductCaseEvent;
use App\Models\User;
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