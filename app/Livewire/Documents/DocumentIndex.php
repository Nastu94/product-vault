<?php

namespace App\Livewire\Documents;

use App\Models\Document;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\WithPagination;

class DocumentIndex extends Component
{
    use AuthorizesRequests;
    use WithPagination;

    /**
     * Numero di documenti mostrati per pagina.
     */
    public int $perPage = 10;

    /**
     * Mostra l'elenco dei documenti appartenenti al team/workspace corrente.
     */
    public function render(): View
    {
        $user = Auth::user();

        /*
        |--------------------------------------------------------------------------
        | Autorizzazione
        |--------------------------------------------------------------------------
        |
        | Il permesso documents.view permette di accedere alla sezione documenti.
        | La policy dovrà comunque limitare i singoli record al team corrente.
        |
        */
        $this->authorize('viewAny', Document::class);

        $teamId = $user->current_team_id ?? $user->currentTeam?->id;

        $documents = Document::query()
            ->with([
                'documentType',
                'merchant',
                'currency',
                'uploadedBy',
            ])
            ->where('team_id', $teamId)
            ->latest()
            ->paginate($this->perPage);

        return view('livewire.documents.document-index', [
            'documents' => $documents,
        ])->layout('layouts.app');
    }
}