<?php

namespace App\Services\Monetization;

use App\Models\Plan;

final class PlanCatalogResolver
{
    /**
     * @return list<array<string, mixed>>
     */
    public function resolve(): array
    {
        return Plan::query()
            ->with(['currency', 'limits', 'features'])
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (Plan $plan): array => [
                'id' => (int) $plan->id,
                'code' => $plan->code,
                'name' => $plan->name,
                'description' => $plan->description,
                'monthly_price_cents' =>
                    (int) $plan->monthly_price_cents,
                'currency_code' => $plan->currency?->code,
                'price_label' => $plan->monthly_price_cents > 0
                    ? number_format(
                        $plan->monthly_price_cents / 100,
                        2,
                        ',',
                        '.'
                    ) . ' ' . ($plan->currency?->code ?? 'EUR') . '/mese'
                    : ($plan->code === 'free'
                        ? 'Gratuito'
                        : 'Prezzo da definire'),
                'limits' => $plan->limits
                    ->where('is_active', true)
                    ->mapWithKeys(fn ($limit): array => [
                        $limit->limit_key => [
                            'value' => $limit->limit_value,
                            'reset_period' => $limit->reset_period,
                            'description' => $limit->description,
                        ],
                    ])
                    ->all(),
                'features' => $plan->features
                    ->mapWithKeys(fn ($feature): array => [
                        $feature->feature_key => [
                            'enabled' => (bool) $feature->is_enabled,
                            'description' => $feature->description,
                        ],
                    ])
                    ->all(),
            ])
            ->values()
            ->all();
    }
}
