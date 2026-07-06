<?php

namespace App\Livewire\Dashboard;

use App\Models\ProductCase;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Laravel\Jetstream\Jetstream;
use Livewire\Component;
use RuntimeException;

final class DashboardResultsCenter extends Component
{
    /**
     * @var list<array<string, mixed>>
     */
    public array $recentResults = [];

    public int $concludedCount = 0;

    public int $repairedCount = 0;

    public int $replacedCount = 0;

    public int $refundedCount = 0;

    public function mount(): void
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            throw new RuntimeException(
                'Utente autenticato non disponibile.'
            );
        }

        $activeTeamId = Jetstream::hasTeamFeatures()
            ? $user->currentTeam?->id
            : null;

        if ($activeTeamId === null) {
            return;
        }

        $query = ProductCase::query()
            ->with('product:id,name')
            ->where('team_id', $activeTeamId)
            ->where('status', ProductCase::STATUS_CLOSED);

        $this->concludedCount =
            (clone $query)->count();

        $this->repairedCount =
            (clone $query)
                ->where('outcome', ProductCase::OUTCOME_REPAIRED)
                ->count();

        $this->replacedCount =
            (clone $query)
                ->where('outcome', ProductCase::OUTCOME_REPLACED)
                ->count();

        $this->refundedCount =
            (clone $query)
                ->where('outcome', ProductCase::OUTCOME_REFUNDED)
                ->count();

        $this->recentResults = $query
            ->orderByDesc('closed_at')
            ->orderByDesc('id')
            ->limit(4)
            ->get()
            ->map(
                fn (ProductCase $productCase): array => [
                    'id' => (int) $productCase->id,
                    'title' => $productCase->title,
                    'product_name' =>
                        $productCase->product?->name
                        ?? 'Prodotto non disponibile',
                    'outcome' => $productCase->outcome,
                    'outcome_label' =>
                        $this->outcomeLabel($productCase->outcome),
                    'outcome_badge_classes' =>
                        $this->outcomeBadgeClasses($productCase->outcome),
                    'closed_at_label' =>
                        $productCase->closed_at?->format('d/m/Y H:i')
                        ?? 'Data non disponibile',
                ]
            )
            ->values()
            ->all();
    }

    private function outcomeLabel(?string $outcome): string
    {
        return match ($outcome) {
            ProductCase::OUTCOME_REPAIRED =>
                'Prodotto riparato',

            ProductCase::OUTCOME_REPLACED =>
                'Prodotto sostituito',

            ProductCase::OUTCOME_REFUNDED =>
                'Importo rimborsato',

            ProductCase::OUTCOME_REJECTED =>
                'Richiesta respinta',

            ProductCase::OUTCOME_ABANDONED =>
                'Procedura abbandonata',

            ProductCase::OUTCOME_OTHER =>
                'Altro esito',

            default =>
                'Esito non disponibile',
        };
    }

    private function outcomeBadgeClasses(?string $outcome): string
    {
        return match ($outcome) {
            ProductCase::OUTCOME_REPAIRED,
            ProductCase::OUTCOME_REPLACED,
            ProductCase::OUTCOME_REFUNDED =>
                'bg-green-50 text-green-700 ring-green-600/20',

            ProductCase::OUTCOME_REJECTED,
            ProductCase::OUTCOME_ABANDONED =>
                'bg-red-50 text-red-700 ring-red-600/20',

            default =>
                'bg-gray-100 text-gray-700 ring-gray-500/20',
        };
    }

    public function render(): View
    {
        return view(
            'livewire.dashboard.dashboard-results-center'
        );
    }
}
