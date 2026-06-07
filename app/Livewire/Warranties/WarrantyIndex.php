<?php

namespace App\Livewire\Warranties;

use App\Models\Warranty;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\WithPagination;

class WarrantyIndex extends Component
{
    use WithPagination;

    /**
     * Numero di garanzie mostrate per pagina.
     */
    public int $perPage = 10;

    /**
     * Filtro stato garanzia.
     */
    public string $status = 'all';

    /**
     * Filtro fonte garanzia.
     */
    public string $source = 'all';

    /**
     * Mantiene i filtri nella query string.
     */
    protected array $queryString = [
        'status' => ['except' => 'all'],
        'source' => ['except' => 'all'],
    ];

    /**
     * Reset paginazione quando cambia filtro stato.
     */
    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    /**
     * Reset paginazione quando cambia filtro fonte.
     */
    public function updatedSource(): void
    {
        $this->resetPage();
    }

    /**
     * Query base: solo garanzie dei prodotti del workspace corrente.
     */
    private function baseWarrantyQuery(): Builder
    {
        $user = Auth::user();
        $teamId = $user->current_team_id ?? $user->currentTeam?->id;

        return Warranty::query()
            ->whereHas('product', fn (Builder $query) => $query->where('team_id', $teamId));
    }

    /**
     * Applica filtro stato garanzia.
     */
    private function applyStatusFilter(Builder $query): Builder
    {
        $today = now()->toDateString();
        $soon = now()->addDays(30)->toDateString();

        return match ($this->status) {
            'active' => $query
                ->whereDate('starts_at', '<=', $today)
                ->whereDate('ends_at', '>=', $today),

            'expiring' => $query
                ->whereDate('ends_at', '>=', $today)
                ->whereDate('ends_at', '<=', $soon),

            'expired' => $query
                ->whereDate('ends_at', '<', $today),

            'unknown' => $query
                ->where(function (Builder $query): void {
                    $query->whereNull('starts_at')
                        ->orWhereNull('ends_at');
                }),

            default => $query,
        };
    }

    /**
     * Applica filtro fonte garanzia.
     */
    private function applySourceFilter(Builder $query): Builder
    {
        if ($this->source === 'all') {
            return $query;
        }

        return $query->where('source', $this->source);
    }

    /**
     * Etichetta stato garanzia.
     */
    public function warrantyStatusLabel(Warranty $warranty): string
    {
        if (! $warranty->starts_at || ! $warranty->ends_at) {
            return 'Non calcolabile';
        }

        if (now()->startOfDay()->lt($warranty->starts_at)) {
            return 'Non iniziata';
        }

        if (now()->startOfDay()->gt($warranty->ends_at)) {
            return 'Scaduta';
        }

        $remainingDays = now()->startOfDay()->diffInDays($warranty->ends_at, false);

        if ($remainingDays <= 30) {
            return 'In scadenza';
        }

        return 'Attiva';
    }

    /**
     * Classi CSS badge stato garanzia.
     */
    public function warrantyStatusBadgeClasses(Warranty $warranty): string
    {
        return match ($this->warrantyStatusLabel($warranty)) {
            'Attiva' => 'bg-green-50 text-green-700 ring-green-600/20',
            'In scadenza' => 'bg-yellow-50 text-yellow-800 ring-yellow-600/20',
            'Scaduta' => 'bg-red-50 text-red-700 ring-red-600/20',
            'Non iniziata' => 'bg-blue-50 text-blue-700 ring-blue-600/20',
            default => 'bg-gray-100 text-gray-700 ring-gray-500/20',
        };
    }

    /**
     * Etichetta fonte garanzia.
     */
    public function warrantySourceLabel(Warranty $warranty): string
    {
        return match ($warranty->source) {
            'calculated' => 'Calcolata',
            'manual' => 'Manuale',
            default => $warranty->source,
        };
    }

    /**
     * Giorni residui alla scadenza.
     */
    public function warrantyRemainingDays(Warranty $warranty): ?int
    {
        if (! $warranty->ends_at) {
            return null;
        }

        return now()->startOfDay()->diffInDays($warranty->ends_at, false);
    }

    /**
     * Renderizza la lista garanzie.
     */
    public function render(): View
    {
        abort_unless(Auth::user()?->can('warranties.view'), 403);

        $today = now()->toDateString();
        $soon = now()->addDays(30)->toDateString();

        $baseQuery = $this->baseWarrantyQuery();

        $summary = [
            'total' => (clone $baseQuery)->count(),
            'active' => (clone $baseQuery)
                ->whereDate('starts_at', '<=', $today)
                ->whereDate('ends_at', '>=', $today)
                ->count(),
            'expiring' => (clone $baseQuery)
                ->whereDate('ends_at', '>=', $today)
                ->whereDate('ends_at', '<=', $soon)
                ->count(),
            'expired' => (clone $baseQuery)
                ->whereDate('ends_at', '<', $today)
                ->count(),
            'manual' => (clone $baseQuery)
                ->where('source', 'manual')
                ->count(),
            'calculated' => (clone $baseQuery)
                ->where('source', 'calculated')
                ->count(),
        ];

        $warrantiesQuery = $this->baseWarrantyQuery()
            ->with([
                'product.currency',
                'product.merchant',
                'warrantyType',
                'sourceDocument.documentType',
            ]);

        $this->applyStatusFilter($warrantiesQuery);
        $this->applySourceFilter($warrantiesQuery);

        $warranties = $warrantiesQuery
            ->orderByRaw('CASE WHEN ends_at IS NULL THEN 1 ELSE 0 END')
            ->orderBy('ends_at')
            ->latest('id')
            ->paginate($this->perPage);

        return view('livewire.warranties.warranty-index', [
            'warranties' => $warranties,
            'summary' => $summary,
        ])->layout('layouts.app');
    }
}