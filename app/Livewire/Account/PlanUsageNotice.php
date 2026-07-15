<?php

namespace App\Livewire\Account;

use App\Models\Team;
use App\Models\User;
use App\Services\Monetization\MonetizationNoticeResolver;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

final class PlanUsageNotice extends Component
{
    /** @var array<string, mixed> */
    public array $notice = [];

    public bool $expanded = false;

    public function mount(
        MonetizationNoticeResolver $noticeResolver,
        bool $expanded = false
    ): void {
        $this->expanded = $expanded;

        $user = Auth::user();

        if (! $user instanceof User) {
            return;
        }

        $team = $user->currentTeam;

        if (! $team instanceof Team) {
            return;
        }

        $this->notice = $noticeResolver->resolve($team);
    }

    public function render(): View
    {
        return view('livewire.account.plan-usage-notice');
    }
}
