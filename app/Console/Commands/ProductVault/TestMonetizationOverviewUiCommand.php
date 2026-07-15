<?php

namespace App\Console\Commands\ProductVault;

use App\Livewire\Account\PlanOverview;
use App\Models\Plan;
use App\Models\PlanLimit;
use App\Models\Team;
use App\Models\User;
use App\Services\Monetization\MonetizationNoticeResolver;
use App\Services\Monetization\MonetizationValueMetricsResolver;
use App\Services\Monetization\PlanCatalogResolver;
use App\Services\Monetization\PlanEntitlementResolver;
use App\Services\Monetization\UsageSnapshotResolver;
use App\Support\Monetization\MonetizationKeys;
use Database\Seeders\PlanSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Throwable;

final class TestMonetizationOverviewUiCommand extends Command
{
    protected $signature =
        'product-vault:test-monetization-overview-ui';

    protected $description =
        'Verifica pagina piano, catalogo, utilizzo, metriche e navigazione.';

    public function handle(
        PlanEntitlementResolver $entitlementResolver,
        UsageSnapshotResolver $snapshotResolver,
        MonetizationValueMetricsResolver $metricsResolver,
        PlanCatalogResolver $catalogResolver,
        MonetizationNoticeResolver $noticeResolver
    ): int {
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

        DB::beginTransaction();

        try {
            app(PlanSeeder::class)->run();

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

            $team->forceFill(['plan_id' => $freePlan->id])->save();
            $user->forceFill(['current_team_id' => $team->id])->save();
            $user->refresh();
            Auth::login($user);

            $baseline = $snapshotResolver->resolve($team);
            $membersUsed = (int) data_get(
                $baseline,
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

            $component = app(PlanOverview::class);
            $component->mount(
                entitlementResolver: $entitlementResolver,
                usageSnapshotResolver: $snapshotResolver,
                valueMetricsResolver: $metricsResolver,
                catalogResolver: $catalogResolver,
                noticeResolver: $noticeResolver,
            );

            $assertSame(
                'component',
                'workspace exposed',
                $team->name,
                $component->workspaceName
            );
            $assertSame(
                'component',
                'current plan exposed',
                'free',
                data_get($component->entitlements, 'plan.code')
            );
            $assertSame(
                'component',
                'four plans exposed',
                4,
                count($component->catalog)
            );
            $assertSame(
                'component',
                'four one-time offers exposed',
                4,
                count($component->oneTimeOffers)
            );
            $assertSame(
                'component',
                'plan notice resolved',
                true,
                array_key_exists('has_alerts', $component->notice)
            );

            $html = $component
                ->render()
                ->with([
                    'entitlements' => $component->entitlements,
                    'usageSnapshot' => $component->usageSnapshot,
                    'valueMetrics' => $component->valueMetrics,
                    'notice' => $component->notice,
                    'catalog' => $component->catalog,
                    'oneTimeOffers' => $component->oneTimeOffers,
                    'workspaceName' => $component->workspaceName,
                ])
                ->render();

            $assertSame(
                'html',
                'overview marker rendered',
                true,
                str_contains($html, 'data-testid="plan-overview"')
            );
            $assertSame(
                'html',
                'usage section rendered',
                true,
                str_contains($html, 'Utilizzo e limiti')
            );
            $assertSame(
                'html',
                'value metrics rendered',
                true,
                str_contains($html, 'Risultati misurabili')
            );
            $assertSame(
                'html',
                'catalog rendered without checkout',
                true,
                str_contains(
                    $html,
                    'Checkout e pagamenti non sono ancora attivi'
                )
            );
            $assertSame(
                'html',
                'one-time offer catalog rendered',
                true,
                str_contains($html, 'data-testid="one-time-offers"')
                    && str_contains($html, 'Fascicolo assistenza')
                    && str_contains($html, 'Prezzo da definire')
            );
            $assertSame(
                'html',
                'exhausted capacity rendered',
                true,
                str_contains($html, 'data-testid="plan-overview-alerts"')
                    && str_contains($html, 'Esaurito')
            );

            $assertSame(
                'routing',
                'account plan route available',
                true,
                Route::has('account.plan')
            );

            $navigation = File::get(
                resource_path('views/navigation-menu.blade.php')
            );

            $assertSame(
                'navigation',
                'plan overview linked in navigation',
                true,
                str_contains($navigation, "route('account.plan')")
            );
        } catch (Throwable $exception) {
            $rows[] = ['runtime', 'overview UI completed', 'FAIL'];
            $failures[] = [
                'scenario' => 'runtime',
                'assertion' => 'overview UI completed',
                'expected' => 'no exception',
                'actual' => $exception::class
                    . ': '
                    . $exception->getMessage(),
            ];
        } finally {
            Auth::logout();
            DB::rollBack();
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

        $this->info('Monetization overview UI checks passed.');

        return self::SUCCESS;
    }
}
