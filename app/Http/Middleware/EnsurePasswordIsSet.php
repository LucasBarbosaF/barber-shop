<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Força a troca da senha provisória gerada pelo superadmin.
 *
 * Usuários criados em /admin/barbearias recebem uma senha aleatória mostrada
 * uma única vez ao superadmin. Até definirem a própria senha, ficam presos
 * nesta tela.
 */
class EnsurePasswordIsSet
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && $user->mustChangePassword()) {
            return redirect()->route('password.setup');
        }

        return $next($request);
    }
}
