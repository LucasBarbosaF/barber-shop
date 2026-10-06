<?php

namespace App\Policies;

use App\Application\Authorization\PermissionChecker;
use App\Application\Shared\Tenancy\TenantContext;
use App\Domain\Authorization\Enums\Permission;
use App\Domain\Tenant\Enums\MembershipRole;
use App\Domain\Tenant\Models\Membership;
use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Autorização sobre memberships — o recurso que decide o papel de cada pessoa.
 *
 * Este é o segundo ponto de decisão, ao lado dos Gates por permission:
 *
 * - Gate   → "esta pessoa tem a permission `users.view`?"
 * - Policy → "esta pessoa pode ver/alterar **este** membership?"
 *
 * A Policy sempre soma a checagem de tenant da BasePolicy: mesmo um admin da
 * barbearia A não toca em membership da barbearia B.
 *
 * As recusas saem com `deny()` e não com `false`: é o que faz a mensagem chegar
 * à tela em português, no lugar do "This action is unauthorized" do framework.
 *
 * Nenhuma mensagem diz *de qual* barbearia é o registro recusado. "Este membro
 * pertence a outra barbearia" confirmaria que o id existe em outro lugar, e essa
 * confirmação é o que permite enumerar a base alheia, id por id.
 */
final class MembershipPolicy extends BasePolicy
{
    /**
     * Recusa de recurso que não é desta barbearia, ou que a pessoa não alcança.
     *
     * Uma frase para os dois casos de propósito — a mesma justificativa serve
     * para quem não tem a permissão e para quem a tem mas em outra barbearia, e
     * nenhuma das duas precisa ser distinguível por fora.
     */
    private const DENIED_RESOURCE = 'Você não tem acesso a este recurso nesta barbearia.';

    public function __construct(private readonly PermissionChecker $permissions) {}

    public function viewAny(Model $user): bool|Response
    {
        if (! $this->allowsInCurrentTenant($user, Permission::UsersView)) {
            return $this->deny(self::DENIED_RESOURCE);
        }

        return true;
    }

    public function view(Model $user, Model $model): bool|Response
    {
        if (! $this->allowsInCurrentTenant($user, Permission::UsersView)) {
            return $this->deny(self::DENIED_RESOURCE);
        }

        return $this->belongsToCurrentTenant($model) ?: $this->deny(self::DENIED_RESOURCE);
    }

    public function create(Model $user): bool|Response
    {
        if (! $this->allowsInCurrentTenant($user, Permission::UsersManage)) {
            return $this->deny(self::DENIED_RESOURCE);
        }

        return $this->canManageRolesInCurrentTenant($user) ?: $this->deny(self::DENIED_RESOURCE);
    }

    public function update(Model $user, Model $model): bool|Response
    {
        if (! $this->allowsInCurrentTenant($user, Permission::UsersManage)
            || ! $this->canManageRolesInCurrentTenant($user)
            || ! $this->belongsToCurrentTenant($model)) {
            return $this->deny(self::DENIED_RESOURCE);
        }

        if (! $this->doesNotDemoteTheLastAdmin($model)) {
            return $this->deny('Esta é a última：admin de uma barbearia. Nomeie outro antes.');
        }

        return true;
    }

    public function delete(Model $user, Model $model): bool|Response
    {
        return $this->update($user, $model);
    }

    /**
     * The permission vale **no tenant do contexto**, nunca em qualquer um.
     *
     * Sem tenant no contexto a resposta é negativa: não existe uma barbearia
     * sobre a qual a role poderia estar falando.
     */
    private function allowsInCurrentTenant(Model $user, Permission $permission): bool
    {
        $tenantId = $this->currentTenantId();

        return $tenantId !== null
            && $this->permissions->allows($this->principal($user), $permission, $tenantId);
    }

    private function canManageRolesInCurrentTenant(Model $user): bool
    {
        $tenantId = $this->currentTenantId();

        return $tenantId !== null
            && $this->permissions->canManageRolesIn($this->principal($user), $tenantId);
    }

    /**
     * Rebaixar ou remover o único admin deixaria a barbearia sem ninguém capaz
     * de conceder roles.
     */
    private function doesNotDemoteTheLastAdmin(Model $model): bool
    {
        if (! $model instanceof Membership || ! $model->isAdmin()) {
            return true;
        }

        return Membership::query()
            ->where('tenant_id', $model->tenant_id)
            ->where('is_active', true)
            ->where('role', MembershipRole::Admin->value)
            ->whereKeyNot($model->getKey())
            ->exists();
    }

    private function currentTenantId(): ?string
    {
        $tenantId = app(TenantContext::class)->id();

        return $tenantId !== null ? (string) $tenantId : null;
    }

    private function principal(Model $user): User
    {
        return $user instanceof User ? $user : throw new LogicException('A policy exige um App\Models\User.');
    }
}
