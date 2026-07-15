<?php

namespace App\Observers;

use App\Models\ProductCase;
use App\Models\Team;
use App\Services\Monetization\PlanLimitDecisionService;
use App\Services\Monetization\UsageMeter;
use App\Support\Monetization\MonetizationKeys;

final class ProductCaseObserver
{
    public function __construct(
        private readonly PlanLimitDecisionService $limitDecisionService,
        private readonly UsageMeter $usageMeter
    ) {
    }

    public function creating(ProductCase $productCase): void
    {
        if ($productCase->team_id === null) {
            return;
        }

        $team = Team::query()->find($productCase->team_id);

        if ($team === null) {
            return;
        }

        $this->limitDecisionService->ensureCanConsume(
            $team,
            MonetizationKeys::LIMIT_MAX_OPEN_PRODUCT_CASES,
            1
        );
    }

    public function created(ProductCase $productCase): void
    {
        $this->record(
            productCase: $productCase,
            eventKey: MonetizationKeys::EVENT_PRODUCT_CASE_OPENED,
            suffix: 'opened',
        );
    }

    public function updated(ProductCase $productCase): void
    {
        if (! $productCase->wasChanged('status')) {
            return;
        }

        if ($productCase->status === ProductCase::STATUS_RESOLVED) {
            $this->record(
                productCase: $productCase,
                eventKey: MonetizationKeys::EVENT_PRODUCT_CASE_RESOLVED,
                suffix: 'resolved',
            );
        }

        if ($productCase->status === ProductCase::STATUS_CLOSED) {
            $this->record(
                productCase: $productCase,
                eventKey: MonetizationKeys::EVENT_PRODUCT_CASE_CLOSED,
                suffix: 'closed',
            );
        }
    }

    private function record(
        ProductCase $productCase,
        string $eventKey,
        string $suffix
    ): void {
        if ($productCase->team_id === null) {
            return;
        }

        $team = Team::query()->find($productCase->team_id);

        if ($team === null) {
            return;
        }

        $this->usageMeter->record(
            team: $team,
            eventKey: $eventKey,
            quantity: 1,
            idempotencyKey:
                'product-case:' . $productCase->id . ':' . $suffix,
            userId: $productCase->opened_by_user_id
                ? (int) $productCase->opened_by_user_id
                : null,
            subject: $productCase,
            metadata: [
                'status' => $productCase->status,
                'outcome' => $productCase->outcome,
            ],
        );
    }
}
