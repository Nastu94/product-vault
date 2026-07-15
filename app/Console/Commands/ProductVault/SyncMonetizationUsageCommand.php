<?php

namespace App\Console\Commands\ProductVault;

use App\Models\Team;
use App\Services\Monetization\UsageCounterSynchronizer;
use Illuminate\Console\Command;

final class SyncMonetizationUsageCommand extends Command
{
    protected $signature =
        'product-vault:sync-monetization-usage {--team= : ID del team da sincronizzare}';

    protected $description =
        'Ricostruisce i contatori di utilizzo dai dati autorevoli del workspace.';

    public function handle(
        UsageCounterSynchronizer $synchronizer
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

        foreach ($teams as $team) {
            $counters = $synchronizer->synchronize($team);

            $rows[] = [
                $team->id,
                $team->name,
                $counters['documents_current'] ?? 0,
                $counters['products_current'] ?? 0,
                $counters['storage_mb_current'] ?? 0,
                $counters['ocr_runs'] ?? 0,
                $counters['team_members_current'] ?? 0,
                $counters['open_product_cases_current'] ?? 0,
            ];
        }

        $this->table([
            'Team',
            'Workspace',
            'Documenti',
            'Prodotti',
            'Storage MB',
            'OCR mese',
            'Membri',
            'Pratiche aperte',
        ], $rows);

        $this->info('Monetization usage counters synchronized.');

        return self::SUCCESS;
    }
}
