<?php

namespace App\Application\Authorization;

use App\Domain\Authorization\Enums\Permission;
use App\Domain\Authorization\RolePermissions;
use App\Domain\Tenant\Enums\MembershipRole;
use App\Domain\Tenant\Models\Membership;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Ponto único de decisão de autorização por permission.
 *
 * Regra aplicada, na ordem:
 *
 *   1. tenant ativo (tenants.is_active);
 *   2. membership ativa (memberships.is_active);
 *   3. role da membership concede a permission (matriz RolePermissions);
 *   4. se um tenantId for informado, a role precisa valer **naquele** tenant.
 *
 * O passo 4 é o que impede o bypass cross-tenant: sem ele, `admin` na barbearia
 * A autorizaria a mesma ação na barbearia B. Por isso este serviço substitui
 * `User::hasRoleInAnyTenant()`, que não pode ser usado para autorizar.
 *
 * O superadmin (users.is_superadmin) **não** entra nesta conta, de propósito:
 * ele é global e não pertence a nenhuma barbearia, e é autorizado pelo
 * middleware `superadmin` em /admin. Tratá-lo como dono de todas as
 * permissions o transformaria em admin de qualquer tenant sem membership.
 */
final class PermissionChecker
{
    public function allows(User $user, Permission $permission, ?string $tenantId = null): bool
    {
        foreach ($this->activeMemberships($user, $tenantId) as $membership) {
            if (in_array($permission, RolePermissions::for($membership->role), true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * O usuário tem a role no tenant informado?
     *
     * tenantId é obrigatório de propósito: sem ele a pergunta seria cross-tenant.
     */
    public function hasRoleIn(User $user, MembershipRole $role, string $tenantId): bool
    {
        return $this->activeMemberships($user, $tenantId)
            ->contains(fn (Membership $membership): bool => $membership->role === $role);
    }

    public function canManageRolesIn(User $user, string $tenantId): bool
    {
        return $this->activeMemberships($user, $tenantId)
            ->contains(
                fn (Membership $membership): bool => $membership->role->canManageRoles()
            );
    }

    /**
     * Ids dos tenants em que a pessoa pode agir agora: membership ativa em
     * barbearia ativa.
     *
     * É a mesma regra de `allows()`/`hasRoleIn()`, exposta porque a seleção de
     * tenant (Sprint 3) precisa da lista de barbearias válidas. Duplicar a
     * consulta aqui criaria dois lugares decidindo "membership ativa", que é
     * exatamente o tipo de deriva que já causou bypass.
     *
     * @return array<int, string>
     */
    public function activeTenantIds(User $user): array
    {
        return $this->activeMemberships($user, null)
            ->map(fn (Membership $membership): string => (string) $membership->tenant_id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Memberships que valem para a autorização agora: ativas e de uma
     * barbearia ativa.
     *
     * Sem `select()` de propósito: cortar as colunas faz o PHPStan perder o
     * tipo do model e o `role` volta a ser `string` em vez de `MembershipRole`.
     * A tabela é pequena, e ler a linha inteira sai mais barato que mentir
     * sobre o tipo.
     *
     * @return Collection<int, Membership>
     */
    private function activeMemberships(User $user, ?string $tenantId): Collection
    {
        return $user->memberships()
            ->where('is_active', true)
            ->whereHas('tenant', fn (Builder $query): Builder => $query->where('is_active', true))
            ->when($tenantId !== null, fn (Builder $query): Builder => $query->where('tenant_id', $tenantId))
            ->get();
    }
}
