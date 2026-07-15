<?php

namespace App\Observers;

use App\Models\Product;
use App\Models\Team;
use App\Services\Monetization\PlanLimitDecisionService;
use App\Services\Monetization\UsageMeter;
use App\Support\Monetization\MonetizationKeys;

final class ProductObserver
{
    public function __construct(
        private readonly PlanLimitDecisionService $limitDecisionService,
        private readonly UsageMeter $usageMeter
    ) {
    }

    public function creating(Product $product): void
    {
        if ($product->team_id === null) {
            return;
        }

        $team = Team::query()->find($product->team_id);

        if ($team === null) {
            return;
        }

        $this->limitDecisionService->ensureCanConsume(
            $team,
            MonetizationKeys::LIMIT_MAX_PRODUCTS,
            1
        );
    }

    public function created(Product $product): void
    {
        if ($product->team_id === null) {
            return;
        }

        $team = Team::query()->find($product->team_id);

        if ($team === null) {
            return;
        }

        $this->usageMeter->record(
            team: $team,
            eventKey: MonetizationKeys::EVENT_PRODUCT_CREATED,
            quantity: 1,
            idempotencyKey: 'product:' . $product->id . ':created',
            userId: $product->created_by_user_id
                ? (int) $product->created_by_user_id
                : null,
            subject: $product,
            metadata: [
                'source' => 'product_observer',
            ],
        );
    }
}
