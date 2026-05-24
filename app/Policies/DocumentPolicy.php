<?php

namespace App\Policies;

use App\Models\Document;
use App\Models\User;

class DocumentPolicy
{
    /**
     * Permette di vedere la lista dei documenti del workspace corrente.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('documents.view');
    }

    /**
     * Permette di vedere un singolo documento solo se appartiene
     * al team/workspace corrente dell'utente.
     */
    public function view(User $user, Document $document): bool
    {
        return $user->can('documents.view')
            && $user->current_team_id === $document->team_id;
    }

    /**
     * Permette di caricare documenti nel workspace corrente.
     */
    public function create(User $user): bool
    {
        return $user->can('documents.upload');
    }

    /**
     * Permette di aggiornare un documento solo se appartiene
     * al team/workspace corrente.
     */
    public function update(User $user, Document $document): bool
    {
        return $user->can('documents.update')
            && $user->current_team_id === $document->team_id;
    }

    /**
     * Permette di eliminare un documento solo se appartiene
     * al team/workspace corrente.
     */
    public function delete(User $user, Document $document): bool
    {
        return $user->can('documents.delete')
            && $user->current_team_id === $document->team_id;
    }
}