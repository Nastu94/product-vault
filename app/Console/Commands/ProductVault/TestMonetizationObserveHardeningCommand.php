<?php

namespace App\Console\Commands\ProductVault;

use App\Livewire\Account\PlanUsageNotice;
use App\Models\AuditLog;
use App\Models\Plan;
use App\Models\PlanLimit;
use App\Models\Team;
use App\Models\UsageCounter;
use App\Models\User;
use App\Services\Monetization\MonetizationHealthResolver;
use App\Services\Monetization\MonetizationNoticeResolver;
use App\Services\Monetization\UsageCounterSynchronizer;
use App\Services\Monetization\UsageSnapshotResolver;
use App\Services\Monetization\WorkspacePlanAssignmentService;
use App\Support\Monetization\MonetizationKeys;
use Database\Seeders\PlanSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class TestMonetizationObserveHardeningCommand extends Command
{
    protected $signature =
        'product-vault:test-monetization-observe-hardening';

    protected $description =
        'Verifica notice, diagnostica e assegnazione controllata dei piani.';

    public function handle(
        UsageSnapshotResolver $snapshotResolver,
        MonetizationNoticeResolver $noticeResolver,
        UsageCounterSynchronizer $counterSynchronizer,
        MonetizationHealthResolver $healthResolver,
        WorkspacePlanAssignmentService $assignmentService
    ): int {
        $rows = [];
        $failures = [];
        $originalMode = config('monetization.enforcement_mode');
        $auditBefore = AuditLog::query()->count();
        $counterBefore = UsageCounter::query()->count();

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
            config(['monetization.enforcement_mode' => 'observe']);

            $team = Team::query()->orderBy('id')->first();

            if ($team === null) {
                throw new RuntimeException(
                    'Nessun workspace disponibile per il test.'
                );
            }

            $user = User::query()->find($team->user_id);

            if ($user === null) {
                throw new RuntimeException(
                    'Proprietario del workspace non disponibile.'
                );
            }

            $freePlan = Plan::query()
                ->where('code', 'free')
                ->firstOrFail();
            $premiumPlan = Plan::query()
                ->where('code', 'premium_personal')
                ->firstOrFail();

            $team->forceFill(['plan_id' => $freePlan->id])->save();
            $user->forceFill(['current_team_id' => $team->id])->save();
            $user->refresh();
            Auth::login($user);

            $snapshot = $snapshotResolver->resolve($team);
            $membersUsed = (int) data_get(
                $snapshot,
                'resources.'
                . MonetizationKeys::LIMIT_MAX_TEAM_MEMBERS
                . '.used',
                1
            );

            PlanLimit::query()
                ->where('plan_id', $freePlan->id)
                ->where(
                    'limit_key',
                    MonetizationKeys::LIMIT_MAX_TEAM_MEMBERS
                )
                ->update(['limit_value' => $membersUsed]);

            $exhaustedNotice = $noticeResolver->resolve($team);

            $assertSame(
                'notice',
                'exhausted capacity detected',
                true,
                collect(data_get($exhaustedNotice, 'items', []))
                    ->contains(
                        fn (array $item): bool =>
                            $item['key'] === MonetizationKeys::LIMIT_MAX_TEAM_MEMBERS
                            && $item['status'] === 'exhausted'
                    )
            );
            $assertSame(
                'notice',
                'observe mode message exposed',
                'observe',
                data_get($exhaustedNotice, 'enforcement_mode')
            );

            $noticeComponent = app(PlanUsageNotice::class);
            $noticeComponent->mount(
                noticeResolver: $noticeResolver,
                expanded: true,
            );

            $noticeHtml = $noticeComponent
                ->render()
                ->with([
                    'notice' => $noticeComponent->notice,
                    'expanded' => true,
                ])
                ->render();

            $assertSame(
                'notice ui',
                'global notice rendered',
                true,
                str_contains($noticeHtml, 'plan-usage-notice')
                    && str_contains($noticeHtml, 'Solo monitoraggio')
            );

            PlanLimit::query()
                ->where('plan_id', $freePlan->id)
                ->where(
                    'limit_key',
                    MonetizationKeys::LIMIT_MAX_TEAM_MEMBERS
                )
                ->update([
                    'limit_value' => max(0, $membersUsed - 1),
                ]);

            $exceededNotice = $noticeResolver->resolve($team);

            $assertSame(
                'notice',
                'exceeded capacity detected',
                'danger',
                data_get($exceededNotice, 'highest_severity')
            );

            $premiumPreview = $assignmentService->preview(
                $team,
                $premiumPlan
            );

            $assertSame(
                'assignment',
                'premium assignment compatible',
                true,
                $premiumPreview['can_assign_without_force']
            );

            $planChangeAuditBefore = AuditLog::query()
                ->where('team_id', $team->id)
                ->where('action', 'workspace.plan_changed')
                ->count();

            $assignmentService->assign(
                team: $team,
                targetPlan: $premiumPlan,
                actorUserId: $user->id,
            );
            $team->refresh();

            $assertSame(
                'assignment',
                'premium plan applied',
                'premium_personal',
                $team->plan?->code
            );
            $assertSame(
                'assignment',
                'plan change audited',
                $planChangeAuditBefore + 1,
                AuditLog::query()
                    ->where('team_id', $team->id)
                    ->where('action', 'workspace.plan_changed')
                    ->count()
            );

            $blocked = false;

            try {
                $assignmentService->assign(
                    team: $team,
                    targetPlan: $freePlan,
                    actorUserId: $user->id,
                );
            } catch (InvalidArgumentException) {
                $blocked = true;
            }

            $assertSame(
                'assignment',
                'incompatible downgrade requires force',
                true,
                $blocked
            );

            $assignmentService->assign(
                team: $team,
                targetPlan: $freePlan,
                actorUserId: $user->id,
                force: true,
            );
            $team->refresh();

            $assertSame(
                'assignment',
                'forced assignment applied',
                'free',
                $team->plan?->code
            );

            $counterSynchronizer->synchronize($team);
            $healthy = $healthResolver->resolve($team);

            $assertSame(
                'diagnostics',
                'no counter drift after sync',
                false,
                collect($healthy['warnings'])->contains(
                    fn (array $warning): bool =>
                        $warning['code'] === 'counter_drift'
                )
            );

            $counter = UsageCounter::query()
                ->where('team_id', $team->id)
                ->where('counter_key', 'documents_current')
                ->firstOrFail();
            $counter->increment('used_value', 5);

            $drifted = $healthResolver->resolve($team);

            $assertSame(
                'diagnostics',
                'counter drift detected',
                true,
                collect($drifted['warnings'])->contains(
                    fn (array $warning): bool =>
                        $warning['code'] === 'counter_drift'
                )
            );
        } catch (Throwable $exception) {
            $rows[] = ['runtime', 'observe hardening completed', 'FAIL'];
            $failures[] = [
                'scenario' => 'runtime',
                'assertion' => 'observe hardening completed',
                'expected' => 'no exception',
                'actual' => $exception::class
                    . ': '
                    . $exception->getMessage(),
            ];
        } finally {
            Auth::logout();
            config([
                'monetization.enforcement_mode' => $originalMode,
            ]);
            DB::rollBack();
        }

        $assertSame(
            'rollback',
            'audit count restored',
            $auditBefore,
            AuditLog::query()->count()
        );
        $assertSame(
            'rollback',
            'counter count restored',
            $counterBefore,
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

        $this->info(
            'Monetization observe hardening checks passed.'
        );

        return self::SUCCESS;
    }
}
