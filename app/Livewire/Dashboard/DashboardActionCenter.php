<?php

namespace App\Livewire\Dashboard;

use App\Models\ProductCase;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Laravel\Jetstream\Jetstream;
use Livewire\Component;
use RuntimeException;

final class DashboardActionCenter extends Component
{
    /**
     * @var list<array<string, mixed>>
     */
    public array $openProductCases = [];

    public int $openProductCasesCount = 0;

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

        $openStatuses = [
            ProductCase::STATUS_DRAFT,
            ProductCase::STATUS_READY_TO_CONTACT,
            ProductCase::STATUS_CONTACTED,
            ProductCase::STATUS_RESOLVED,
        ];

        $query = ProductCase::query()
            ->with('product:id,name')
            ->where('team_id', $activeTeamId)
            ->whereIn('status', $openStatuses);

        $this->openProductCasesCount =
            (clone $query)->count();

        $this->openProductCases = $query
            ->orderByRaw(
                "CASE status
                    WHEN 'resolved' THEN 1
                    WHEN 'contacted' THEN 2
                    WHEN 'ready_to_contact' THEN 3
                    WHEN 'draft' THEN 4
                    ELSE 5
                END"
            )
            ->orderByDesc('updated_at')
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
                    'status' => $productCase->status,
                    'status_label' =>
                        $this->statusLabel($productCase->status),
                    'status_badge_classes' =>
                        $this->statusBadgeClasses($productCase->status),
                    'action_label' =>
                        $this->actionLabel($productCase->status),
                    'updated_at_label' =>
                        $productCase->updated_at?->diffForHumans()
                        ?? 'Data non disponibile',
                ]
            )
            ->values()
            ->all();
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            ProductCase::STATUS_DRAFT =>
                'Bozza',

            ProductCase::STATUS_READY_TO_CONTACT =>
                'Pronta per il contatto',

            ProductCase::STATUS_CONTACTED =>
                'Contattata',

            ProductCase::STATUS_RESOLVED =>
                'Risolta',

            default =>
                'Stato non disponibile',
        };
    }

    private function statusBadgeClasses(string $status): string
    {
        return match ($status) {
            ProductCase::STATUS_DRAFT =>
                'bg-gray-100 text-gray-700 ring-gray-500/20',

            ProductCase::STATUS_READY_TO_CONTACT =>
                'bg-blue-50 text-blue-700 ring-blue-600/20',

            ProductCase::STATUS_CONTACTED =>
                'bg-indigo-50 text-indigo-700 ring-indigo-600/20',

            ProductCase::STATUS_RESOLVED =>
                'bg-green-50 text-green-700 ring-green-600/20',

            default =>
                'bg-gray-100 text-gray-700 ring-gray-500/20',
        };
    }

    private function actionLabel(string $status): string
    {
        return match ($status) {
            ProductCase::STATUS_DRAFT =>
                'Completa la pratica',

            ProductCase::STATUS_READY_TO_CONTACT =>
                'Registra il contatto',

            ProductCase::STATUS_CONTACTED =>
                'Registra l’esito',

            ProductCase::STATUS_RESOLVED =>
                'Chiudi la pratica',

            default =>
                'Apri pratica',
        };
    }

    public function render(): View
    {
        return view(
            'livewire.dashboard.dashboard-action-center'
        );
    }
}
