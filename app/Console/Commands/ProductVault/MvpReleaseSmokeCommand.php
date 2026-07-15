<?php

namespace App\Console\Commands\ProductVault;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Throwable;

final class MvpReleaseSmokeCommand extends Command
{
    protected $signature = 'product-vault:mvp-release-smoke
        {--include-documents : Include le regressioni documentali più lunghe}
        {--stop-on-failure : Interrompe la suite al primo errore}
        {--show-output : Mostra l’output completo di ogni comando}';

    protected $description =
        'Esegue una smoke suite isolata sul flusso verticale dell’MVP.';

    public function handle(): int
    {
        $commands = config('release_readiness.smoke_commands', []);

        if ((bool) $this->option('include-documents')) {
            $commands = array_merge($commands, [
                'product-vault:regression-documents',
                'product-vault:test-understanding',
                'product-vault:test-recognition-quality-contract',
            ]);
        }

        $commands = array_values(array_unique(array_filter(
            $commands,
            fn (mixed $command): bool => is_string($command)
                && $command !== ''
        )));

        $rows = [];
        $failures = [];

        foreach ($commands as $command) {
            $startedAt = microtime(true);
            $exitCode = self::FAILURE;
            $output = '';
            $error = null;

            try {
                $exitCode = Artisan::call($command);
                $output = trim(Artisan::output());
            } catch (Throwable $exception) {
                $error = $exception::class . ': ' . $exception->getMessage();
                $output = $error;
            }

            $duration = round(microtime(true) - $startedAt, 2);
            $passed = $exitCode === self::SUCCESS && $error === null;

            $rows[] = [
                $command,
                $passed ? 'OK' : 'FAIL',
                number_format($duration, 2, ',', '.') . 's',
            ];

            if ((bool) $this->option('show-output')) {
                $this->newLine();
                $this->line('>>> ' . $command);
                $this->line($output !== '' ? $output : '(nessun output)');
            }

            if (! $passed) {
                $failures[] = [
                    'command' => $command,
                    'exit_code' => $exitCode,
                    'output' => $output,
                ];

                if ((bool) $this->option('stop-on-failure')) {
                    break;
                }
            }
        }

        $this->table(
            ['Comando', 'Esito', 'Durata'],
            $rows
        );

        if ($failures !== []) {
            foreach ($failures as $failure) {
                $this->error(
                    $failure['command']
                    . ' è terminato con exit code '
                    . $failure['exit_code']
                    . '.'
                );

                if (! (bool) $this->option('show-output')) {
                    $lastLines = collect(preg_split(
                        '/\R/',
                        (string) $failure['output']
                    ))
                        ->filter()
                        ->take(-12)
                        ->implode(PHP_EOL);

                    if ($lastLines !== '') {
                        $this->line($lastLines);
                    }
                }
            }

            return self::FAILURE;
        }

        $this->info('MVP release smoke suite passed.');

        return self::SUCCESS;
    }
}
