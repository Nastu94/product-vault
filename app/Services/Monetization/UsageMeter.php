<?php

namespace App\Services\Monetization;

use App\Models\Team;
use App\Models\UsageCounter;
use App\Models\UsageEvent;
use App\Support\Monetization\MonetizationKeys;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class UsageMeter
{
    public function __construct(
        private readonly PlanEntitlementResolver $entitlementResolver
    ) {
    }

    /**
     * Registra un evento una sola volta e aggiorna il relativo contatore.
     *
     * @param array<string, mixed> $metadata
     */
    public function record(
        Team $team,
        string $eventKey,
        int $quantity,
        string $idempotencyKey,
        ?int $userId = null,
        ?Model $subject = null,
        array $metadata = []
    ): UsageEvent {
        if ($quantity < 1) {
            throw new InvalidArgumentException(
                'La quantità dell’evento deve essere positiva.'
            );
        }

        $idempotencyKey = trim($idempotencyKey);

        if ($idempotencyKey === '') {
            throw new InvalidArgumentException(
                'La chiave di idempotenza è obbligatoria.'
            );
        }

        return DB::transaction(function () use (
            $team,
            $eventKey,
            $quantity,
            $idempotencyKey,
            $userId,
            $subject,
            $metadata
        ): UsageEvent {
            $event = UsageEvent::query()->firstOrCreate(
                [
                    'team_id' => $team->id,
                    'event_key' => $eventKey,
                    'idempotency_key' => $idempotencyKey,
                ],
                [
                    'user_id' => $userId,
                    'quantity' => $quantity,
                    'subject_type' => $subject?->getMorphClass(),
                    'subject_id' => $subject?->getKey(),
                    'occurred_at' => now(),
                    'metadata' => $metadata,
                ]
            );

            if ($event->wasRecentlyCreated) {
                $this->incrementCounter(
                    team: $team,
                    eventKey: $eventKey,
                    quantity: $quantity,
                    metadata: [
                        'last_usage_event_id' => $event->id,
                        'last_idempotency_key' => $idempotencyKey,
                    ],
                );
            }

            return $event->refresh();
        });
    }

    /** @param array<string, mixed> $metadata */
    private function incrementCounter(
        Team $team,
        string $eventKey,
        int $quantity,
        array $metadata
    ): void {
        $definition = $this->counterDefinition($eventKey);
        $periodStartsAt = $definition['period'] === 'monthly'
            ? now()->startOfMonth()->toDateString()
            : null;
        $periodEndsAt = $definition['period'] === 'monthly'
            ? now()->endOfMonth()->toDateString()
            : null;

        $entitlements = $this->entitlementResolver->resolve($team);
        $planLimitId = $definition['limit_key'] !== null
            ? data_get(
                $entitlements,
                'limits.' . $definition['limit_key'] . '.plan_limit_id'
            )
            : null;

        $counter = UsageCounter::query()
            ->where('team_id', $team->id)
            ->whereNull('user_id')
            ->where('counter_key', $definition['counter_key'])
            ->when(
                $periodStartsAt === null,
                fn ($query) => $query->whereNull('period_starts_at'),
                fn ($query) => $query->whereDate(
                    'period_starts_at',
                    $periodStartsAt
                )
            )
            ->when(
                $periodEndsAt === null,
                fn ($query) => $query->whereNull('period_ends_at'),
                fn ($query) => $query->whereDate(
                    'period_ends_at',
                    $periodEndsAt
                )
            )
            ->lockForUpdate()
            ->first();

        if ($counter === null) {
            $counter = UsageCounter::query()->create([
                'team_id' => $team->id,
                'user_id' => null,
                'plan_limit_id' => is_numeric($planLimitId)
                    ? (int) $planLimitId
                    : null,
                'counter_key' => $definition['counter_key'],
                'used_value' => 0,
                'period_starts_at' => $periodStartsAt,
                'period_ends_at' => $periodEndsAt,
                'metadata' => [],
            ]);
        }

        $counter->forceFill([
            'plan_limit_id' => is_numeric($planLimitId)
                ? (int) $planLimitId
                : null,
            'used_value' => (int) $counter->used_value + $quantity,
            'metadata' => array_merge(
                is_array($counter->metadata) ? $counter->metadata : [],
                $metadata
            ),
        ])->save();
    }

    /**
     * @return array{counter_key: string, limit_key: string|null, period: string}
     */
    private function counterDefinition(string $eventKey): array
    {
        return match ($eventKey) {
            MonetizationKeys::EVENT_DOCUMENT_UPLOADED => [
                'counter_key' => 'documents_uploaded',
                'limit_key' => MonetizationKeys::LIMIT_MAX_DOCUMENTS,
                'period' => 'none',
            ],
            MonetizationKeys::EVENT_STORAGE_BYTES_ADDED => [
                'counter_key' => 'storage_bytes_added',
                'limit_key' => MonetizationKeys::LIMIT_MAX_STORAGE_MB,
                'period' => 'none',
            ],
            MonetizationKeys::EVENT_PRODUCT_CREATED => [
                'counter_key' => 'products_created',
                'limit_key' => MonetizationKeys::LIMIT_MAX_PRODUCTS,
                'period' => 'none',
            ],
            MonetizationKeys::EVENT_OCR_RUN => [
                'counter_key' => 'ocr_runs',
                'limit_key' => MonetizationKeys::LIMIT_MAX_OCR_PER_MONTH,
                'period' => 'monthly',
            ],
            MonetizationKeys::EVENT_PRODUCT_CASE_OPENED => [
                'counter_key' => 'product_cases_opened',
                'limit_key' => MonetizationKeys::LIMIT_MAX_OPEN_PRODUCT_CASES,
                'period' => 'monthly',
            ],
            MonetizationKeys::EVENT_PRODUCT_CASE_RESOLVED => [
                'counter_key' => 'product_cases_resolved',
                'limit_key' => null,
                'period' => 'monthly',
            ],
            MonetizationKeys::EVENT_PRODUCT_CASE_CLOSED => [
                'counter_key' => 'product_cases_closed',
                'limit_key' => null,
                'period' => 'monthly',
            ],
            default => [
                'counter_key' => $eventKey,
                'limit_key' => null,
                'period' => 'monthly',
            ],
        };
    }
}
