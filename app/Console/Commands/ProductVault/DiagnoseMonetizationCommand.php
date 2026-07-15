<?php

namespace App\Console\Commands\ProductVault;

use App\Models\Team;
use App\Services\Monetization\MonetizationHealthResolver;
use Illuminate\Console\Command;

final class DiagnoseMonetizationCommand extends Command
{
    protected $signature =
        'product-vault:diagnose-monetization
        {--team= : ID del workspace da verificare}
        {--strict : Restituisce errore anche in presenza di warning}';

    protected $description =
        'Verifica contratto piano, contatori, capacità e anomalie della monetizzazione.';

    public function handle(
        MonetizationHealthResolver $healthResolver
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

        $results = $teams
            ->map(
                fn (Team $team): array => $healthResolver->resolve($team)
            )
            ->values();

        $this->table([
            'Team',
            'Workspace',
            'Piano',
            'Stato',
            'Errori',
            'Warning',
        ], $results->map(fn (array $result): array => [
            $result['team_id'],
            $result['workspace'],
            $result['plan_code'] ?? 'missing',
            $result['status'],
            $result['error_count'],
            $result['warning_count'],
        ])->all());

        foreach ($results as $result) {
            foreach ($result['errors'] as $error) {
                $this->error(
                    'Team ' . $result['team_id'] . ': ' . $error['message']
                );
            }

            foreach ($result['warnings'] as $warning) {
                $this->warn(
                    'Team ' . $result['team_id'] . ': ' . $warning['message']
                );
            }
        }

        $hasErrors = $results->contains(
            fn (array $result): bool => $result['error_count'] > 0
        );
        $hasWarnings = $results->contains(
            fn (array $result): bool => $result['warning_count'] > 0
        );

        if ($hasErrors || ((bool) $this->option('strict') && $hasWarnings)) {
            return self::FAILURE;
        }

        $this->info('Monetization diagnostics completed.');

        return self::SUCCESS;
    }
}
