<?php

namespace App\Policies;

use App\Application\Authorization\PermissionChecker;
use App\Application\Shared\Tenancy\TenantContext;
use App\Domain\Authorization\Enums\Permission;
use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;
use LogicException;

final class CustomerPolicy extends BasePolicy
{
    private const DENIED_RESOURCE = 'Você não tem acesso a este recurso nesta barbearia.';

    public function __construct(
        private readonly PermissionChecker $permissions,
        private readonly TenantContext $tenantContext,
    ) {}

    public function viewAny(Model $user): bool|Response
    {
        return $this->allows($user, Permission::CustomersView)
            ?: $this->deny(self::DENIED_RESOURCE);
    }

    public function view(Model $user, Model $model): bool|Response
    {
        return $this->allows($user, Permission::CustomersView) && $this->belongsToCurrentTenant($model)
            ?: $this->deny(self::DENIED_RESOURCE);
    }

    public function create(Model $user): bool|Response
    {
        return $this->allows($user, Permission::CustomersCreate)
            ?: $this->deny(self::DENIED_RESOURCE);
    }

    public function update(Model $user, Model $model): bool|Response
    {
        return $this->allows($user, Permission::CustomersUpdate) && $this->belongsToCurrentTenant($model)
            ?: $this->deny(self::DENIED_RESOURCE);
    }

    public function delete(Model $user, Model $model): bool|Response
    {
        return $this->allows($user, Permission::CustomersDelete) && $this->belongsToCurrentTenant($model)
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
