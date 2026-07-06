<?php

namespace App\Livewire\ProductCases;

use App\Models\Product;
use App\Models\ProductCase;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\WithPagination;
use RuntimeException;

final class ProductCaseIndex extends Component
{
    use AuthorizesRequests;
    use WithPagination;

    public string $scope = 'open';

    public string $search = '';

    public int $perPage = 10;

    public ?int $productId = null;

    public ?string $productFilterName = null;

    public function mount(): void
    {
        $requestedScope = request()->query('scope');

        if (
            is_string($requestedScope)
            && in_array($requestedScope, $this->allowedScopes(), true)
        ) {
            $this->scope = $requestedScope;
        }

        $user = Auth::user();
        $requestedProductId = request()->query('product');

        if (
            ! $user instanceof User
            || $user->current_team_id === null
            || ! is_numeric($requestedProductId)
        ) {
            return;
        }

        $product = Product::query()
            ->whereKey((int) $requestedProductId)
            ->where('team_id', (int) $user->current_team_id)
            ->first();

        if ($product === null) {
            return;
        }

        $this->productId = (int) $product->id;
        $this->productFilterName = $product->name;
    }

    public function updatedScope(): void
    {
        if (! in_array($this->scope, $this->allowedScopes(), true)) {
            $this->scope = 'open';
        }

        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function statusLabel(string $status): string
    {
        return match ($status) {
            ProductCase::STATUS_DRAFT => 'Bozza',
            ProductCase::STATUS_READY_TO_CONTACT => 'Pronta per il contatto',
            ProductCase::STATUS_CONTACTED => 'Contattata',
            ProductCase::STATUS_RESOLVED => 'Risolta',
            ProductCase::STATUS_CLOSED => 'Chiusa',
            ProductCase::STATUS_CANCELLED => 'Annullata',
            default => 'Stato non disponibile',
        };
    }

    public function statusBadgeClasses(string $status): string
    {
        return match ($status) {
            ProductCase::STATUS_DRAFT =>
                'bg-gray-100 text-gray-700 ring-gray-500/20',

            ProductCase::STATUS_READY_TO_CONTACT =>
                'bg-blue-50 text-blue-700 ring-blue-600/20',

            ProductCase::STATUS_CONTACTED =>
                'bg-indigo-50 text-indigo-700 ring-indigo-600/20',

            ProductCase::STATUS_RESOLVED,
            ProductCase::STATUS_CLOSED =>
                'bg-green-50 text-green-700 ring-green-600/20',

            ProductCase::STATUS_CANCELLED =>
                'bg-red-50 text-red-700 ring-red-600/20',

            default =>
                'bg-gray-100 text-gray-700 ring-gray-500/20',
        };
    }

    public function outcomeLabel(?string $outcome): ?string
    {
        return match ($outcome) {
            ProductCase::OUTCOME_REPAIRED => 'Prodotto riparato',
            ProductCase::OUTCOME_REPLACED => 'Prodotto sostituito',
            ProductCase::OUTCOME_REFUNDED => 'Importo rimborsato',
            ProductCase::OUTCOME_REJECTED => 'Richiesta respinta',
            ProductCase::OUTCOME_ABANDONED => 'Procedura abbandonata',
            ProductCase::OUTCOME_OTHER => 'Altro esito',
            default => null,
        };
    }

    public function actionLabel(string $status): string
    {
        return match ($status) {
            ProductCase::STATUS_DRAFT => 'Completa la pratica',
            ProductCase::STATUS_READY_TO_CONTACT => 'Registra il contatto',
            ProductCase::STATUS_CONTACTED => 'Registra l’esito',
            ProductCase::STATUS_RESOLVED => 'Chiudi la pratica',
            ProductCase::STATUS_CLOSED,
            ProductCase::STATUS_CANCELLED => 'Consulta la pratica',
            default => 'Apri pratica',
        };
    }

    public function render(): View
    {
        $this->authorize('viewAny', ProductCase::class);

        $user = Auth::user();

        if (! $user instanceof User || $user->current_team_id === null) {
            throw new RuntimeException(
                'Workspace attivo non disponibile.'
            );
        }

        $teamId = (int) $user->current_team_id;

        $baseQuery = ProductCase::query()
            ->where('team_id', $teamId);

        $this->applyProductFilter($baseQuery);

        $counts = [
            'open' => (clone $baseQuery)
                ->whereIn('status', $this->openStatuses())
                ->count(),

            'closed' => (clone $baseQuery)
                ->where('status', ProductCase::STATUS_CLOSED)
                ->count(),

            'cancelled' => (clone $baseQuery)
                ->where('status', ProductCase::STATUS_CANCELLED)
                ->count(),

            'all' => (clone $baseQuery)->count(),
        ];

        $productCases = $this->filteredQuery($teamId)
            ->with([
                'product:id,name,model',
                'openedBy:id,name',
            ])
            ->paginate($this->perPage);

        return view(
            'livewire.product-cases.product-case-index',
            [
                'productCases' => $productCases,
                'counts' => $counts,
                'scope' => $this->scope,
                'presenter' => $this,
                'productId' => $this->productId,
                'productFilterName' => $this->productFilterName,
            ]
        )->layout('layouts.app');
    }

    private function filteredQuery(int $teamId): Builder
    {
        $query = ProductCase::query()
            ->where('team_id', $teamId);

        $this->applyProductFilter($query);

        match ($this->scope) {
            'closed' => $query->where(
                'status',
                ProductCase::STATUS_CLOSED
            ),

            'cancelled' => $query->where(
                'status',
                ProductCase::STATUS_CANCELLED
            ),

            'all' => null,

            default => $query->whereIn(
                'status',
                $this->openStatuses()
            ),
        };

        $search = trim($this->search);

        if ($search !== '') {
            $query->where(function (Builder $builder) use ($search): void {
                $builder
                    ->where('title', 'like', '%' . $search . '%')
                    ->orWhere('description', 'like', '%' . $search . '%')
                    ->orWhereHas(
                        'product',
                        fn (Builder $productQuery) => $productQuery
                            ->where('name', 'like', '%' . $search . '%')
                            ->orWhere('model', 'like', '%' . $search . '%')
                    );
            });
        }

        if ($this->scope === 'closed') {
            return $query
                ->orderByDesc('closed_at')
                ->orderByDesc('id');
        }

        if ($this->scope === 'cancelled') {
            return $query
                ->orderByDesc('cancelled_at')
                ->orderByDesc('id');
        }

        return $query
            ->orderByRaw(
                "CASE status
                    WHEN 'resolved' THEN 1
                    WHEN 'contacted' THEN 2
                    WHEN 'ready_to_contact' THEN 3
                    WHEN 'draft' THEN 4
                    WHEN 'closed' THEN 5
                    WHEN 'cancelled' THEN 6
                    ELSE 7
                END"
            )
            ->orderByDesc('updated_at')
            ->orderByDesc('id');
    }

    private function applyProductFilter(Builder $query): void
    {
        if ($this->productId !== null) {
            $query->where('product_id', $this->productId);
        }
    }

    /**
     * @return list<string>
     */
    private function openStatuses(): array
    {
        return [
            ProductCase::STATUS_DRAFT,
            ProductCase::STATUS_READY_TO_CONTACT,
            ProductCase::STATUS_CONTACTED,
            ProductCase::STATUS_RESOLVED,
        ];
    }

    /**
     * @return list<string>
     */
    private function allowedScopes(): array
    {
        return [
            'open',
            'closed',
            'cancelled',
            'all',
        ];
    }
}
