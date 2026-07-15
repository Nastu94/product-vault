<?php

namespace App\Console\Commands\ProductVault;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

final class TestReleaseFailureSafetyCommand extends Command
{
    protected $signature =
        'product-vault:test-release-failure-safety';

    protected $description =
        'Verifica i contratti di rollback, idempotenza e registrazione degli errori operativi.';

    public function handle(): int
    {
        $rows = [];
        $failures = [];

        $assertSame = function (
            string $scenario,
            string $assertion,
            mixed $expected,
            mixed $actual
        ) use (&$rows, &$failures): void {
            $passed = $expected === $actual;
            $rows[] = [$scenario, $assertion, $passed ? 'OK' : 'FAIL'];

            if (! $passed) {
                $failures[] = compact(
                    'scenario',
                    'assertion',
                    'expected',
                    'actual'
                );
            }
        };

        try {
            $uploadPath = app_path(
                'Actions/Documents/StoreUploadedDocumentAction.php'
            );
            $jobPath = app_path('Jobs/ProcessDocumentJob.php');
            $usageMigrationPath = database_path(
                'migrations/2026_07_15_200100_create_usage_events_table.php'
            );

            foreach (
                [$uploadPath, $jobPath, $usageMigrationPath]
                as $path
            ) {
                if (! File::exists($path)) {
                    throw new RuntimeException(
                        'File richiesto non disponibile: ' . $path
                    );
                }
            }

            $upload = File::get($uploadPath);
            $job = File::get($jobPath);
            $usageMigration = File::get($usageMigrationPath);

            $assertSame(
                'upload',
                'persistence wrapped in transaction',
                true,
                str_contains($upload, 'DB::transaction(function () use')
            );
            $assertSame(
                'upload',
                'physical media cleanup available',
                true,
                str_contains($upload, 'cleanupFailedPersistence')
                    && str_contains($upload, 'File::delete')
            );
            $assertSame(
                'upload',
                'original exception preserved',
                true,
                str_contains($upload, 'throw $exception;')
                    && str_contains(
                        $upload,
                        'Document upload cleanup failed.'
                    )
            );
            $assertSame(
                'dispatch',
                'dispatch occurs after persistence block',
                true,
                strpos($upload, 'ProcessDocumentJob::dispatch')
                    > strpos($upload, 'return $document->refresh();')
            );
            $assertSame(
                'dispatch',
                'dispatch failure recorded',
                true,
                str_contains(
                    $upload,
                    "'step' => 'dispatch'"
                )
                    && str_contains(
                        $upload,
                        "'text_extraction_status' => 'failed'"
                    )
            );
            $assertSame(
                'pipeline',
                'processing steps record failed attempts',
                true,
                str_contains(
                    $job,
                    "'status' => 'failed'"
                )
                    && str_contains(
                        $job,
                        "'exception_class' => \$exception::class"
                    )
                    && str_contains(
                        $job,
                        "'completed_at' => now()"
                    )
            );
            $assertSame(
                'metering',
                'usage events enforce idempotency',
                true,
                str_contains($usageMigration, '$table->unique(')
                    && str_contains(
                        $usageMigration,
                        "['team_id', 'event_key', 'idempotency_key']"
                    )
            );
        } catch (Throwable $exception) {
            $rows[] = ['runtime', 'failure safety completed', 'FAIL'];
            $failures[] = [
                'scenario' => 'runtime',
                'assertion' => 'failure safety completed',
                'expected' => 'no exception',
                'actual' => $exception::class
                    . ': '
                    . $exception->getMessage(),
            ];
        }

        $this->table(['Scenario', 'Assertion', 'Status'], $rows);

        if ($failures !== []) {
            foreach ($failures as $failure) {
                $this->error(
                    $failure['scenario']
                    . ' / '
                    . $failure['assertion']
                );
                $this->line(
                    'Expected: '
                    . var_export($failure['expected'], true)
                );
                $this->line(
                    'Actual: '
                    . var_export($failure['actual'], true)
                );
            }

            return self::FAILURE;
        }

        $this->info('Release failure safety checks passed.');

        return self::SUCCESS;
    }
}
