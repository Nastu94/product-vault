<?php

namespace App\Console\Commands\ProductVault;

use Illuminate\Console\Command;

class TestUnderstandingCommand extends Command
{
    protected $signature = 'product-vault:test-understanding
        {--fresh : Reset database with migrate:fresh --seed before running understanding tests}';

    protected $description = 'Seed and run the full Product Understanding fixture suite';

    public function handle(): int
    {
        if ($this->option('fresh')) {
            if (! app()->environment(['local', 'testing'])) {
                $this->error('The --fresh option is allowed only in local/testing environments.');

                return self::FAILURE;
            }

            $this->warn('Running migrate:fresh --seed. Local database data will be deleted.');

            if (! $this->confirm('Continue?', false)) {
                $this->info('Aborted.');

                return self::FAILURE;
            }

            $freshExitCode = $this->call('migrate:fresh', [
                '--seed' => true,
            ]);

            if ($freshExitCode !== self::SUCCESS) {
                return $freshExitCode;
            }
        }

        $seedExitCode = $this->call('product-vault:seed-understanding-knowledge');

        if ($seedExitCode !== self::SUCCESS) {
            return $seedExitCode;
        }

        $initialKnowledgeExitCode = $this->call('product-vault:seed-initial-knowledge');

        if ($initialKnowledgeExitCode !== self::SUCCESS) {
            return $initialKnowledgeExitCode;
        }

        $fixturesExitCode = $this->call('product-vault:run-understanding-fixtures');

        if ($fixturesExitCode !== self::SUCCESS) {
            return $fixturesExitCode;
        }

        $this->info('Product Understanding test suite passed.');

        return self::SUCCESS;
    }
}