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
     * Mostra la homepage della dashboard.
     *
     * La dashboard deve sempre leggere i dati relativi al workspace/team attivo,
     * non dati globali dell'applicazione.
     */
    public function index(Request $request): View
    {
        $user = $request->user();

        /*
        |--------------------------------------------------------------------------
        | Workspace / team attivo
        |--------------------------------------------------------------------------
        |
        | Il progetto usa Jetstream con team. Per ora trattiamo il currentTeam come
        | workspace attivo. Le tabelle business usano team_id, quindi useremo
        | l'id del team corrente come identificativo dell'account/workspace attivo.
        |
        */
        $currentTeam = Jetstream::hasTeamFeatures()
            ? $user->currentTeam
            : null;

        $activeTeamId = $currentTeam?->id;

        $activeWorkspaceName = $currentTeam?->name ?? 'Personale ' . $user->name;

        /*
        |--------------------------------------------------------------------------
        | Conteggi principali
        |--------------------------------------------------------------------------
        |
        | Se non esiste un workspace attivo, restituiamo conteggi a zero.
        | In condizioni normali Jetstream dovrebbe sempre avere un currentTeam.
        |
        */
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

        /*
        |--------------------------------------------------------------------------
        | Revisioni aperte
        |--------------------------------------------------------------------------
        |
        | Per MVP consideriamo da revisionare i documenti con status needs_review
        | o low_confidence. Più avanti potremo includere anche prodotti parziali,
        | processing falliti o garanzie da confermare.
        |
        */
        $openReviewsCount = $activeTeamId
            ? Document::query()
                ->where('team_id', $activeTeamId)
                ->whereIn('status', ['needs_review', 'low_confidence'])
                ->count()
            : 0;

        /*
        |--------------------------------------------------------------------------
        | Garanzie in scadenza
        |--------------------------------------------------------------------------
        |
        | Conteggiamo le garanzie collegate a prodotti del workspace attivo
        | con data di fine entro i prossimi 30 giorni.
        |
        */
        $expiringWarrantiesCount = $activeTeamId
            ? Warranty::query()
                ->whereIn(
                    'product_id',
                    Product::query()
                        ->select('id')
                        ->where('team_id', $activeTeamId)
                )
                ->whereNotNull('ends_at')
                ->whereBetween('ends_at', [now(), now()->addDays(30)])
                ->count()
            : 0;

        $warrantiesCount = $activeTeamId
            ? Warranty::query()
                ->whereIn(
                    'product_id',
                    Product::query()
                        ->select('id')
                        ->where('team_id', $activeTeamId)
                )
                ->count()
            : 0;

        $expiredWarrantiesCount = $activeTeamId
            ? Warranty::query()
                ->whereIn(
                    'product_id',
                    Product::query()
                        ->select('id')
                        ->where('team_id', $activeTeamId)
                )
                ->whereNotNull('ends_at')
                ->whereDate('ends_at', '<', now()->toDateString())
                ->count()
            : 0;

        $stats = [
            'documents_count' => $documentsCount,
            'products_count' => $productsCount,
            'open_reviews_count' => $openReviewsCount,
            'warranties_count' => $warrantiesCount,
            'expiring_warranties_count' => $expiringWarrantiesCount,
            'expired_warranties_count' => $expiredWarrantiesCount,
        ];

        /*
        |--------------------------------------------------------------------------
        | Liste sintetiche dashboard
        |--------------------------------------------------------------------------
        |
        | Mostriamo pochi elementi recenti, sempre filtrati per team attivo.
        | La dashboard deve rimanere compatta: non è una pagina elenco completa.
        |
        */
        $openReviewDocuments = $activeTeamId
            ? Document::query()
                ->where('team_id', $activeTeamId)
                ->whereIn('status', ['needs_review', 'low_confidence'])
                ->latest()
                ->limit(3)
                ->get(['id', 'original_filename', 'status', 'created_at'])
            : collect();

        $recentDocuments = $activeTeamId
            ? Document::query()
                ->where('team_id', $activeTeamId)
                ->latest()
                ->limit(4)
                ->get(['id', 'original_filename', 'status', 'created_at'])
            : collect();

        $recentProducts = $activeTeamId
            ? Product::query()
                ->where('team_id', $activeTeamId)
                ->latest()
                ->limit(4)
                ->get(['id', 'name', 'created_at'])
            : collect();

        $expiringWarranties = $activeTeamId
            ? Warranty::query()
                ->with([
                    'product',
                    'warrantyType',
                ])
                ->whereIn(
                    'product_id',
                    Product::query()
                        ->select('id')
                        ->where('team_id', $activeTeamId)
                )
                ->whereNotNull('ends_at')
                ->whereBetween('ends_at', [
                    now()->toDateString(),
                    now()->addDays(30)->toDateString(),
                ])
                ->orderBy('ends_at')
                ->limit(3)
                ->get()
            : collect();

        return view('dashboard', [
            'userName' => $user->name,
            'activeWorkspaceName' => $activeWorkspaceName,
            'stats' => $stats,
            'isArchiveEmpty' => $documentsCount === 0 && $productsCount === 0,
            'openReviewDocuments' => $openReviewDocuments,
            'recentDocuments' => $recentDocuments,
            'recentProducts' => $recentProducts,
            'expiringWarranties' => $expiringWarranties,
        ]);
    }
}