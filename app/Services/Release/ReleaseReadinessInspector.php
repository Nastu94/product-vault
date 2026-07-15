<?php

namespace App\Services\Release;

use App\Models\Plan;
use App\Models\Team;
use App\Services\Monetization\MonetizationHealthResolver;
use App\Support\Monetization\MonetizationKeys;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\ExecutableFinder;
use Throwable;

final class ReleaseReadinessInspector
{
    public function __construct(
        private readonly WorkspaceEnvironmentClassifier $workspaceClassifier,
        private readonly MonetizationHealthResolver $monetizationHealthResolver,
        private readonly ExecutableFinder $executableFinder,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function inspect(bool $production = false): array
    {
        $checks = [];

        $this->inspectEnvironment($checks, $production);
        $this->inspectDatabase($checks);
        $this->inspectStorage($checks, $production);
        $this->inspectQueue($checks, $production);
        $this->inspectTools($checks);
        $this->inspectRoutes($checks);
        $this->inspectMonetization($checks, $production);
        $this->inspectWorkspaceData($checks, $production);
        $this->inspectLegalConfiguration($checks, $production);

        $counts = collect($checks)
            ->countBy('status')
            ->all();

        return [
            'generated_at' => now()->toIso8601String(),
            'environment' => app()->environment(),
            'production_profile' => $production,
            'status' => ($counts['fail'] ?? 0) > 0
                ? 'fail'
                : (($counts['warning'] ?? 0) > 0 ? 'warning' : 'pass'),
            'counts' => [
                'pass' => (int) ($counts['pass'] ?? 0),
                'warning' => (int) ($counts['warning'] ?? 0),
                'fail' => (int) ($counts['fail'] ?? 0),
            ],
            'checks' => $checks,
        ];
    }

    /**
     * @param list<array<string, mixed>> $checks
     */
    private function inspectEnvironment(array &$checks, bool $production): void
    {
        $environment = (string) config('app.env');
        $debug = (bool) config('app.debug');
        $url = (string) config('app.url');
        $key = (string) config('app.key');
        $mode = (string) config(
            'monetization.enforcement_mode',
            'observe'
        );

        $this->add(
            $checks,
            'environment',
            'app_key',
            'Chiave applicativa',
            $key !== '' ? 'pass' : 'fail',
            $key !== ''
                ? 'APP_KEY è configurata.'
                : 'APP_KEY non è configurata.'
        );

        $this->add(
            $checks,
            'environment',
            'app_environment',
            'Ambiente applicativo',
            $production && $environment !== 'production'
                ? 'fail'
                : 'pass',
            'Ambiente corrente: ' . $environment . '.'
        );

        $this->add(
            $checks,
            'environment',
            'app_debug',
            'Debug applicativo',
            $production && $debug ? 'fail' : 'pass',
            $debug
                ? 'APP_DEBUG è attivo.'
                : 'APP_DEBUG è disattivato.'
        );

        $requiresHttps = (bool) config(
            'release_readiness.production.require_https',
            true
        );
        $usesHttps = str_starts_with(strtolower($url), 'https://');

        $this->add(
            $checks,
            'environment',
            'app_url',
            'URL applicativa',
            $production && $requiresHttps && ! $usesHttps
                ? 'fail'
                : 'pass',
            'APP_URL: ' . ($url !== '' ? $url : 'non configurata') . '.'
        );

        $this->add(
            $checks,
            'environment',
            'monetization_mode',
            'Modalità monetizzazione',
            $mode === 'observe' ? 'pass' : 'warning',
            $mode === 'observe'
                ? 'I limiti sono in monitoraggio non bloccante.'
                : 'La modalità enforce è attiva: verificare i flussi bloccati.'
        );

        $logLevel = (string) config('logging.level', env('LOG_LEVEL', 'debug'));
        $this->add(
            $checks,
            'environment',
            'log_level',
            'Livello dei log',
            $production && $logLevel === 'debug'
                ? 'warning'
                : 'pass',
            'Livello configurato: ' . $logLevel . '.'
        );
    }

    /**
     * @param list<array<string, mixed>> $checks
     */
    private function inspectDatabase(array &$checks): void
    {
        try {
            DB::connection()->getPdo();

            $this->add(
                $checks,
                'database',
                'connection',
                'Connessione database',
                'pass',
                'La connessione al database è disponibile.'
            );
        } catch (Throwable $exception) {
            $this->add(
                $checks,
                'database',
                'connection',
                'Connessione database',
                'fail',
                $exception->getMessage()
            );

            return;
        }

        $requiredTables = [
            'users',
            'teams',
            'documents',
            'products',
            'warranties',
            'product_cases',
            'plans',
            'plan_limits',
            'plan_features',
            'usage_counters',
            'usage_events',
            'migrations',
        ];

        foreach ($requiredTables as $table) {
            $present = Schema::hasTable($table);

            $this->add(
                $checks,
                'database',
                'table_' . $table,
                'Tabella ' . $table,
                $present ? 'pass' : 'fail',
                $present
                    ? 'Tabella disponibile.'
                    : 'Tabella mancante: eseguire le migration.'
            );
        }
    }

    /**
     * @param list<array<string, mixed>> $checks
     */
    private function inspectStorage(array &$checks, bool $production): void
    {
        $disk = (string) config('filesystems.default', 'local');
        $driver = (string) config('filesystems.disks.' . $disk . '.driver');
        $root = config('filesystems.disks.' . $disk . '.root');

        $this->add(
            $checks,
            'storage',
            'default_disk',
            'Disco documenti',
            $disk !== 'public' ? 'pass' : 'fail',
            'Disco predefinito: ' . $disk . ' (' . $driver . ').'
        );

        if ($driver === 'local' && is_string($root)) {
            $exists = is_dir($root);
            $writable = $exists && is_writable($root);
            $publicRoot = realpath(public_path()) ?: public_path();
            $resolvedRoot = realpath($root) ?: $root;
            $insidePublic = str_starts_with(
                str_replace('\\', '/', $resolvedRoot),
                rtrim(str_replace('\\', '/', $publicRoot), '/') . '/'
            );

            $this->add(
                $checks,
                'storage',
                'private_root',
                'Storage privato',
                $exists && $writable && ! $insidePublic
                    ? 'pass'
                    : 'fail',
                ! $exists
                    ? 'Directory storage non disponibile: ' . $root . '.'
                    : (! $writable
                        ? 'Directory storage non scrivibile: ' . $root . '.'
                        : ($insidePublic
                            ? 'Lo storage dei documenti ricade nella directory pubblica.'
                            : 'Directory privata e scrivibile: ' . $root . '.'))
            );
        } else {
            $this->add(
                $checks,
                'storage',
                'private_root',
                'Storage privato',
                $driver !== '' ? 'pass' : 'fail',
                'Storage remoto configurato con driver ' . $driver . '.'
            );
        }

        $publicLinkTarget = config(
            'filesystems.links.' . public_path('storage')
        );
        $privateRoot = config('filesystems.disks.local.root');

        $this->add(
            $checks,
            'storage',
            'public_link_isolation',
            'Isolamento link pubblico',
            $publicLinkTarget !== $privateRoot ? 'pass' : 'fail',
            $publicLinkTarget !== $privateRoot
                ? 'Il link pubblico non espone lo storage privato.'
                : 'Il link pubblico punta allo storage privato.'
        );

        if ($production && (bool) config('session.encrypt') === false) {
            $this->add(
                $checks,
                'security',
                'session_encryption',
                'Cifratura sessione',
                'warning',
                'SESSION_ENCRYPT è disattivato.'
            );
        } else {
            $this->add(
                $checks,
                'security',
                'session_encryption',
                'Cifratura sessione',
                'pass',
                'Configurazione sessione compatibile con il profilo corrente.'
            );
        }
    }

    /**
     * @param list<array<string, mixed>> $checks
     */
    private function inspectQueue(array &$checks, bool $production): void
    {
        $connection = (string) config('queue.default', 'sync');
        $driver = (string) config(
            'queue.connections.' . $connection . '.driver',
            $connection
        );
        $disallowed = config(
            'release_readiness.production.disallowed_queue_drivers',
            ['sync', 'null']
        );

        $this->add(
            $checks,
            'queue',
            'connection',
            'Coda applicativa',
            $production && in_array($driver, $disallowed, true)
                ? 'fail'
                : 'pass',
            'Connessione: ' . $connection . '; driver: ' . $driver . '.'
        );

        if ($driver === 'database') {
            foreach (['jobs', 'failed_jobs'] as $table) {
                $present = Schema::hasTable($table);
                $this->add(
                    $checks,
                    'queue',
                    'table_' . $table,
                    'Tabella coda ' . $table,
                    $present ? 'pass' : 'fail',
                    $present
                        ? 'Tabella disponibile.'
                        : 'Tabella mancante.'
                );
            }
        }

        if (Schema::hasTable('failed_jobs')) {
            $failedJobs = DB::table('failed_jobs')->count();
            $warningThreshold = (int) config(
                'release_readiness.production.max_failed_jobs_warning',
                0
            );

            $this->add(
                $checks,
                'queue',
                'failed_jobs',
                'Job falliti',
                $failedJobs > $warningThreshold ? 'warning' : 'pass',
                'Job falliti registrati: ' . $failedJobs . '.'
            );
        }
    }

    /**
     * @param list<array<string, mixed>> $checks
     */
    private function inspectTools(array &$checks): void
    {
        $primaryEngine = (string) config(
            'services.ocr.primary_engine',
            'paddleocr'
        );

        foreach (
            config('release_readiness.required_tools', [])
            as $key => $definition
        ) {
            if (! is_array($definition)) {
                continue;
            }

            $configured = config($definition['config_key'] ?? '');
            $configured = is_string($configured) ? $configured : '';
            $isFile = (bool) ($definition['file'] ?? false);
            $required = (bool) ($definition['required'] ?? false);

            if ($key === 'tesseract' && $primaryEngine === 'tesseract') {
                $required = true;
            }

            if (
                in_array($key, ['paddle_python', 'paddle_script'], true)
                && $primaryEngine !== 'paddleocr'
            ) {
                $required = false;
            }

            $resolved = null;
            if ($configured !== '') {
                if (is_file($configured)) {
                    $resolved = $configured;
                } elseif (! $isFile) {
                    $resolved = $this->executableFinder->find($configured);
                }
            }

            $available = $resolved !== null;
            $status = $available
                ? 'pass'
                : ($required ? 'fail' : 'warning');

            $this->add(
                $checks,
                'tools',
                (string) $key,
                (string) ($definition['label'] ?? $key),
                $status,
                $available
                    ? 'Disponibile: ' . $resolved . '.'
                    : 'Non trovato: ' . ($configured !== ''
                        ? $configured
                        : 'non configurato') . '.'
            );
        }
    }

    /**
     * @param list<array<string, mixed>> $checks
     */
    private function inspectRoutes(array &$checks): void
    {
        foreach (
            config('release_readiness.required_public_routes', [])
            as $routeName
        ) {
            $present = is_string($routeName) && Route::has($routeName);
            $this->add(
                $checks,
                'routes',
                'public_' . str_replace('.', '_', (string) $routeName),
                'Rotta pubblica ' . $routeName,
                $present ? 'pass' : 'fail',
                $present ? 'Rotta disponibile.' : 'Rotta mancante.'
            );
        }

        foreach (
            config('release_readiness.required_authenticated_routes', [])
            as $routeName
        ) {
            $route = is_string($routeName)
                ? Route::getRoutes()->getByName($routeName)
                : null;
            $middleware = $route?->gatherMiddleware() ?? [];
            $protected = collect($middleware)->contains(
                fn (string $item): bool => str_starts_with($item, 'auth')
            );

            $this->add(
                $checks,
                'routes',
                'auth_' . str_replace('.', '_', (string) $routeName),
                'Rotta autenticata ' . $routeName,
                $route !== null && $protected ? 'pass' : 'fail',
                $route === null
                    ? 'Rotta mancante.'
                    : ($protected
                        ? 'Rotta disponibile e protetta.'
                        : 'Rotta disponibile ma senza middleware auth.')
            );
        }
    }

    /**
     * @param list<array<string, mixed>> $checks
     */
    private function inspectMonetization(
        array &$checks,
        bool $production
    ): void {
        if (! Schema::hasTable('plans')) {
            return;
        }

        $plans = Plan::query()
            ->where('is_active', true)
            ->with(['limits', 'features'])
            ->orderBy('sort_order')
            ->get();

        $this->add(
            $checks,
            'monetization',
            'active_plans',
            'Catalogo piani',
            $plans->count() >= 4 ? 'pass' : 'fail',
            'Piani attivi disponibili: ' . $plans->count() . '.'
        );

        foreach ($plans as $plan) {
            $limitKeys = $plan->limits
                ->where('is_active', true)
                ->pluck('limit_key')
                ->all();
            $featureKeys = $plan->features
                ->pluck('feature_key')
                ->all();
            $missingLimits = array_values(array_diff(
                MonetizationKeys::limitKeys(),
                $limitKeys
            ));
            $missingFeatures = array_values(array_diff(
                MonetizationKeys::featureKeys(),
                $featureKeys
            ));

            $this->add(
                $checks,
                'monetization',
                'contract_' . $plan->code,
                'Contratto piano ' . $plan->code,
                $missingLimits === [] && $missingFeatures === []
                    ? 'pass'
                    : 'fail',
                $missingLimits === [] && $missingFeatures === []
                    ? 'Limiti e funzionalità completi.'
                    : 'Mancano limiti ['
                        . implode(', ', $missingLimits)
                        . '] e funzionalità ['
                        . implode(', ', $missingFeatures)
                        . '].'
            );
        }

        if (! Schema::hasTable('teams')) {
            return;
        }

        $unassigned = Team::query()->whereNull('plan_id')->count();
        $this->add(
            $checks,
            'monetization',
            'teams_without_plan',
            'Workspace senza piano',
            $unassigned === 0
                ? 'pass'
                : ($production ? 'fail' : 'warning'),
            'Workspace senza piano esplicito: ' . $unassigned . '.'
        );

        $operationalWarnings = 0;
        $operationalErrors = 0;

        Team::query()
            ->with('plan')
            ->orderBy('id')
            ->each(function (Team $team) use (
                &$operationalWarnings,
                &$operationalErrors
            ): void {
                if ($this->workspaceClassifier->isFixtureLike($team)) {
                    return;
                }

                $health = $this->monetizationHealthResolver->resolve($team);
                $operationalErrors += (int) data_get(
                    $health,
                    'error_count',
                    0
                );
                $operationalWarnings += collect(
                    data_get($health, 'warnings', [])
                )
                    ->whereIn('code', [
                        'counter_missing',
                        'counter_drift',
                    ])
                    ->count();
            });

        $this->add(
            $checks,
            'monetization',
            'operational_health',
            'Salute contatori monetizzazione',
            $operationalErrors > 0
                ? 'fail'
                : ($operationalWarnings > 0 ? 'warning' : 'pass'),
            'Errori: ' . $operationalErrors
                . '; anomalie contatori: ' . $operationalWarnings . '.'
        );
    }

    /**
     * @param list<array<string, mixed>> $checks
     */
    private function inspectWorkspaceData(
        array &$checks,
        bool $production
    ): void {
        if (! Schema::hasTable('teams')) {
            return;
        }

        $fixtureLike = Team::query()
            ->orderBy('id')
            ->get()
            ->filter(
                fn (Team $team): bool =>
                    $this->workspaceClassifier->isFixtureLike($team)
            )
            ->values();

        $allowed = (bool) config(
            'release_readiness.allow_fixture_workspaces',
            false
        );

        $status = match (true) {
            $fixtureLike->isEmpty() => 'pass',
            $production && ! $allowed => 'fail',
            default => 'warning',
        };

        $this->add(
            $checks,
            'data',
            'fixture_workspaces',
            'Separazione dati di test',
            $status,
            $fixtureLike->isEmpty()
                ? 'Nessun workspace assimilabile a fixture.'
                : 'Workspace assimilabili a fixture: '
                    . $fixtureLike
                        ->map(fn (Team $team): string =>
                            '#' . $team->id . ' ' . $team->name)
                        ->implode('; ')
                    . '.'
        );
    }

    /**
     * @param list<array<string, mixed>> $checks
     */
    private function inspectLegalConfiguration(
        array &$checks,
        bool $production
    ): void {
        $supportEmail = (string) config(
            'release_readiness.legal.support_email'
        );
        $effectiveDate = (string) config(
            'release_readiness.legal.effective_date'
        );
        $placeholderEmail = $supportEmail === ''
            || str_ends_with(strtolower($supportEmail), '@example.com');

        $this->add(
            $checks,
            'legal',
            'support_email',
            'Contatto privacy e supporto',
            $placeholderEmail
                ? ($production ? 'fail' : 'warning')
                : 'pass',
            $placeholderEmail
                ? 'Configurare LEGAL_SUPPORT_EMAIL con un indirizzo reale.'
                : 'Contatto configurato: ' . $supportEmail . '.'
        );

        $validDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $effectiveDate) === 1;
        $this->add(
            $checks,
            'legal',
            'effective_date',
            'Data documenti legali',
            $validDate ? 'pass' : 'fail',
            $validDate
                ? 'Data di efficacia: ' . $effectiveDate . '.'
                : 'LEGAL_EFFECTIVE_DATE deve usare il formato YYYY-MM-DD.'
        );

        $mailer = (string) config('mail.default', 'log');
        $disallowedMailers = config(
            'release_readiness.production.disallowed_mailers',
            ['log', 'array']
        );
        $this->add(
            $checks,
            'operations',
            'mail_transport',
            'Trasporto email',
            $production && in_array($mailer, $disallowedMailers, true)
                ? 'warning'
                : 'pass',
            'Mailer configurato: ' . $mailer . '.'
        );
    }

    /**
     * @param list<array<string, mixed>> $checks
     */
    private function add(
        array &$checks,
        string $group,
        string $key,
        string $label,
        string $status,
        string $message
    ): void {
        $checks[] = [
            'group' => $group,
            'key' => $key,
            'label' => $label,
            'status' => $status,
            'message' => $message,
        ];
    }
}
