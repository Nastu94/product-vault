<?php

namespace App\Console\Commands\ProductVault;

use App\Services\Release\ReleaseReadinessInspector;
use Illuminate\Console\Command;

final class ReleaseReadinessCommand extends Command
{
    protected $signature = 'product-vault:release-readiness
        {--production : Applica il profilo di controllo produzione}
        {--strict : Considera i warning come errori}
        {--json : Restituisce il report in formato JSON}';

    protected $description =
        'Verifica configurazione, storage, code, strumenti, sicurezza, dati e monetizzazione prima del rilascio.';

    public function handle(
        ReleaseReadinessInspector $inspector
    ): int {
        $production = (bool) $this->option('production')
            || app()->environment('production');
        $report = $inspector->inspect($production);

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode(
                $report,
                JSON_PRETTY_PRINT
                | JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
            ));
        } else {
            $rows = collect($report['checks'])
                ->map(fn (array $check): array => [
                    strtoupper((string) $check['status']),
                    $check['group'],
                    $check['label'],
                    $check['message'],
                ])
                ->all();

            $this->table(
                ['Esito', 'Area', 'Controllo', 'Dettaglio'],
                $rows
            );

            $counts = $report['counts'];
            $this->newLine();
            $this->line(
                'Pass: ' . $counts['pass']
                . ' | Warning: ' . $counts['warning']
                . ' | Fail: ' . $counts['fail']
            );

            if ($production) {
                $this->line('Profilo applicato: produzione.');
            } else {
                $this->line('Profilo applicato: sviluppo/pilota.');
            }
        }

        $failed = (int) data_get($report, 'counts.fail', 0) > 0;
        $strictWarning = (bool) $this->option('strict')
            && (int) data_get($report, 'counts.warning', 0) > 0;

        if ($failed || $strictWarning) {
            if (! (bool) $this->option('json')) {
                $this->error('MVP release readiness checks failed.');
            }

            return self::FAILURE;
        }

        if (! (bool) $this->option('json')) {
            $this->info('MVP release readiness checks completed.');
        }

        return self::SUCCESS;
    }
}
