<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Acesso exclusivo do superadmin (users.is_superadmin).
 *
 * O superadmin é o único usuário que cadastra barbearias. Todo controller
 * administrativo deve estar protegido por este middleware.
 */
class EnsureSuperadmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $user->isSuperadmin()) {
            abort(403, 'Acesso restrito ao superadmin.');
        }

        return $next($request);
    }
}
