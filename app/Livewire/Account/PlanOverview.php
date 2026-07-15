<?php

namespace App\Livewire\Account;

use App\Models\Team;
use App\Models\User;
use App\Services\Monetization\MonetizationValueMetricsResolver;
use App\Services\Monetization\PlanCatalogResolver;
use App\Services\Monetization\PlanEntitlementResolver;
use App\Services\Monetization\UsageSnapshotResolver;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use RuntimeException;

final class PlanOverview extends Component
{
    /** @var array<string, mixed> */
    public array $entitlements = [];

    /** @var array<string, mixed> */
    public array $usageSnapshot = [];

    /** @var array<string, mixed> */
    public array $valueMetrics = [];

    /** @var list<array<string, mixed>> */
    public array $catalog = [];

    public string $workspaceName = '';

    public function mount(
        PlanEntitlementResolver $entitlementResolver,
        UsageSnapshotResolver $usageSnapshotResolver,
        MonetizationValueMetricsResolver $valueMetricsResolver,
        PlanCatalogResolver $catalogResolver
    ): void {
        $user = Auth::user();

        if (! $user instanceof User) {
            throw new RuntimeException(
                'Utente autenticato non disponibile.'
            );
        }

        $team = $user->currentTeam;

        if (! $team instanceof Team) {
            throw new RuntimeException(
                'Workspace attivo non disponibile.'
            );
        }

        $this->workspaceName = $team->name;
        $this->entitlements = $entitlementResolver->resolve($team);
        $this->usageSnapshot = $usageSnapshotResolver->resolve($team);
        $this->valueMetrics = $valueMetricsResolver->resolve($team);
        $this->catalog = $catalogResolver->resolve();
    }

    public function render(): View
    {
        return view('livewire.account.plan-overview')
            ->layout('layouts.app');
    }
}
