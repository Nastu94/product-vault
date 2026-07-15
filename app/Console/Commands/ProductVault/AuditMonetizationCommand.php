<?php

namespace App\Console\Commands\ProductVault;

use App\Models\Team;
use App\Services\Monetization\MonetizationValueMetricsResolver;
use App\Services\Monetization\PlanEntitlementResolver;
use App\Services\Monetization\UsageSnapshotResolver;
use App\Support\Monetization\MonetizationKeys;
use Illuminate\Console\Command;

final class AuditMonetizationCommand extends Command
{
    protected $signature =
        'product-vault:audit-monetization {--team= : ID del team da verificare}';

    protected $description =
        'Mostra piano, limiti, utilizzo e metriche operative dei workspace.';

    public function handle(
        PlanEntitlementResolver $entitlementResolver,
        UsageSnapshotResolver $usageSnapshotResolver,
        MonetizationValueMetricsResolver $metricsResolver
    ): int {
        $query = Team::query()->orderBy('id');

        if ($this->option('team') !== null) {
            $query->whereKey((int) $this->option('team'));
        }

        $teams = $query->get();

        if ($teams->isEmpty()) {
            $this->warn('Nessun workspace trovato.');

            return self::SUCCESS;
        }

        $rows = [];
        $anomalies = [];

        foreach ($teams as $team) {
            $entitlements = $entitlementResolver->resolve($team);
            $usage = $usageSnapshotResolver->resolve($team);
            $metrics = $metricsResolver->resolve($team);

            $missingLimits = collect(MonetizationKeys::limitKeys())
                ->reject(
                    fn (string $key): bool => is_array(
                        data_get($entitlements, 'limits.' . $key)
                    )
                )
                ->values()
                ->all();

            $missingFeatures = collect(MonetizationKeys::featureKeys())
                ->reject(
                    fn (string $key): bool => is_array(
                        data_get($entitlements, 'features.' . $key)
                    )
                )
                ->values()
                ->all();

            $capacityAlerts = collect(
                data_get($usage, 'resources', [])
            )
                ->filter(
                    fn (array $resource): bool => in_array(
                        $resource['status'] ?? null,
                        ['exhausted', 'exceeded'],
                        true
                    )
                )
                ->map(
                    fn (array $resource, string $key): string =>
                        $key
                        . (($resource['status'] ?? null) === 'exceeded'
                            ? ' (superato)'
                            : ' (esaurito)')
                )
                ->values()
                ->all();

            if (data_get($entitlements, 'plan') === null) {
                $anomalies[] = 'Team ' . $team->id . ': piano mancante.';
            }

            if ($missingLimits !== []) {
                $anomalies[] = 'Team ' . $team->id
                    . ': limiti mancanti '
                    . implode(', ', $missingLimits)
                    . '.';
            }

            if ($missingFeatures !== []) {
                $anomalies[] = 'Team ' . $team->id
                    . ': funzionalità mancanti '
                    . implode(', ', $missingFeatures)
                    . '.';
            }

            $rows[] = [
                $team->id,
                $team->name,
                data_get($entitlements, 'plan.code', 'missing'),
                data_get($usage, 'raw.documents_count', 0),
                data_get($usage, 'raw.products_count', 0),
                data_get($usage, 'raw.storage_mb', 0),
                data_get($usage, 'raw.ocr_runs_this_month', 0),
                data_get($usage, 'raw.open_product_cases_count', 0),
                data_get($metrics, 'practices_concluded', 0),
                $capacityAlerts === []
                    ? 'OK'
                    : implode(', ', $capacityAlerts),
            ];
        }

        $this->table([
            'Team',
            'Workspace',
            'Piano',
            'Documenti',
            'Prodotti',
            'Storage MB',
            'OCR mese',
            'Pratiche aperte',
            'Pratiche concluse',
            'Capacità esaurite/superate',
        ], $rows);

        foreach ($anomalies as $anomaly) {
            $this->error($anomaly);
        }

        if ($anomalies !== []) {
            return self::FAILURE;
        }

        $this->info('Monetization audit completed.');

        return self::SUCCESS;
    }
}
