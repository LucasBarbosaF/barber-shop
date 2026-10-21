<?php

namespace App\Policies;

use App\Application\Authorization\PermissionChecker;
use App\Application\Shared\Tenancy\TenantContext;
use App\Domain\Authorization\Enums\Permission;
use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Assinatura de recorrência.
 *
 * Ler exige `recurrence.view`; qualquer mutação do ciclo de vida (criar,
 * ativar, cancelar) exige `recurrence.manage` — quem opera a agenda na
 * recepção ativa o pagamento e agenda os cortes, sem poder alterar o catálogo
 * de planos (`RecurrencePlanPolicy`).
 */
final class RecurrenceSubscriptionPolicy extends BasePolicy
{
    private const DENIED_RESOURCE = 'Você não tem acesso a este recurso nesta barbearia.';

    public function __construct(
        private readonly PermissionChecker $permissions,
        private readonly TenantContext $tenantContext,
    ) {}

    public function viewAny(Model $user): bool|Response
    {
        return $this->allows($user, Permission::RecurrenceView)
            ?: $this->deny(self::DENIED_RESOURCE);
    }

    public function view(Model $user, Model $model): bool|Response
    {
        return $this->allows($user, Permission::RecurrenceView) && $this->belongsToCurrentTenant($model)
            ?: $this->deny(self::DENIED_RESOURCE);
    }

    public function create(Model $user): bool|Response
    {
        return $this->allows($user, Permission::RecurrenceManage)
            ?: $this->deny(self::DENIED_RESOURCE);
    }

    public function update(Model $user, Model $model): bool|Response
    {
        return $this->allows($user, Permission::RecurrenceManage) && $this->belongsToCurrentTenant($model)
            ?: $this->deny(self::DENIED_RESOURCE);
    }

    public function delete(Model $user, Model $model): bool|Response
    {
        return $this->allows($user, Permission::RecurrenceManage) && $this->belongsToCurrentTenant($model)
            ?: $this->deny(self::DENIED_RESOURCE);
    }

    private function allows(Model $user, Permission $permission): bool
    {
        $tenantId = $this->tenantContext->id();

        return $tenantId !== null
            && $this->permissions->allows($this->principal($user), $permission, $tenantId);
    }

    private function principal(Model $user): User
    {
        return $user instanceof User ? $user : throw new LogicException('A policy exige um App\Models\User.');
    }
}
