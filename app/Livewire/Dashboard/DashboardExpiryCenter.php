<?php

namespace App\Livewire\Dashboard;

use App\Models\User;
use App\Models\Warranty;
use App\Services\Warranties\WarrantyCoverageContextResolver;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

final class DashboardExpiryCenter extends Component
{
    /**
     * @var list<array<string, mixed>>
     */
    public array $expiringItems = [];

    public int $expiringCount = 0;

    public int $urgentCount = 0;

    public int $upcomingCount = 0;

    public function mount(
        WarrantyCoverageContextResolver $coverageResolver
    ): void {
        $user = Auth::user();

        if (! $user instanceof User || $user->current_team_id === null) {
            return;
        }

        $teamId = (int) $user->current_team_id;
        $today = now()->startOfDay();
        $soon = $today->copy()->addDays(30);

        $expiringCoverages = Warranty::query()
            ->with([
                'product:id,team_id,name',
                'warrantyType:id,code,name',
            ])
            ->whereHas(
                'product',
                fn (Builder $query): Builder => $query
                    ->where('team_id', $teamId)
            )
            ->whereNotNull('starts_at')
            ->whereNotNull('ends_at')
            ->whereDate('starts_at', '<=', $today->toDateString())
            ->whereDate('ends_at', '>=', $today->toDateString())
            ->whereDate('ends_at', '<=', $soon->toDateString())
            ->orderBy('ends_at')
            ->orderByDesc('id')
            ->get()
            ->map(function (Warranty $warranty) use (
                $coverageResolver,
                $today
            ): array {
                $context = $coverageResolver->resolve(
                    warranty: $warranty,
                    referenceDate: $today,
                );

                $remainingDays = $warranty->ends_at
                    ? (int) $today->diffInDays(
                        $warranty->ends_at,
                        false
                    )
                    : null;

                return [
                    'warranty' => $warranty,
                    'context' => $context,
                    'remaining_days' => $remainingDays,
                ];
            })
            ->filter(
                fn (array $item): bool => data_get(
                    $item,
                    'context.coverage_state.code'
                ) !== 'cancelled'
            )
            ->values();

        $this->expiringCount = $expiringCoverages->count();

        $this->urgentCount = $expiringCoverages
            ->filter(
                fn (array $item): bool =>
                    is_int($item['remaining_days'])
                    && $item['remaining_days'] <= 7
            )
            ->count();

        $this->upcomingCount = $this->expiringCount
            - $this->urgentCount;

        $this->expiringItems = $expiringCoverages
            ->take(6)
            ->map(function (array $item): array {
                /** @var Warranty $warranty */
                $warranty = $item['warranty'];
                $remainingDays = $item['remaining_days'];
                $isUrgent = is_int($remainingDays)
                    && $remainingDays <= 7;

                return [
                    'id' => (int) $warranty->id,
                    'product_name' => $warranty->product?->name
                        ?? 'Prodotto non disponibile',
                    'coverage_label' => (string) data_get(
                        $item,
                        'context.coverage_state.label',
                        'Copertura da verificare'
                    ),
                    'coverage_type_label' => (string) data_get(
                        $item,
                        'context.coverage_type.label',
                        'Tipo non disponibile'
                    ),
                    'ends_at_label' =>
                        $warranty->ends_at?->format('d/m/Y') ?? '—',
                    'remaining_label' =>
                        $this->remainingLabel($remainingDays),
                    'badge_classes' => $isUrgent
                        ? 'bg-red-50 text-red-700 ring-red-600/20'
                        : 'bg-yellow-50 text-yellow-800 ring-yellow-600/20',
                    'url' => $warranty->product
                        ? route('products.show', $warranty->product)
                        : route(
                            'warranties.index',
                            ['status' => 'expiring']
                        ),
                ];
            })
            ->all();
    }

    private function remainingLabel(?int $remainingDays): string
    {
        return match ($remainingDays) {
            null => 'Scadenza non disponibile',
            0 => 'Scade oggi',
            1 => 'Scade domani',
            default => 'Scade tra ' . $remainingDays . ' giorni',
        };
    }

    public function render(): View
    {
        return view(
            'livewire.dashboard.dashboard-expiry-center'
        );
    }
}
