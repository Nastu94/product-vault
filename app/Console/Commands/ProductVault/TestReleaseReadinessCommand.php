<?php

namespace App\Console\Commands\ProductVault;

use App\Models\Team;
use App\Models\User;
use App\Services\Release\MigrationReadinessProbe;
use App\Services\Release\ReleaseReadinessInspector;
use App\Services\Release\WorkspaceEnvironmentClassifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class TestReleaseReadinessCommand extends Command
{
    protected $signature =
        'product-vault:test-release-readiness';

    protected $description =
        'Verifica inspector, profilo produzione e classificazione dati di test con rollback.';

    public function handle(
        ReleaseReadinessInspector $inspector,
        WorkspaceEnvironmentClassifier $classifier,
        MigrationReadinessProbe $migrationProbe
    ): int {
        $rows = [];
        $failures = [];
        $teamsBefore = Team::query()->count();
        $originalConfig = [
            'app.env' => config('app.env'),
            'app.debug' => config('app.debug'),
            'release_readiness.allow_fixture_workspaces' => config(
                'release_readiness.allow_fixture_workspaces'
            ),
            'release_readiness.legal.support_email' => config(
                'release_readiness.legal.support_email'
            ),
        ];

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

        DB::beginTransaction();

        try {
            $user = User::query()->orderBy('id')->first();

            if ($user === null) {
                throw new RuntimeException(
                    'Nessun utente disponibile per il test.'
                );
            }

            $fixtureName = 'Release Fixture Test ' . Str::uuid();
            $teamId = DB::table('teams')->insertGetId([
                'user_id' => $user->id,
                'name' => $fixtureName,
                'personal_team' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $team = Team::query()->findOrFail($teamId);
            $classification = $classifier->classify($team);

            $assertSame(
                'data',
                'fixture workspace detected',
                true,
                $classification['is_fixture_like']
            );
            $assertSame(
                'data',
                'fixture scope exposed',
                'fixture_like',
                $classification['scope']
            );

            $localReport = $inspector->inspect(false);
            $groups = collect($localReport['checks'])
                ->pluck('group')
                ->unique()
                ->values()
                ->all();

            foreach ([
                'environment',
                'database',
                'storage',
                'queue',
                'tools',
                'routes',
                'monetization',
                'data',
                'legal',
            ] as $requiredGroup) {
                $assertSame(
                    'report',
                    $requiredGroup . ' group exposed',
                    true,
                    in_array($requiredGroup, $groups, true)
                );
            }

            $assertSame(
                'report',
                'counts match checks',
                count($localReport['checks']),
                array_sum($localReport['counts'])
            );

            $gettingStartedCheck = collect($localReport['checks'])
                ->firstWhere('key', 'auth_account_getting-started');
            $assertSame(
                'routes',
                'getting started route protected',
                'pass',
                data_get($gettingStartedCheck, 'status')
            );

            $migrationCheck = $migrationProbe->inspect();
            $assertSame(
                'database',
                'migration probe exposed',
                'pending_migrations',
                $migrationCheck['key']
            );
            $assertSame(
                'database',
                'all migrations applied',
                'pass',
                $migrationCheck['status']
            );

            config([
                'app.env' => 'local',
                'app.debug' => true,
                'release_readiness.allow_fixture_workspaces' => false,
                'release_readiness.legal.support_email' =>
                    'support@example.com',
            ]);

            $productionReport = $inspector->inspect(true);
            $productionChecks = collect($productionReport['checks']);

            $assertSame(
                'production',
                'non production environment fails',
                'fail',
                data_get(
                    $productionChecks->firstWhere(
                        'key',
                        'app_environment'
                    ),
                    'status'
                )
            );
            $assertSame(
                'production',
                'debug mode fails',
                'fail',
                data_get(
                    $productionChecks->firstWhere('key', 'app_debug'),
                    'status'
                )
            );
            $assertSame(
                'production',
                'fixture data fails',
                'fail',
                data_get(
                    $productionChecks->firstWhere(
                        'key',
                        'fixture_workspaces'
                    ),
                    'status'
                )
            );
            $assertSame(
                'production',
                'placeholder legal email fails',
                'fail',
                data_get(
                    $productionChecks->firstWhere(
                        'key',
                        'support_email'
                    ),
                    'status'
                )
            );
        } catch (Throwable $exception) {
            $rows[] = ['runtime', 'release readiness completed', 'FAIL'];
            $failures[] = [
                'scenario' => 'runtime',
                'assertion' => 'release readiness completed',
                'expected' => 'no exception',
                'actual' => $exception::class
                    . ': '
                    . $exception->getMessage(),
            ];
        } finally {
            config($originalConfig);
            DB::rollBack();
        }

        $assertSame(
            'rollback',
            'team count restored',
            $teamsBefore,
            Team::query()->count()
        );

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

        $this->info('Release readiness checks passed.');

        return self::SUCCESS;
    }
}
