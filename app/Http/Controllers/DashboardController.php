<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\Product;
use App\Models\Warranty;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Laravel\Jetstream\Jetstream;

class DashboardController extends Controller
{
    /**
     * Mostra la dashboard del workspace attivo.
     */
    public function index(Request $request): View
    {
        $user = $request->user();

        $currentTeam = Jetstream::hasTeamFeatures()
            ? $user->currentTeam
            : null;

        $activeTeamId = $currentTeam?->id;

        $activeWorkspaceName = $currentTeam?->name
            ?? 'Personale ' . $user->name;

        $documentsCount = $activeTeamId
            ? Document::query()
                ->where('team_id', $activeTeamId)
                ->count()
            : 0;

        $productsCount = $activeTeamId
            ? Product::query()
                ->where('team_id', $activeTeamId)
                ->count()
            : 0;

        $openReviewsCount = $activeTeamId
            ? Document::query()
                ->where('team_id', $activeTeamId)
                ->whereIn(
                    'status',
                    ['needs_review', 'low_confidence']
                )
                ->count()
            : 0;

        $today = now()
            ->startOfDay()
            ->toDateString();

        $soon = now()
            ->startOfDay()
            ->addDays(30)
            ->toDateString();

        $workspaceProductIds = Product::query()
            ->select('id')
            ->when(
                $activeTeamId !== null,
                fn ($query) => $query
                    ->where('team_id', $activeTeamId),
                fn ($query) => $query->whereRaw('1 = 0')
            );

        $expiringWarrantiesCount = Warranty::query()
            ->whereIn('product_id', clone $workspaceProductIds)
            ->whereNotNull('starts_at')
            ->whereNotNull('ends_at')
            ->whereDate('starts_at', '<=', $today)
            ->whereDate('ends_at', '>=', $today)
            ->whereDate('ends_at', '<=', $soon)
            ->count();

        $warrantiesCount = Warranty::query()
            ->whereIn('product_id', clone $workspaceProductIds)
            ->count();

        $expiredWarrantiesCount = Warranty::query()
            ->whereIn('product_id', clone $workspaceProductIds)
            ->whereNotNull('ends_at')
            ->whereDate('ends_at', '<', $today)
            ->count();

        $recentDocuments = $activeTeamId
            ? Document::query()
                ->where('team_id', $activeTeamId)
                ->latest()
                ->limit(4)
                ->get([
                    'id',
                    'original_filename',
                    'status',
                    'created_at',
                ])
            : collect();

        $recentProducts = $activeTeamId
            ? Product::query()
                ->where('team_id', $activeTeamId)
                ->latest()
                ->limit(4)
                ->get([
                    'id',
                    'name',
                    'created_at',
                ])
            : collect();

        return view('dashboard', [
            'userName' => $user->name,
            'activeWorkspaceName' => $activeWorkspaceName,
            'stats' => [
                'documents_count' => $documentsCount,
                'products_count' => $productsCount,
                'open_reviews_count' => $openReviewsCount,
                'warranties_count' => $warrantiesCount,
                'expiring_warranties_count' =>
                    $expiringWarrantiesCount,
                'expired_warranties_count' =>
                    $expiredWarrantiesCount,
            ],
            'isArchiveEmpty' =>
                $documentsCount === 0
                && $productsCount === 0,
            'recentDocuments' => $recentDocuments,
            'recentProducts' => $recentProducts,
        ]);
    }
}
