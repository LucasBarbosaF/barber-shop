<?php

namespace App\Http\Middleware;

use App\Application\Shared\Tenancy\TenantContext;
use App\Http\Middleware\Concerns\WritesDatabaseContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Informa ao banco **qual barbearia** a requisição está operando, para que o RLS
 * possa aplicá-la.
 *
 * ## A ordem em relação a `ResolveTenant` é obrigatória
 *
 * Este middleware roda **depois** de `ResolveTenant`, porque só depois dele o
 * `TenantContext` está preenchido. E roda **depois** de `SetUserDatabaseContext`,
 * que abriu a transação: `set_config(..., true)` é `SET LOCAL` e vale até o fim
 * da transação, então a transação precisa já existir quando este roda.
 *
 * Para os detalhes de por que o usuário é setado antes do tenant, e de por que
 * `SET LOCAL` sobrevive a savepoint aninhado, ver `WritesDatabaseContext`.
 *
 * ## Quem roda sem tenant
 *
 * `/admin` (superadmin) entra no par de middlewares sem `app.current_tenant`,
 * porque não pertence a nenhuma barbearia. `app.current_user` está sempre
 * setado, e é ele que permite à policy reconhecer o superadmin sem a aplicação
 * declarar sobre si mesma que é superadmin.
 */
class SetTenantDatabaseContext
{
    use WritesDatabaseContext;

    public function handle(Request $request, Closure $next): Response
    {
        $tenant = app(TenantContext::class)->id();

        return DB::connection()->transaction(function () use ($request, $tenant, $next): Response {
            $this->setLocal('app.current_tenant', $tenant);

            return $next($request);
        });
    }
}
