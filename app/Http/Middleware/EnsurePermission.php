<?php

namespace App\Http\Middleware;

use App\Domain\Authorization\Enums\Permission;
use App\Models\User;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Autoriza a rota pela permission do Gate, no tenant do TenantContext.
 *
 * Uso: `->middleware('permission:customers.update')`.
 *
 * Guest recebe 401 (ou é redirecionado para o login, como manda
 * `bootstrap/app.php`); autenticado sem a permission recebe 403. A
 * distinção importa: 401 diz "entre", 403 diz "não pode".
 *
 * O `tenant_id` da requisição nunca participa da decisão — o tenant vem do
 * TenantContext, preenchido pelo TenantResolver (Sprint 3).
 */
class EnsurePermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        $required = Permission::tryFrom($permission);

        // Alias de rota com permission inexistente é erro de configuração:
        // falhar alto é melhor que liberar a rota silenciosamente.
        abort_if($required === null, 500, "Permission de rota inexistente: [{$permission}].");

        abort_unless($user->can($required->value), 403, 'Você não tem permissão para esta ação.');

        return $next($request);
    }
}
