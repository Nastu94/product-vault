<?php

namespace App\Console\Commands\ProductVault;

use App\Models\Team;
use App\Services\Release\WorkspaceEnvironmentClassifier;
use Illuminate\Console\Command;

final class AuditEnvironmentDataCommand extends Command
{
    protected $signature = 'product-vault:audit-environment-data
        {--production : Fallisce se trova workspace assimilabili a fixture}
        {--json : Restituisce il risultato in formato JSON}';

    protected $description =
        'Classifica i workspace applicativi e quelli assimilabili a fixture senza modificare dati.';

    public function handle(
        WorkspaceEnvironmentClassifier $classifier
    ): int {
        $items = Team::query()
            ->orderBy('id')
            ->get()
            ->map(
                fn (Team $team): array => $classifier->classify($team)
            )
            ->values();

        $fixtureCount = $items
            ->where('is_fixture_like', true)
            ->count();
        $applicationCount = $items->count() - $fixtureCount;

        $report = [
            'environment' => app()->environment(),
            'application_workspaces' => $applicationCount,
            'fixture_like_workspaces' => $fixtureCount,
            'items' => $items->all(),
        ];

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode(
                $report,
                JSON_PRETTY_PRINT
                | JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
            ));
        } else {
            $this->table(
                ['Team', 'Workspace', 'Ambito', 'Pattern'],
                $items->map(fn (array $item): array => [
                    $item['team_id'],
                    $item['workspace'],
                    $item['scope'],
                    implode(', ', $item['matched_patterns']),
                ])->all()
            );

            $this->line(
                'Workspace applicativi: ' . $applicationCount
                . ' | Assimilabili a fixture: ' . $fixtureCount
            );
        }

        $production = (bool) $this->option('production')
            || app()->environment('production');
        $fixturesAllowed = (bool) config(
            'release_readiness.allow_fixture_workspaces',
            false
        );

        if ($production && $fixtureCount > 0 && ! $fixturesAllowed) {
            if (! (bool) $this->option('json')) {
                $this->error(
                    'Sono presenti workspace di test in un profilo produzione.'
                );
            }

            return self::FAILURE;
        }

        if (! (bool) $this->option('json')) {
            $this->info('Environment data audit completed.');
        }

        return self::SUCCESS;
    }
}
