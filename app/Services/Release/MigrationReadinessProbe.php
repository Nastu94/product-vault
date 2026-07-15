<?php

namespace App\Services\Release;

use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class MigrationReadinessProbe
{
    public function __construct(
        private readonly Migrator $migrator
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function inspect(): array
    {
        if (! Schema::hasTable('migrations')) {
            return [
                'group' => 'database',
                'key' => 'pending_migrations',
                'label' => 'Migration pendenti',
                'status' => 'fail',
                'message' => 'La tabella migrations non è disponibile.',
                'pending' => [],
            ];
        }

        try {
            $files = $this->migrator->getMigrationFiles(
                database_path('migrations')
            );
            $ran = $this->migrator
                ->getRepository()
                ->getRan();
            $pending = array_values(array_diff(
                array_keys($files),
                $ran
            ));

            return [
                'group' => 'database',
                'key' => 'pending_migrations',
                'label' => 'Migration pendenti',
                'status' => $pending === [] ? 'pass' : 'fail',
                'message' => $pending === []
                    ? 'Tutte le migration disponibili risultano applicate.'
                    : 'Migration non applicate: '
                        . implode(', ', $pending)
                        . '.',
                'pending' => $pending,
            ];
        } catch (Throwable $exception) {
            return [
                'group' => 'database',
                'key' => 'pending_migrations',
                'label' => 'Migration pendenti',
                'status' => 'fail',
                'message' => $exception::class
                    . ': '
                    . $exception->getMessage(),
                'pending' => [],
            ];
        }
    }
}
