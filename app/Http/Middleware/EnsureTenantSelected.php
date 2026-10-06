<?php

namespace App\Http\Middleware;

use App\Application\Shared\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Exige que já exista um tenant escolhido no contexto.
 *
 * Uso: `->middleware('tenant.selected')`.
 *
 * Sem tenant no contexto não existe resposta correta para a rota: qualquer
 * consulta feita aqui seria cross-tenant por definição. Por isso 403, e não um
 * redirecionamento silencioso — a pessoa precisa escolher a barbearia, e a
 * escolha é dela.
 */
class EnsureTenantSelected
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    public function handle(Request $request, Closure $next): Response
    {
        abort_if(
            ! $this->tenantContext->hasTenant(),
            403,
            'Escolha uma barbearia para continuar.',
        );

        return $next($request);
    }
}
