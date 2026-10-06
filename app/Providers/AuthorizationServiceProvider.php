<?php

namespace App\Providers;

use App\Application\Authorization\PermissionChecker;
use App\Application\Shared\Tenancy\TenantContext;
use App\Domain\Authorization\Enums\Permission;
use App\Domain\Tenant\Models\Membership;
use App\Models\Barber;
use App\Models\Customer;
use App\Models\Service;
use App\Models\User;
use App\Policies\BarberPolicy;
use App\Policies\CustomerPolicy;
use App\Policies\MembershipPolicy;
use App\Policies\ServicePolicy;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Registra a camada de autorização criada na Sprint 1.
 *
 * Duas portas, com papéis distintos (decisão 7.1.4 do discovery):
 *
 * - **Gate** por `Permission` → "esta pessoa pode *customers.update* aqui?"
 * - **Policy** por recurso   → "esta pessoa pode alterar *este* membership?"
 *
 * Todo Gate resolve o tenant pelo `TenantContext` — nunca pelo `tenant_id`
 * da requisição. Sem tenant no contexto a resposta é negativa para todo mundo,
 * inclusive o superadmin: sem tenant definido não existe nada a autorizar
 * dentro da barbearia, e liberar aqui reabriria o bypass cross-tenant de S-01.
 * O acesso do superadmin às telas globais é problema do middleware
 * `superadmin`, não de um Gate tenant-scoped.
 */
class AuthorizationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(Gate $gate, PermissionChecker $permissions): void
    {
        foreach (Permission::cases() as $permission) {
            $gate->define(
                $permission->value,
                function (User $user) use ($permissions, $permission): bool {
                    $tenantId = app(TenantContext::class)->id();

                    // Sem tenant no contexto, nada é autorizado. Se o Gate fosse
                    // liberado aqui, valeria a role em *qualquer* membership do
                    // usuário — exatamente o bypass cross-tenant de S-01. O
                    // TenantResolver (Sprint 3) é quem passa a preencher o
                    // contexto antes deste ponto.
                    if ($tenantId === null) {
                        return false;
                    }

                    return $permissions->allows($user, $permission, $tenantId);
                },
            );
        }

        $gate->policy(Membership::class, MembershipPolicy::class);
        $gate->policy(Barber::class, BarberPolicy::class);
        $gate->policy(Customer::class, CustomerPolicy::class);
        $gate->policy(Service::class, ServicePolicy::class);
    }
}
