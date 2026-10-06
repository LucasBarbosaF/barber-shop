<?php

namespace App\Http\Middleware;

use App\Http\Middleware\Concerns\WritesDatabaseContext;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Abre a transação da requisição e informa **quem** está asking, para que o RLS
 * possa aplicá-la.
 *
 * ## Por que este middleware é separado do de tenant
 *
 * A policy de RLS não recebe o tenant por parâmetro — não existe como amarrar um
 * predicado a um valor do PHP. O valor vai para as *customized options*
 * `app.current_tenant` e `app.current_user`, e é isso que
 * `app.current_tenant_id()`, `app.current_user_id()` e `app.is_superadmin()`
 * leem.
 *
 * Este middleware roda **antes** de `ResolveTenant`, e essa ordem é a parte
 * difícil. `ResolveTenant` consulta `memberships` para decidir qual é a
 * barbearia; se `app.current_user` não estivesse setada, essa consulta só
 * devolveria o que a policy de tenant devolve — e como não há tenant ainda, ela
 * devolveria zero linhas, o tenant nunca resolveria e a política de RLS
 * quebraria a própria autenticação. Por isso o usuário é setado aqui e o tenant
 * só depois, em `SetTenantDatabaseContext`.
 *
 * O preço conhecido de abrir a transação aqui é que **toda** requisição
 * autenticada passa a rodar dentro de uma transação. É o que dá atomicidade por
 * requisição de graça. Export, job longo e webhook não passam por aqui — vão
 * pela fila, que não usa este middleware.
 */
class SetUserDatabaseContext
{
    use WritesDatabaseContext;

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        return DB::connection()->transaction(function () use ($request, $user, $next): Response {
            $this->setLocal('app.current_user', $user instanceof User ? $user->getAuthIdentifier() : null);

            return $next($request);
        });
    }
}
