<?php

namespace App\Policies;

use App\Models\Product;
use App\Models\ProductCase;
use App\Models\User;

class ProductCasePolicy
{
    /**
     * Permette di vedere le pratiche del workspace corrente.
     */
    public function viewAny(User $user): bool
    {
        return $user->current_team_id !== null
            && $user->can('product_cases.view');
    }

    /**
     * Permette di vedere una pratica solo nel workspace corrente.
     */
    public function view(
        User $user,
        ProductCase $productCase
    ): bool {
        return $this->canAccess(
            user: $user,
            productCase: $productCase,
            permission: 'product_cases.view',
        );
    }

    /**
     * Permette di aprire una pratica per un prodotto del workspace corrente.
     *
     * La chiamata prevista è:
     * authorize('create', [ProductCase::class, $product]).
     */
    public function create(
        User $user,
        Product $product
    ): bool {
        return $user->current_team_id !== null
            && $user->can('product_cases.create')
            && (int) $user->current_team_id
                === (int) $product->team_id;
    }

    /**
     * Permette di aggiornare una pratica del workspace corrente.
     */
    public function update(
        User $user,
        ProductCase $productCase
    ): bool {
        return $this->canAccess(
            user: $user,
            productCase: $productCase,
            permission: 'product_cases.update',
        );
    }

    /**
     * Permette di risolvere, chiudere o annullare una pratica.
     *
     * Le transizioni valide saranno comunque controllate da un service
     * di dominio dedicato.
     */
    public function close(
        User $user,
        ProductCase $productCase
    ): bool {
        return $this->canAccess(
            user: $user,
            productCase: $productCase,
            permission: 'product_cases.close',
        );
    }

    /**
     * Permette di eliminare logicamente una pratica.
     */
    public function delete(
        User $user,
        ProductCase $productCase
    ): bool {
        return $this->canAccess(
            user: $user,
            productCase: $productCase,
            permission: 'product_cases.delete',
        );
    }

    /**
     * Verifica permesso e appartenenza al workspace corrente.
     */
    private function canAccess(
        User $user,
        ProductCase $productCase,
        string $permission
    ): bool {
        return $user->current_team_id !== null
            && $user->can($permission)
            && (int) $user->current_team_id
                === (int) $productCase->team_id;
    }
}