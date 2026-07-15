<?php

namespace App\Console\Commands\ProductVault;

use App\Exceptions\Monetization\PlanLimitExceededException;
use App\Models\Plan;
use App\Models\PlanLimit;
use App\Models\Team;
use App\Models\UsageCounter;
use App\Models\UsageEvent;
use App\Services\Monetization\PlanLimitDecisionService;
use App\Services\Monetization\UsageCounterSynchronizer;
use App\Services\Monetization\UsageMeter;
use App\Services\Monetization\UsageSnapshotResolver;
use App\Support\Monetization\MonetizationKeys;
use Database\Seeders\PlanSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class TestMonetizationUsageGuardCommand extends Command
{
    protected $signature =
        'product-vault:test-monetization-usage-guard';

    protected $description =
        'Verifica metering idempotente, snapshot, sync e modalità observe/enforce.';

    public function handle(
        UsageMeter $usageMeter,
        UsageSnapshotResolver $snapshotResolver,
        PlanLimitDecisionService $decisionService,
        UsageCounterSynchronizer $synchronizer
    ): int {
        $rows = [];
        $failures = [];
        $originalMode = config('monetization.enforcement_mode');
        $eventsBefore = UsageEvent::query()->count();
        $countersBefore = UsageCounter::query()->count();

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
            app(PlanSeeder::class)->run();

            $team = Team::query()->orderBy('id')->first();

            if ($team === null) {
                throw new RuntimeException(
                    'Nessun workspace disponibile per il test.'
                );
            }

            $freePlan = Plan::query()
                ->where('code', 'free')
                ->firstOrFail();

            $team->forceFill(['plan_id' => $freePlan->id])->save();
            $team->refresh();

            $baseline = $snapshotResolver->resolve($team);
            $documentsUsed = (int) data_get(
                $baseline,
                'resources.'
                . MonetizationKeys::LIMIT_MAX_DOCUMENTS
                . '.used',
                0
            );

            PlanLimit::query()
                ->where('plan_id', $freePlan->id)
                ->where(
                    'limit_key',
                    MonetizationKeys::LIMIT_MAX_DOCUMENTS
                )
                ->update(['limit_value' => $documentsUsed]);

            config(['monetization.enforcement_mode' => 'observe']);

            $observed = $decisionService->decide(
                $team,
                MonetizationKeys::LIMIT_MAX_DOCUMENTS,
                1
            );

            $assertSame(
                'observe',
                'limit would be exceeded',
                true,
                $observed['would_block'] ?? false
            );
            $assertSame(
                'observe',
                'observe mode keeps operation allowed',
                true,
                $observed['allowed'] ?? false
            );

            config(['monetization.enforcement_mode' => 'enforce']);

            $blocked = false;

            try {
                $decisionService->ensureCanConsume(
                    $team,
                    MonetizationKeys::LIMIT_MAX_DOCUMENTS,
                    1
                );
            } catch (PlanLimitExceededException) {
                $blocked = true;
            }

            $assertSame(
                'enforce',
                'enforce mode blocks exceeded operation',
                true,
                $blocked
            );

            config(['monetization.enforcement_mode' => 'observe']);

            $idempotencyKey = 'test:' . Str::uuid();
            $ocrBefore = (int) data_get(
                $baseline,
                'raw.ocr_runs_this_month',
                0
            );

            $usageMeter->record(
                team: $team,
                eventKey: MonetizationKeys::EVENT_OCR_RUN,
                quantity: 1,
                idempotencyKey: $idempotencyKey,
                metadata: ['source' => 'test'],
            );

            $usageMeter->record(
                team: $team,
                eventKey: MonetizationKeys::EVENT_OCR_RUN,
                quantity: 1,
                idempotencyKey: $idempotencyKey,
                metadata: ['source' => 'duplicate_test'],
            );

            $eventCount = UsageEvent::query()
                ->where('team_id', $team->id)
                ->where('event_key', MonetizationKeys::EVENT_OCR_RUN)
                ->where('idempotency_key', $idempotencyKey)
                ->count();

            $assertSame(
                'metering',
                'idempotent event stored once',
                1,
                $eventCount
            );

            $snapshot = $snapshotResolver->resolve($team);

            $assertSame(
                'snapshot',
                'monthly OCR usage incremented once',
                $ocrBefore + 1,
                (int) data_get(
                    $snapshot,
                    'raw.ocr_runs_this_month',
                    0
                )
            );

            $synchronized = $synchronizer->synchronize($team);

            $assertSame(
                'sync',
                'document counter matches authoritative usage',
                $documentsUsed,
                $synchronized['documents_current'] ?? null
            );
            $assertSame(
                'sync',
                'OCR counter matches current period',
                $ocrBefore + 1,
                $synchronized['ocr_runs'] ?? null
            );
        } catch (Throwable $exception) {
            $rows[] = ['runtime', 'usage guard completed', 'FAIL'];
            $failures[] = [
                'scenario' => 'runtime',
                'assertion' => 'usage guard completed',
                'expected' => 'no exception',
                'actual' => $exception::class
                    . ': '
                    . $exception->getMessage(),
            ];
        } finally {
            config([
                'monetization.enforcement_mode' => $originalMode,
            ]);
            DB::rollBack();
        }

        $assertSame(
            'rollback',
            'usage event count restored',
            $eventsBefore,
            UsageEvent::query()->count()
        );
        $assertSame(
            'rollback',
            'usage counter count restored',
            $countersBefore,
            UsageCounter::query()->count()
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

        $this->info('Monetization usage guard checks passed.');

        return self::SUCCESS;
    }
}
