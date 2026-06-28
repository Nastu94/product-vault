<?php

namespace App\Livewire\Warranties;

use App\Models\Warranty;
use App\Services\Warranties\WarrantyCoverageContextResolver;
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
        $today = now()->startOfDay()->toDateString();
        $soon = now()
            ->startOfDay()
            ->addDays(30)
            ->toDateString();

        return match ($this->status) {
            /*
            * Il periodo attivo esclude le coperture già classificate
            * come in scadenza.
            */
            'active' => $query
                ->whereDate('starts_at', '<=', $today)
                ->whereDate('ends_at', '>', $soon),

            'expiring' => $query
                ->whereDate('starts_at', '<=', $today)
                ->whereDate('ends_at', '>=', $today)
                ->whereDate('ends_at', '<=', $soon),

            'not_started' => $query
                ->whereNotNull('ends_at')
                ->whereDate('starts_at', '>', $today),

            'expired' => $query
                ->whereNotNull('starts_at')
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
     * Restituisce il contesto normalizzato della copertura.
     *
     * @return array<string, mixed>
     */
    public function warrantyCoverageContext(
        Warranty $warranty
    ): array {
        return app(
            WarrantyCoverageContextResolver::class
        )->resolve($warranty);
    }

    /**
     * Etichetta dello stato temporale.
     */
    public function warrantyStatusLabel(
        Warranty $warranty
    ): string {
        return (string) data_get(
            $this->warrantyCoverageContext($warranty),
            'temporal_status.label',
            'Periodo non determinabile'
        );
    }

    /**
     * Classi del badge dello stato temporale.
     */
    public function warrantyStatusBadgeClasses(
        Warranty $warranty
    ): string {
        return match (
            data_get(
                $this->warrantyCoverageContext($warranty),
                'temporal_status.code'
            )
        ) {
            'active' =>
                'bg-green-50 text-green-700 ring-green-600/20',

            'expiring' =>
                'bg-yellow-50 text-yellow-800 ring-yellow-600/20',

            'not_started' =>
                'bg-blue-50 text-blue-700 ring-blue-600/20',

            'expired' =>
                'bg-red-50 text-red-700 ring-red-600/20',

            default =>
                'bg-gray-100 text-gray-700 ring-gray-500/20',
        };
    }

    /**
     * Etichetta dello stato della copertura.
     */
    public function warrantyCoverageStateLabel(
        Warranty $warranty
    ): string {
        return (string) data_get(
            $this->warrantyCoverageContext($warranty),
            'coverage_state.label',
            'Copertura non determinata'
        );
    }

    /**
     * Classi del badge dello stato della copertura.
     *
     * Questi colori rappresentano la provenienza e il grado di
     * conferma della copertura, non il suo stato temporale.
     */
    public function warrantyCoverageStateBadgeClasses(
        Warranty $warranty
    ): string {
        return match (
            data_get(
                $this->warrantyCoverageContext($warranty),
                'coverage_state.code'
            )
        ) {
            'estimated' =>
                'bg-yellow-50 text-yellow-800 ring-yellow-600/20',

            'declared' =>
                'bg-blue-50 text-blue-700 ring-blue-600/20',

            'user_confirmed' =>
                'bg-indigo-50 text-indigo-700 ring-indigo-600/20',

            'verified' =>
                'bg-green-50 text-green-700 ring-green-600/20',

            'cancelled' =>
                'bg-red-50 text-red-700 ring-red-600/20',

            default =>
                'bg-gray-100 text-gray-700 ring-gray-500/20',
        };
    }

    /**
     * Indica se la copertura è ancora una stima.
     */
    public function warrantyCoverageIsEstimate(
        Warranty $warranty
    ): bool {
        return (bool) data_get(
            $this->warrantyCoverageContext($warranty),
            'coverage_state.is_estimate',
            false
        );
    }

    /**
     * Numero di informazioni contestuali ancora mancanti.
     */
    public function warrantyMissingInformationCount(
        Warranty $warranty
    ): int {
        $missingInformation = data_get(
            $this->warrantyCoverageContext($warranty),
            'missing_information',
            []
        );

        return is_array($missingInformation)
            ? count($missingInformation)
            : 0;
    }

    /**
     * Etichetta normalizzata della provenienza.
     */
    public function warrantySourceLabel(
        Warranty $warranty
    ): string {
        return (string) data_get(
            $this->warrantyCoverageContext($warranty),
            'source.label',
            $warranty->source
        );
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
                ->whereDate('ends_at', '>', $soon)
                ->count(),

            'expiring' => (clone $baseQuery)
                ->whereDate('starts_at', '<=', $today)
                ->whereDate('ends_at', '>=', $today)
                ->whereDate('ends_at', '<=', $soon)
                ->count(),

            'not_started' => (clone $baseQuery)
                ->whereNotNull('ends_at')
                ->whereDate('starts_at', '>', $today)
                ->count(),

            'expired' => (clone $baseQuery)
                ->whereNotNull('starts_at')
                ->whereDate('ends_at', '<', $today)
                ->count(),

            'unknown' => (clone $baseQuery)
                ->where(function (Builder $query): void {
                    $query->whereNull('starts_at')
                        ->orWhereNull('ends_at');
                })
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