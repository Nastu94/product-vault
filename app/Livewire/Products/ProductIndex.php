<?php

namespace App\Livewire\Products;

use App\Models\Product;
use App\Models\Warranty;
use App\Services\Warranties\WarrantyCoverageContextResolver;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\WithPagination;

class ProductIndex extends Component
{
    use AuthorizesRequests;
    use WithPagination;

    /**
     * Numero di prodotti mostrati per pagina.
     */
    public int $perPage = 10;

    /**
     * Cache locale, valida per la singola richiesta Livewire,
     * dei contesti già risolti.
     *
     * @var array<int, array<string, mixed>>
     */
    private array $warrantyCoverageContextCache = [];

    /**
     * Garanzia principale da mostrare nella lista prodotti.
     */
    public function primaryWarranty(Product $product): ?Warranty
    {
        return $product->warranties
            ->sort(function ($first, $second): int {
                $firstIsLegal = $first->warrantyType?->code === 'legal' ? 1 : 0;
                $secondIsLegal = $second->warrantyType?->code === 'legal' ? 1 : 0;

                if ($firstIsLegal !== $secondIsLegal) {
                    return $secondIsLegal <=> $firstIsLegal;
                }

                $firstEndsAt = $first->ends_at?->timestamp ?? PHP_INT_MAX;
                $secondEndsAt = $second->ends_at?->timestamp ?? PHP_INT_MAX;

                return $firstEndsAt <=> $secondEndsAt;
            })
            ->first();
    }

    /**
     * Restituisce il contesto normalizzato della copertura.
     *
     * Il risultato viene memorizzato soltanto per la richiesta
     * corrente e non modifica la garanzia o i relativi metadata.
     *
     * @return array<string, mixed>|null
     */
    public function warrantyCoverageContext(
        ?Warranty $warranty
    ): ?array {
        if (! $warranty) {
            return null;
        }

        $warrantyId = (int) $warranty->getKey();

        if (
            ! array_key_exists(
                $warrantyId,
                $this->warrantyCoverageContextCache
            )
        ) {
            $this->warrantyCoverageContextCache[$warrantyId] = app(
                WarrantyCoverageContextResolver::class
            )->resolve($warranty);
        }

        return $this->warrantyCoverageContextCache[$warrantyId];
    }

    /**
     * Etichetta dello stato temporale del periodo indicato.
     */
    public function warrantyStatusLabel(
        ?Warranty $warranty
    ): string {
        if (! $warranty) {
            return 'Nessun periodo';
        }

        return (string) data_get(
            $this->warrantyCoverageContext($warranty),
            'temporal_status.label',
            'Periodo non determinabile'
        );
    }

    /**
     * Classi del badge relativo esclusivamente al periodo.
     */
    public function warrantyStatusBadgeClasses(
        ?Warranty $warranty
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
        ?Warranty $warranty
    ): string {
        if (! $warranty) {
            return 'Nessuna copertura';
        }

        return (string) data_get(
            $this->warrantyCoverageContext($warranty),
            'coverage_state.label',
            'Copertura non determinata'
        );
    }

    /**
     * Classi del badge dello stato della copertura.
     *
     * Queste classi non descrivono lo stato temporale.
     */
    public function warrantyCoverageStateBadgeClasses(
        ?Warranty $warranty
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
     * Etichetta del tipo di copertura.
     */
    public function warrantyCoverageTypeLabel(
        ?Warranty $warranty
    ): string {
        if (! $warranty) {
            return '—';
        }

        return (string) data_get(
            $this->warrantyCoverageContext($warranty),
            'coverage_type.label',
            'Tipo non disponibile'
        );
    }

    /**
     * Indica se la copertura è ancora una stima.
     */
    public function warrantyCoverageIsEstimate(
        ?Warranty $warranty
    ): bool {
        return (bool) data_get(
            $this->warrantyCoverageContext($warranty),
            'coverage_state.is_estimate',
            false
        );
    }

    /**
     * Numero di informazioni ancora mancanti.
     */
    public function warrantyMissingInformationCount(
        ?Warranty $warranty
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
        ?Warranty $warranty
    ): string {
        if (! $warranty) {
            return '—';
        }

        return (string) data_get(
            $this->warrantyCoverageContext($warranty),
            'source.label',
            $warranty->source
        );
    }

    /**
     * Giorni residui alla scadenza.
     */
    public function warrantyRemainingDays(?Warranty $warranty): ?int
    {
        if (! $warranty?->ends_at) {
            return null;
        }

        return now()->startOfDay()->diffInDays($warranty->ends_at, false);
    }

    /**
     * Mostra i prodotti del workspace corrente.
     */
    public function render(): View
    {
        $this->authorize('viewAny', Product::class);

        $user = Auth::user();

        $teamId = $user->current_team_id ?? $user->currentTeam?->id;

        $products = Product::query()
            ->with([
                'merchant',
                'currency',
                'identificationStatus',
                'documents',
                'warranties.warrantyType',
            ])
            ->where('team_id', $teamId)
            ->latest()
            ->paginate($this->perPage);

        return view('livewire.products.product-index', [
            'products' => $products,
        ])->layout('layouts.app');
    }
}