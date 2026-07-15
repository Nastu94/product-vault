<?php

namespace App\Services\Monetization;

use App\Models\Team;
use App\Support\Monetization\MonetizationKeys;

final class MonetizationNoticeResolver
{
    public function __construct(
        private readonly UsageSnapshotResolver $snapshotResolver,
        private readonly PlanEntitlementResolver $entitlementResolver
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function resolve(Team $team): array
    {
        $snapshot = $this->snapshotResolver->resolve($team);
        $entitlements = $this->entitlementResolver->resolve($team);
        $mode = (string) data_get(
            $snapshot,
            'enforcement_mode',
            'observe'
        );

        $sharedWorkspaceEnabled = (bool) data_get(
            $entitlements,
            'features.'
            . MonetizationKeys::FEATURE_SHARED_WORKSPACE
            . '.enabled',
            false
        );

        $items = collect(data_get($snapshot, 'resources', []))
            ->filter(
                fn (array $resource): bool => in_array(
                    $resource['status'] ?? null,
                    ['warning', 'exhausted', 'exceeded'],
                    true
                )
            )
            ->reject(
                fn (array $resource, string $key): bool =>
                    $key === MonetizationKeys::LIMIT_MAX_TEAM_MEMBERS
                    && ($resource['status'] ?? null) === 'exhausted'
                    && ! $sharedWorkspaceEnabled
            )
            ->map(function (array $resource, string $key): array {
                $status = (string) ($resource['status'] ?? 'warning');
                $used = (int) ($resource['used'] ?? 0);
                $limit = is_int($resource['limit'] ?? null)
                    ? (int) $resource['limit']
                    : null;
                $unit = $resource['unit'] ?? null;

                return [
                    'key' => $key,
                    'label' => (string) ($resource['label'] ?? $key),
                    'status' => $status,
                    'severity' => $this->severityFor($status),
                    'used' => $used,
                    'limit' => $limit,
                    'remaining' => $resource['remaining'] ?? null,
                    'percentage' => $resource['percentage'] ?? null,
                    'unit' => $unit,
                    'reset_period' => $resource['reset_period'] ?? 'none',
                    'usage_label' => $this->usageLabel(
                        used: $used,
                        limit: $limit,
                        unit: is_string($unit) ? $unit : null,
                    ),
                    'message' => $this->itemMessage(
                        status: $status,
                        label: (string) ($resource['label'] ?? $key),
                        used: $used,
                        limit: $limit,
                        unit: is_string($unit) ? $unit : null,
                    ),
                ];
            })
            ->sortByDesc(
                fn (array $item): int => match ($item['severity']) {
                    'danger' => 3,
                    'critical' => 2,
                    default => 1,
                }
            )
            ->values()
            ->all();

        $highestSeverity = collect($items)
            ->pluck('severity')
            ->sortByDesc(fn (string $severity): int => match ($severity) {
                'danger' => 3,
                'critical' => 2,
                default => 1,
            })
            ->first();

        return [
            'team_id' => (int) $team->getKey(),
            'plan' => data_get($snapshot, 'plan'),
            'enforcement_mode' => $mode,
            'has_alerts' => $items !== [],
            'highest_severity' => $highestSeverity,
            'title' => $this->summaryTitle($highestSeverity),
            'message' => $this->summaryMessage(
                severity: is_string($highestSeverity)
                    ? $highestSeverity
                    : null,
                mode: $mode,
            ),
            'items' => $items,
        ];
    }

    private function severityFor(string $status): string
    {
        return match ($status) {
            'exceeded' => 'danger',
            'exhausted' => 'critical',
            default => 'warning',
        };
    }

    private function summaryTitle(?string $severity): string
    {
        return match ($severity) {
            'danger' => 'Alcune capacità del piano sono state superate',
            'critical' => 'Hai raggiunto una capacità del piano',
            'warning' => 'Una capacità del piano è quasi esaurita',
            default => 'Utilizzo del piano regolare',
        };
    }

    private function summaryMessage(
        ?string $severity,
        string $mode
    ): string {
        if ($severity === null) {
            return 'Le capacità del workspace sono disponibili.';
        }

        if ($mode === 'enforce') {
            return $severity === 'warning'
                ? 'Il workspace è vicino a un limite applicato. Controlla l’utilizzo prima della prossima operazione.'
                : 'I limiti sono applicati: alcune nuove operazioni possono essere bloccate finché non liberi capacità o assegni un piano adeguato.';
        }

        return $severity === 'warning'
            ? 'Il monitoraggio è attivo: il flusso continua, ma conviene controllare l’utilizzo disponibile.'
            : 'Il monitoraggio è attivo e non blocca ancora il flusso. Queste informazioni servono a validare limiti e piano prima dell’applicazione reale.';
    }

    private function usageLabel(
        int $used,
        ?int $limit,
        ?string $unit
    ): string {
        $suffix = $unit !== null ? ' ' . $unit : '';

        if ($limit === null) {
            return number_format($used, 0, ',', '.') . $suffix;
        }

        return number_format($used, 0, ',', '.')
            . ' / '
            . number_format($limit, 0, ',', '.')
            . $suffix;
    }

    private function itemMessage(
        string $status,
        string $label,
        int $used,
        ?int $limit,
        ?string $unit
    ): string {
        $usage = $this->usageLabel($used, $limit, $unit);

        return match ($status) {
            'exceeded' => $label . ': capacità superata (' . $usage . ').',
            'exhausted' => $label . ': capacità raggiunta (' . $usage . ').',
            default => $label . ': capacità quasi esaurita (' . $usage . ').',
        };
    }
}
