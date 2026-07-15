<?php

namespace App\Services\Monetization;

use App\Models\Plan;
use App\Models\Team;

final class PlanEntitlementResolver
{
    /**
     * @return array<string, mixed>
     */
    public function resolve(Team $team): array
    {
        $team->loadMissing([
            'plan.currency',
            'plan.limits',
            'plan.features',
        ]);

        $plan = $team->plan;
        $fallbackApplied = false;

        if ($plan === null) {
            $plan = Plan::query()
                ->with(['currency', 'limits', 'features'])
                ->where('code', 'free')
                ->where('is_active', true)
                ->first();

            $fallbackApplied = $plan !== null;
        }

        if ($plan === null) {
            return [
                'plan' => null,
                'limits' => [],
                'features' => [],
                'fallback_applied' => false,
                'is_configured' => false,
            ];
        }

        $limits = $plan->limits
            ->where('is_active', true)
            ->mapWithKeys(fn ($limit): array => [
                $limit->limit_key => [
                    'key' => $limit->limit_key,
                    'value' => $limit->limit_value,
                    'reset_period' => $limit->reset_period,
                    'description' => $limit->description,
                    'metadata' => $limit->metadata ?? [],
                    'plan_limit_id' => (int) $limit->id,
                ],
            ])
            ->all();

        $features = $plan->features
            ->mapWithKeys(fn ($feature): array => [
                $feature->feature_key => [
                    'key' => $feature->feature_key,
                    'enabled' => (bool) $feature->is_enabled,
                    'description' => $feature->description,
                    'metadata' => $feature->metadata ?? [],
                ],
            ])
            ->all();

        return [
            'plan' => [
                'id' => (int) $plan->id,
                'code' => $plan->code,
                'name' => $plan->name,
                'description' => $plan->description,
                'monthly_price_cents' =>
                    (int) $plan->monthly_price_cents,
                'currency_code' => $plan->currency?->code,
                'is_active' => (bool) $plan->is_active,
                'sort_order' => (int) $plan->sort_order,
            ],
            'limits' => $limits,
            'features' => $features,
            'fallback_applied' => $fallbackApplied,
            'is_configured' => true,
        ];
    }

    public function hasFeature(Team $team, string $featureKey): bool
    {
        return (bool) data_get(
            $this->resolve($team),
            'features.' . $featureKey . '.enabled',
            false
        );
    }

    public function limitValue(Team $team, string $limitKey): ?int
    {
        $value = data_get(
            $this->resolve($team),
            'limits.' . $limitKey . '.value'
        );

        return is_int($value) ? $value : null;
    }
}
