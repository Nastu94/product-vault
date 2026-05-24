<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;

class SetCurrentTeamForPermissions
{
    /**
     * Imposta il team Jetstream corrente come contesto attivo per Spatie Permission.
     *
     * In ProductVault il Team Jetstream rappresenta il workspace/account attivo.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->currentTeam) {
            app(PermissionRegistrar::class)->setPermissionsTeamId($user->currentTeam->id);

            // Evita che ruoli/permessi già caricati restino legati a un team precedente.
            $user->unsetRelation('roles');
            $user->unsetRelation('permissions');
        }

        return $next($request);
    }
}