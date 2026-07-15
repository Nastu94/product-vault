<?php

namespace App\Services\Monetization;

use App\Models\Team;
use App\Models\UsageCounter;
use App\Support\Monetization\MonetizationKeys;

final class MonetizationHealthResolver
{
    public function __construct(
        private readonly PlanEntitlementResolver $entitlementResolver,
        private readonly UsageSnapshotResolver $snapshotResolver
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function resolve(Team $team): array
    {
        $entitlements = $this->entitlementResolver->resolve($team);
        $snapshot = $this->snapshotResolver->resolve($team);
        $errors = [];
        $warnings = [];

        $plan = data_get($entitlements, 'plan');

        if (! is_array($plan)) {
            $errors[] = [
                'code' => 'plan_missing',
                'message' => 'Nessun piano risolto per il workspace.',
            ];
        } elseif (! (bool) data_get($plan, 'is_active', false)) {
            $errors[] = [
                'code' => 'plan_inactive',
                'message' => 'Il piano assegnato non è attivo.',
            ];
        }

        foreach (MonetizationKeys::limitKeys() as $limitKey) {
            if (! is_array(data_get($entitlements, 'limits.' . $limitKey))) {
                $errors[] = [
                    'code' => 'limit_missing',
                    'key' => $limitKey,
                    'message' => 'Limite mancante: ' . $limitKey . '.',
                ];
            }
        }

        foreach (MonetizationKeys::featureKeys() as $featureKey) {
            if (! is_array(data_get($entitlements, 'features.' . $featureKey))) {
                $errors[] = [
                    'code' => 'feature_missing',
                    'key' => $featureKey,
                    'message' => 'Funzionalità mancante: ' . $featureKey . '.',
                ];
            }
        }

        foreach ($this->counterMapping() as $limitKey => $counterKey) {
            $resource = data_get(
                $snapshot,
                'resources.' . $limitKey,
                []
            );
            $expected = (int) data_get($resource, 'used', 0);
            $period = data_get($resource, 'reset_period', 'none');
            $counter = $this->currentCounter(
                teamId: (int) $team->id,
                counterKey: $counterKey,
                period: (string) $period,
            );

            if ($counter === null) {
                $warnings[] = [
                    'code' => 'counter_missing',
                    'key' => $counterKey,
                    'message' => 'Contatore non sincronizzato: '
                        . $counterKey . '.',
                ];

                continue;
            }

            if ((int) $counter->used_value !== $expected) {
                $warnings[] = [
                    'code' => 'counter_drift',
                    'key' => $counterKey,
                    'expected' => $expected,
                    'actual' => (int) $counter->used_value,
                    'message' => 'Contatore non allineato: '
                        . $counterKey
                        . ' (atteso '
                        . $expected
                        . ', registrato '
                        . (int) $counter->used_value
                        . ').',
                ];
            }
        }

        $capacityAlerts = collect(
            data_get($snapshot, 'resources', [])
        )
            ->filter(
                fn (array $resource): bool => in_array(
                    $resource['status'] ?? null,
                    ['warning', 'exhausted', 'exceeded'],
                    true
                )
            )
            ->map(
                fn (array $resource, string $key): array => [
                    'code' => 'capacity_' . $resource['status'],
                    'key' => $key,
                    'status' => $resource['status'],
                    'message' => ($resource['label'] ?? $key)
                        . ': '
                        . $resource['status']
                        . '.',
                ]
            )
            ->values()
            ->all();

        $warnings = array_merge($warnings, $capacityAlerts);

        return [
            'team_id' => (int) $team->id,
            'workspace' => $team->name,
            'plan_code' => data_get($plan, 'code'),
            'errors' => $errors,
            'warnings' => $warnings,
            'error_count' => count($errors),
            'warning_count' => count($warnings),
            'status' => $errors !== []
                ? 'error'
                : ($warnings !== [] ? 'warning' : 'healthy'),
        ];
    }

    /** @return array<string, string> */
    private function counterMapping(): array
    {
        return [
            MonetizationKeys::LIMIT_MAX_DOCUMENTS =>
                'documents_current',
            MonetizationKeys::LIMIT_MAX_PRODUCTS =>
                'products_current',
            MonetizationKeys::LIMIT_MAX_STORAGE_MB =>
                'storage_mb_current',
            MonetizationKeys::LIMIT_MAX_TEAM_MEMBERS =>
                'team_members_current',
            MonetizationKeys::LIMIT_MAX_OPEN_PRODUCT_CASES =>
                'open_product_cases_current',
            MonetizationKeys::LIMIT_MAX_OCR_PER_MONTH =>
                'ocr_runs',
        ];
    }

    private function currentCounter(
        int $teamId,
        string $counterKey,
        string $period
    ): ?UsageCounter {
        return UsageCounter::query()
            ->where('team_id', $teamId)
            ->whereNull('user_id')
            ->where('counter_key', $counterKey)
            ->when(
                $period === 'monthly',
                fn ($query) => $query
                    ->whereDate(
                        'period_starts_at',
                        now()->startOfMonth()->toDateString()
                    )
                    ->whereDate(
                        'period_ends_at',
                        now()->endOfMonth()->toDateString()
                    ),
                fn ($query) => $query
                    ->whereNull('period_starts_at')
                    ->whereNull('period_ends_at')
            )
            ->first();
    }
}
