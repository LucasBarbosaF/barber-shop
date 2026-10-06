<?php

namespace App\Policies;

use App\Application\Shared\Tenancy\TenantContext;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

/**
 * Base Policy for tenant-scoped resources.
 *
 * Convention:
 * 1. Check authentication (handled by Laravel).
 * 2. Check membership + tenant context (Sprint 2-4).
 * 3. Check role/permission via Gates or Spatie.
 * 4. Check resource belongs to current tenant.
 *
 * Never trust tenant_id sent from the frontend.
 *
 * O retorno é `bool|Response` e não só `bool` porque a recusa precisa dizer por
 * quê. Devolvendo `false`, o Gate monta a exceção com a mensagem padrão do
 * framework — em inglês, e genérica demais para quem está na tela. Devolvendo
 * `deny('...')`, o motivo vai junto e o formatador de 403 (Sprint 4) só tem de
 * exibi-lo.
 */
abstract class BasePolicy
{
    use HandlesAuthorization;

    abstract public function viewAny(Model $user): bool|Response;

    abstract public function view(Model $user, Model $model): bool|Response;

    abstract public function create(Model $user): bool|Response;

    abstract public function update(Model $user, Model $model): bool|Response;

    abstract public function delete(Model $user, Model $model): bool|Response;

    protected function belongsToCurrentTenant(Model $model): bool
    {
        return isset($model->tenant_id)
            && app(TenantContext::class)->id() === (string) $model->tenant_id;
    }
}
