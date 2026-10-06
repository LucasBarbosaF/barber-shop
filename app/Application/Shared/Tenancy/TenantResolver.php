<?php

namespace App\Application\Shared\Tenancy;

use App\Application\Authorization\PermissionChecker;
use App\Application\Shared\Tenancy\Exceptions\TenantAccessDenied;
use App\Domain\Tenant\Models\Tenant;
use App\Models\User;

/**
 * Decide qual é o tenant da requisição.
 *
 * Regra: o tenant **nunca** vem do pedido. Vem da sessão, e a sessão só é
 * acreditada quando a pessoa ainda tem membership ativa naquela barbearia e a
 * barbearia continua ativa. É a diferença entre lembrar a escolha e obedecê-la:
 * sem essa revalidação, bastaria plantar `current_tenant_id` na sessão para
 * escrever na barbearia alheia.
 *
 * Seleção (decidida na Sprint 3): automática com uma única barbearia; explícita
 * quando há mais de uma, porque escolher a "primeira" seria inventar autorização
 * que ninguém concedeu.
 *
 * Subdomínio ficou de fora por decisão: entra quando existir site público
 * (Sprint 19), com DNS e certificado próprios. Até lá a sessão é a única fonte.
 */
final class TenantResolver
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly PermissionChecker $permissions,
    ) {}

    /**
     * Resolve o tenant corrente de quem já está autenticado.
     *
     * Mantém o tenant já escolhido se ainda for válido; adota o único disponível;
     * devolve `SelectionRequired` quando há mais de um e `None` quando não há
     * nenhum. Nunca lança: "não tem tenant" é estado legítimo da navegação.
     */
    public function resolveFor(User $user): TenantResolution
    {
        $available = $this->permissions->activeTenantIds($user);
        $current = $this->tenantContext->id();

        if ($current !== null && in_array($current, $available, true)) {
            return TenantResolution::resolved($current, $available);
        }

        // A sessão apontava para uma barbearia que a pessoa não pode mais acessar
        // (membership revogada, barbershops desativada ou sessão forjada).
        // Descartar é obrigatório: manter seria aceitar o frontend como fonte.
        if ($current !== null) {
            $this->tenantContext->clear();
        }

        if ($available === []) {
            return TenantResolution::none();
        }

        if (count($available) === 1) {
            $this->tenantContext->set($available[0]);

            return TenantResolution::resolved($available[0], $available);
        }

        return TenantResolution::selectionRequired($available);
    }

    /**
     * Troca explícita de tenant, exigindo membership ativa.
     *
     * @throws TenantAccessDenied quando o tenant não existe ou a pessoa não pertence a ele
     */
    public function switchTo(User $user, string $tenantId): Tenant
    {
        $tenant = Tenant::query()->find($tenantId);

        if ($tenant === null) {
            throw TenantAccessDenied::forTenant($tenantId);
        }

        if (! in_array($tenantId, $this->permissions->activeTenantIds($user), true)) {
            throw TenantAccessDenied::forTenant($tenantId);
        }

        $this->tenantContext->set($tenantId);

        return $tenant;
    }
}
