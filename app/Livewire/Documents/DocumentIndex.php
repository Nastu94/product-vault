<?php

namespace App\Livewire\Documents;

use App\Models\Document;
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
    public function render()
    {
        $user = Auth::user();

        // Verifica che l'utente abbia il permesso documents.view
        // nel workspace/team corrente.
        $this->authorize('viewAny', Document::class);

        $documents = Document::query()
            ->where('team_id', $user->current_team_id)
            ->latest()
            ->paginate($this->perPage);

        return view('livewire.documents.document-index', [
            'documents' => $documents,
        ]);
    }
}