<?php

namespace App\Services\Monetization;

use App\Exceptions\Monetization\PlanLimitExceededException;
use App\Models\Team;

final class PlanLimitDecisionService
{
    public function __construct(
        private readonly UsageSnapshotResolver $usageSnapshotResolver
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function decide(
        Team $team,
        string $limitKey,
        int $increment = 1
    ): array {
        $increment = max(0, $increment);
        $snapshot = $this->usageSnapshotResolver->resolve($team);
        $resource = data_get(
            $snapshot,
            'resources.' . $limitKey
        );

        if (! is_array($resource)) {
            return [
                'limit_key' => $limitKey,
                'allowed' => true,
                'would_block' => false,
                'enforced' => false,
                'status' => 'unconfigured',
                'message' => 'Limite non configurato.',
                'resource' => null,
                'increment' => $increment,
            ];
        }

        $limit = $resource['limit'] ?? null;
        $used = (int) ($resource['used'] ?? 0);
        $projected = $used + $increment;
        $isUnlimited = (bool) ($resource['is_unlimited'] ?? false);
        $isConfigured = (bool) ($resource['is_configured'] ?? false);
        $wouldBlock = $isConfigured
            && ! $isUnlimited
            && is_int($limit)
            && $projected > $limit;

        $mode = (string) config(
            'monetization.enforcement_mode',
            'observe'
        );
        $enforced = $mode === 'enforce';
        $allowed = ! ($wouldBlock && $enforced);

        $message = $wouldBlock
            ? sprintf(
                'Il piano consente %d per %s; utilizzo previsto: %d.',
                $limit,
                (string) ($resource['label'] ?? $limitKey),
                $projected
            )
            : 'Operazione compatibile con il piano corrente.';

        return [
            'limit_key' => $limitKey,
            'allowed' => $allowed,
            'would_block' => $wouldBlock,
            'enforced' => $enforced,
            'status' => $wouldBlock
                ? ($enforced ? 'blocked' : 'observed_exceeded')
                : 'allowed',
            'message' => $message,
            'resource' => $resource,
            'increment' => $increment,
            'projected_usage' => $projected,
            'enforcement_mode' => $mode,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function ensureCanConsume(
        Team $team,
        string $limitKey,
        int $increment = 1
    ): array {
        $decision = $this->decide(
            team: $team,
            limitKey: $limitKey,
            increment: $increment,
        );

        if (($decision['allowed'] ?? true) !== true) {
            throw new PlanLimitExceededException($decision);
        }

        return $decision;
    }
}
