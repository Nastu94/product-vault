<?php

namespace App\Policies;

use App\Models\Product;
use App\Models\User;

class ProductPolicy
{
    /**
     * Permette di vedere la lista prodotti del workspace corrente.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('products.view');
    }

    /**
     * Permette di vedere un prodotto solo se appartiene
     * al team/workspace corrente dell'utente.
     */
    public function view(User $user, Product $product): bool
    {
        return $user->can('products.view')
            && $user->current_team_id === $product->team_id;
    }

    /**
     * Permette di creare prodotti nel workspace corrente.
     */
    public function create(User $user): bool
    {
        return $user->can('products.create');
    }

    /**
     * Permette di aggiornare un prodotto del workspace corrente.
     */
    public function update(User $user, Product $product): bool
    {
        return $user->can('products.update')
            && $user->current_team_id === $product->team_id;
    }

    /**
     * Permette di eliminare un prodotto del workspace corrente.
     */
    public function delete(User $user, Product $product): bool
    {
        return $user->can('products.delete')
            && $user->current_team_id === $product->team_id;
    }
}