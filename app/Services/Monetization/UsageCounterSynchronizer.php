<?php

namespace App\Services\Monetization;

use App\Models\Team;
use App\Models\UsageCounter;
use App\Support\Monetization\MonetizationKeys;

final class UsageCounterSynchronizer
{
    public function __construct(
        private readonly UsageSnapshotResolver $snapshotResolver,
        private readonly PlanEntitlementResolver $entitlementResolver
    ) {
    }

    /**
     * Ricostruisce i contatori autorevoli a partire dai record correnti.
     *
     * @return array<string, int>
     */
    public function synchronize(Team $team): array
    {
        $snapshot = $this->snapshotResolver->resolve($team);
        $entitlements = $this->entitlementResolver->resolve($team);

        $mapping = [
            MonetizationKeys::LIMIT_MAX_DOCUMENTS => 'documents_current',
            MonetizationKeys::LIMIT_MAX_PRODUCTS => 'products_current',
            MonetizationKeys::LIMIT_MAX_STORAGE_MB => 'storage_mb_current',
            MonetizationKeys::LIMIT_MAX_TEAM_MEMBERS => 'team_members_current',
            MonetizationKeys::LIMIT_MAX_OPEN_PRODUCT_CASES => 'open_product_cases_current',
            MonetizationKeys::LIMIT_MAX_OCR_PER_MONTH => 'ocr_runs',
        ];

        $result = [];

        foreach ($mapping as $limitKey => $counterKey) {
            $resource = data_get(
                $snapshot,
                'resources.' . $limitKey,
                []
            );

            $used = (int) data_get($resource, 'used', 0);
            $period = data_get($resource, 'reset_period', 'none');
            $periodStartsAt = $period === 'monthly'
                ? now()->startOfMonth()->toDateString()
                : null;
            $periodEndsAt = $period === 'monthly'
                ? now()->endOfMonth()->toDateString()
                : null;
            $planLimitId = data_get(
                $entitlements,
                'limits.' . $limitKey . '.plan_limit_id'
            );

            $counter = UsageCounter::query()
                ->where('team_id', $team->id)
                ->whereNull('user_id')
                ->where('counter_key', $counterKey)
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
                ->first();

            if ($counter === null) {
                $counter = new UsageCounter();
                $counter->team_id = $team->id;
                $counter->user_id = null;
                $counter->counter_key = $counterKey;
                $counter->period_starts_at = $periodStartsAt;
                $counter->period_ends_at = $periodEndsAt;
            }

            $counter->plan_limit_id = is_numeric($planLimitId)
                ? (int) $planLimitId
                : null;
            $counter->used_value = $used;
            $counter->metadata = [
                'source' => 'authoritative_snapshot',
                'limit_key' => $limitKey,
                'synchronized_at' => now()->toIso8601String(),
            ];
            $counter->save();

            $result[$counterKey] = $used;
        }

        return $result;
    }
}
