<?php

namespace App\Services\Monetization;

use App\Models\AuditLog;
use App\Models\Plan;
use App\Models\Team;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class WorkspacePlanAssignmentService
{
    public function __construct(
        private readonly UsageSnapshotResolver $snapshotResolver,
        private readonly UsageCounterSynchronizer $counterSynchronizer
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function preview(Team $team, Plan $targetPlan): array
    {
        $targetPlan->loadMissing(['limits', 'features', 'currency']);
        $snapshot = $this->snapshotResolver->resolve($team);
        $currentPlan = data_get($snapshot, 'plan');
        $warningThreshold = max(
            1,
            min(
                100,
                (int) config(
                    'monetization.warning_threshold_percent',
                    80
                )
            )
        );

        $resources = $targetPlan->limits
            ->where('is_active', true)
            ->map(function ($limit) use (
                $snapshot,
                $warningThreshold
            ): array {
                $key = (string) $limit->limit_key;
                $currentResource = data_get(
                    $snapshot,
                    'resources.' . $key,
                    []
                );
                $used = (int) data_get($currentResource, 'used', 0);
                $limitValue = $limit->limit_value;
                $isUnlimited = $limitValue === null;
                $percentage = is_int($limitValue) && $limitValue > 0
                    ? (int) round(($used / $limitValue) * 100)
                    : null;

                $status = match (true) {
                    $isUnlimited => 'unlimited',
                    is_int($limitValue) && $used > $limitValue =>
                        'exceeded',
                    is_int($limitValue) && $used === $limitValue =>
                        'exhausted',
                    is_int($percentage)
                        && $percentage >= $warningThreshold => 'warning',
                    default => 'available',
                };

                return [
                    'key' => $key,
                    'label' => data_get(
                        $currentResource,
                        'label',
                        $key
                    ),
                    'used' => $used,
                    'limit' => is_int($limitValue)
                        ? $limitValue
                        : null,
                    'unit' => data_get($currentResource, 'unit'),
                    'status' => $status,
                    'remaining' => is_int($limitValue)
                        ? max(0, $limitValue - $used)
                        : null,
                    'percentage' => $percentage,
                ];
            })
            ->values()
            ->all();

        $incompatible = collect($resources)
            ->where('status', 'exceeded')
            ->values()
            ->all();

        $capacityAlerts = collect($resources)
            ->filter(
                fn (array $resource): bool => in_array(
                    $resource['status'],
                    ['warning', 'exhausted', 'exceeded'],
                    true
                )
            )
            ->values()
            ->all();

        return [
            'team_id' => (int) $team->getKey(),
            'team_name' => $team->name,
            'current_plan' => $currentPlan,
            'target_plan' => [
                'id' => (int) $targetPlan->getKey(),
                'code' => $targetPlan->code,
                'name' => $targetPlan->name,
                'is_active' => (bool) $targetPlan->is_active,
            ],
            'resources' => $resources,
            'capacity_alerts' => $capacityAlerts,
            'incompatible_resources' => $incompatible,
            'can_assign_without_force' => $incompatible === [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function assign(
        Team $team,
        Plan $targetPlan,
        ?int $actorUserId = null,
        bool $force = false
    ): array {
        if (! $targetPlan->is_active) {
            throw new InvalidArgumentException(
                'Il piano target non è attivo.'
            );
        }

        $preview = $this->preview($team, $targetPlan);

        if (
            ! ($preview['can_assign_without_force'] ?? false)
            && ! $force
        ) {
            throw new InvalidArgumentException(
                'L’utilizzo corrente supera uno o più limiti del piano target. Usa --force solo dopo una verifica esplicita.'
            );
        }

        return DB::transaction(function () use (
            $team,
            $targetPlan,
            $actorUserId,
            $force,
            $preview
        ): array {
            $previousPlanId = $team->plan_id;
            $previousPlanCode = data_get(
                $preview,
                'current_plan.code'
            );

            $team->forceFill([
                'plan_id' => $targetPlan->id,
            ])->save();

            $team->refresh();
            $synchronized = $this->counterSynchronizer
                ->synchronize($team);

            AuditLog::query()->create([
                'team_id' => $team->id,
                'user_id' => $actorUserId,
                'action' => 'workspace.plan_changed',
                'auditable_type' => $team->getMorphClass(),
                'auditable_id' => $team->id,
                'metadata' => [
                    'previous_plan_id' => $previousPlanId,
                    'previous_plan_code' => $previousPlanCode,
                    'target_plan_id' => (int) $targetPlan->id,
                    'target_plan_code' => $targetPlan->code,
                    'forced' => $force,
                    'incompatible_resources' => data_get(
                        $preview,
                        'incompatible_resources',
                        []
                    ),
                    'assigned_at' => now()->toIso8601String(),
                ],
            ]);

            return [
                'preview' => $preview,
                'team_id' => (int) $team->id,
                'previous_plan_id' => $previousPlanId,
                'target_plan_id' => (int) $targetPlan->id,
                'target_plan_code' => $targetPlan->code,
                'forced' => $force,
                'synchronized_counters' => $synchronized,
            ];
        });
    }
}
