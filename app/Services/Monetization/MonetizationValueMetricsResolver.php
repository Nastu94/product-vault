<?php

namespace App\Services\Monetization;

use App\Models\ProductCase;
use App\Models\Team;

final class MonetizationValueMetricsResolver
{
    /**
     * @return array<string, mixed>
     */
    public function resolve(Team $team): array
    {
        $cases = ProductCase::query()
            ->where('team_id', $team->id)
            ->get([
                'id',
                'opened_by_user_id',
                'status',
                'outcome',
                'opened_at',
                'resolved_at',
                'closed_at',
            ]);

        $started = $cases->count();
        $concluded = $cases
            ->where('status', ProductCase::STATUS_CLOSED)
            ->count();
        $cancelled = $cases
            ->where('status', ProductCase::STATUS_CANCELLED)
            ->count();
        $resolvedOrClosed = $cases->filter(
            fn (ProductCase $case): bool => in_array(
                $case->status,
                [
                    ProductCase::STATUS_RESOLVED,
                    ProductCase::STATUS_CLOSED,
                ],
                true
            )
        );

        $resolutionDurations = $resolvedOrClosed
            ->filter(
                fn (ProductCase $case): bool =>
                    $case->opened_at !== null
                    && $case->resolved_at !== null
            )
            ->map(
                fn (ProductCase $case): float => round(
                    $case->opened_at->diffInMinutes(
                        $case->resolved_at
                    ) / 1440,
                    2
                )
            );

        $averageResolutionDays = $resolutionDurations->isNotEmpty()
            ? round((float) $resolutionDurations->average(), 1)
            : null;

        $repeatUsers = $cases
            ->whereNotNull('opened_by_user_id')
            ->groupBy('opened_by_user_id')
            ->filter(fn ($userCases): bool => $userCases->count() > 1)
            ->count();

        $outcomes = [
            'repaired' => $resolvedOrClosed
                ->where('outcome', ProductCase::OUTCOME_REPAIRED)
                ->count(),
            'replaced' => $resolvedOrClosed
                ->where('outcome', ProductCase::OUTCOME_REPLACED)
                ->count(),
            'refunded' => $resolvedOrClosed
                ->where('outcome', ProductCase::OUTCOME_REFUNDED)
                ->count(),
            'rejected' => $resolvedOrClosed
                ->where('outcome', ProductCase::OUTCOME_REJECTED)
                ->count(),
            'abandoned' => $resolvedOrClosed
                ->where('outcome', ProductCase::OUTCOME_ABANDONED)
                ->count(),
            'other' => $resolvedOrClosed
                ->where('outcome', ProductCase::OUTCOME_OTHER)
                ->count(),
        ];

        $successfulOutcomes = $outcomes['repaired']
            + $outcomes['replaced']
            + $outcomes['refunded'];

        return [
            'practices_started' => $started,
            'practices_concluded' => $concluded,
            'practices_cancelled' => $cancelled,
            'successful_outcomes' => $successfulOutcomes,
            'outcomes' => $outcomes,
            'average_resolution_days' => $averageResolutionDays,
            'repeat_users' => $repeatUsers,
            'completion_rate_percent' => $started > 0
                ? (int) round(($concluded / $started) * 100)
                : 0,
        ];
    }
}
