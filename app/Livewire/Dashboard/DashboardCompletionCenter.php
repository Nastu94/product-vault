<?php

namespace App\Livewire\Dashboard;

use App\Models\Product;
use App\Models\ProductIdentificationCandidate;
use App\Models\User;
use App\Models\Warranty;
use App\Services\Warranties\WarrantyCoverageContextResolver;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

final class DashboardCompletionCenter extends Component
{
    /**
     * @var list<array<string, mixed>>
     */
    public array $completionItems = [];

    public int $completionTasksCount = 0;

    public int $pendingCandidatesCount = 0;

    public int $estimatedCoveragesCount = 0;

    public int $missingPurchaseDatesCount = 0;

    public int $missingSourceDocumentsCount = 0;

    public function mount(
        WarrantyCoverageContextResolver $coverageResolver
    ): void {
        $user = Auth::user();

        if (! $user instanceof User || $user->current_team_id === null) {
            return;
        }

        $teamId = (int) $user->current_team_id;

        $pendingCandidatesQuery = ProductIdentificationCandidate::query()
            ->whereHas(
                'document',
                fn (Builder $query): Builder => $query
                    ->where('team_id', $teamId)
            )
            ->where('review_status', 'pending')
            ->whereNull('product_id');

        $this->pendingCandidatesCount =
            (clone $pendingCandidatesQuery)->count();

        $productQuery = Product::query()
            ->where('team_id', $teamId);

        $this->missingPurchaseDatesCount =
            (clone $productQuery)
                ->whereNull('purchase_date')
                ->count();

        $this->missingSourceDocumentsCount =
            (clone $productQuery)
                ->whereDoesntHave('documents')
                ->count();

        $estimatedCoverages = Warranty::query()
            ->with([
                'product:id,team_id,name',
                'warrantyType:id,code,name',
            ])
            ->whereHas(
                'product',
                fn (Builder $query): Builder => $query
                    ->where('team_id', $teamId)
            )
            ->where(function (Builder $query): void {
                $query
                    ->where('source', 'calculated')
                    ->orWhere(
                        'metadata->coverage_context->state',
                        'estimated'
                    );
            })
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->get()
            ->map(function (Warranty $warranty) use (
                $coverageResolver
            ): array {
                return [
                    'warranty' => $warranty,
                    'context' => $coverageResolver->resolve($warranty),
                ];
            })
            ->filter(
                fn (array $item): bool =>
                    data_get(
                        $item,
                        'context.coverage_state.is_estimate'
                    ) === true
                    && data_get(
                        $item,
                        'context.confirmation.is_confirmed'
                    ) !== true
            )
            ->values();

        $this->estimatedCoveragesCount =
            $estimatedCoverages->count();

        $candidateItems = (clone $pendingCandidatesQuery)
            ->with('document:id,original_filename')
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit(4)
            ->get()
            ->map(
                fn (
                    ProductIdentificationCandidate $candidate
                ): array => [
                    'type' => 'candidate',
                    'priority' => 1,
                    'sort_timestamp' =>
                        $candidate->updated_at?->getTimestamp() ?? 0,
                    'title' => $candidate->name,
                    'subtitle' => $candidate->document?->original_filename
                        ?? 'Documento non disponibile',
                    'badge_label' => 'Da revisionare',
                    'badge_classes' =>
                        'bg-orange-50 text-orange-700 ring-orange-600/20',
                    'action_label' => 'Apri revisione',
                    'url' => route(
                        'reviews.index',
                        ['filter' => 'pending']
                    ),
                    'updated_at_label' =>
                        $candidate->updated_at?->diffForHumans() ?? '—',
                ]
            );

        $coverageItems = $estimatedCoverages
            ->take(4)
            ->map(function (array $item): array {
                /** @var Warranty $warranty */
                $warranty = $item['warranty'];

                return [
                    'type' => 'coverage',
                    'priority' => 2,
                    'sort_timestamp' =>
                        $warranty->updated_at?->getTimestamp() ?? 0,
                    'title' => $warranty->product?->name
                        ?? 'Prodotto non disponibile',
                    'subtitle' =>
                        'Copertura stimata da verificare e confermare.',
                    'badge_label' => 'Copertura stimata',
                    'badge_classes' =>
                        'bg-yellow-50 text-yellow-800 ring-yellow-600/20',
                    'action_label' => 'Controlla copertura',
                    'url' => $warranty->product
                        ? route('products.show', $warranty->product)
                        : route('warranties.index'),
                    'updated_at_label' =>
                        $warranty->updated_at?->diffForHumans() ?? '—',
                ];
            });

        $productItems = Product::query()
            ->where('team_id', $teamId)
            ->withCount('documents')
            ->where(function (Builder $query): void {
                $query
                    ->whereNull('purchase_date')
                    ->orWhereDoesntHave('documents');
            })
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit(6)
            ->get()
            ->map(function (Product $product): array {
                $missing = [];

                if ($product->purchase_date === null) {
                    $missing[] = 'data di acquisto';
                }

                if ((int) $product->documents_count === 0) {
                    $missing[] = 'documento sorgente';
                }

                return [
                    'type' => 'product',
                    'priority' => 3,
                    'sort_timestamp' =>
                        $product->updated_at?->getTimestamp() ?? 0,
                    'title' => $product->name,
                    'subtitle' => 'Completa: ' . implode(', ', $missing) . '.',
                    'badge_label' => 'Dati mancanti',
                    'badge_classes' =>
                        'bg-slate-100 text-slate-700 ring-slate-500/20',
                    'action_label' => 'Completa prodotto',
                    'url' => route('products.show', $product),
                    'updated_at_label' =>
                        $product->updated_at?->diffForHumans() ?? '—',
                ];
            });

        $this->completionItems = $candidateItems
            ->concat($coverageItems)
            ->concat($productItems)
            ->sort(function (array $left, array $right): int {
                $priorityComparison =
                    $left['priority'] <=> $right['priority'];

                if ($priorityComparison !== 0) {
                    return $priorityComparison;
                }

                return $right['sort_timestamp']
                    <=> $left['sort_timestamp'];
            })
            ->take(6)
            ->map(function (array $item): array {
                unset($item['priority'], $item['sort_timestamp']);

                return $item;
            })
            ->values()
            ->all();

        $this->completionTasksCount =
            $this->pendingCandidatesCount
            + $this->estimatedCoveragesCount
            + $this->missingPurchaseDatesCount
            + $this->missingSourceDocumentsCount;
    }

    public function render(): View
    {
        return view(
            'livewire.dashboard.dashboard-completion-center'
        );
    }
}
