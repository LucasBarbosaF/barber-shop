<?php

namespace App\Http\Middleware;

use App\Application\Shared\Tenancy\TenantContext;
use App\Application\Shared\Tenancy\TenantResolution;
use App\Application\Shared\Tenancy\TenantResolver;
use App\Infrastructure\Shared\Tenancy\SessionTenantContext;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolve o tenant corrente de quem está autenticado.
 *
 * Middleware **brando** de propósito: ele preenche o contexto quando consegue e
 * não bloqueia quando não consegue. Quem exige um tenant de verdade usa
 * `tenant.selected` (`EnsureTenantSelected`), para que a troca de tenant e a
 * tela de seleção continuem acessíveis a quem ainda não escolheu.
 *
 * Precisa vir depois de `auth`: sem usuário não há o que resolver.
 *
 * Só roda com sessão. O `SessionTenantContext` guarda a escolha em
 * `current_tenant_id`, e um token de API não tem sessão nem barra chosen tenant
 * — resolvê-lo aqui produziria um `SelectionRequired` falso em toda chamada
 * stateless. Quem resolver tenant por token faz isso explicitamente, na
 * aplicação, quando existir esse fluxo.
 */
class ResolveTenant
{
    public function __construct(private readonly TenantResolver $resolver) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && $request->hasSession()) {
            $request->attributes->set(
                TenantResolution::ATTRIBUTE,
                $this->resolver->resolveFor($user),
            );
        }

        return $next($request);
    }

    /**
     * O contexto persiste na sessão, mas a instância é singleton. Em worker de
     * longa duração (Octane) o valor em memória vazaria para a requisição
     * seguinte; esquecer a instância devolve a leitura à sessão, que é o estado
     * que realmente deve sobreviver à requisição.
     */
    public function terminate(Request $request, Response $response): void
    {
        app()->forgetInstance(TenantContext::class);
        app()->forgetInstance(SessionTenantContext::class);
    }
}
