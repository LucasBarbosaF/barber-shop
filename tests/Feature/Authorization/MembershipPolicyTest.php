<?php

use App\Application\Shared\Tenancy\TenantContext;
use App\Domain\Tenant\Enums\MembershipRole;
use App\Domain\Tenant\Models\Membership;
use App\Domain\Tenant\Models\Tenant;
use App\Models\User;
use App\Policies\MembershipPolicy;
use Illuminate\Support\Facades\Gate;

/*
 * Cobertura da porta "Policy": a decisão sobre um membership específico.
 * A Policy sempre soma a checagem de tenant da BasePolicy ao Gate.
 *
 * Referência: docs/sprint-1-authorization.md
 */

it('registers the membership policy', function () {
    expect(Gate::getPolicyFor(Membership::class))->toBeInstanceOf(MembershipPolicy::class);
});

it('lets the admin list the team', function () {
    [$tenant, $admin] = tenantWithUser(MembershipRole::Admin);

    actingInTenant($tenant);

    expect($admin->can('viewAny', Membership::class))->toBeTrue();
});

it('denies listing the team to a barber', function () {
    [$tenant, $barber] = tenantWithUser(MembershipRole::Barber);

    actingInTenant($tenant);

    expect($barber->can('viewAny', Membership::class))->toBeFalse();
});

it('lets the admin change a role inside the tenant', function () {
    [$tenant, $admin] = tenantWithUser(MembershipRole::Admin);
    [, $membership] = addMemberTo($tenant, MembershipRole::Barber);

    actingInTenant($tenant);

    expect($admin->can('update', $membership))->toBeTrue();
});

it('denies changing a role to anyone but the admin', function (MembershipRole $role): void {
    [$tenant, $user] = tenantWithUser($role);
    [, $membership] = addMemberTo($tenant, MembershipRole::Barber);

    actingInTenant($tenant);

    // A role não tem `users.manage`, mesmo dentro da própria barbearia.
    expect($user->can('update', $membership))->toBeFalse();
})->with([
    MembershipRole::Manager,
    MembershipRole::Supervisor,
    MembershipRole::Barber,
    MembershipRole::Receptionist,
    MembershipRole::Financeiro,
]);

it('denies touching a membership from another tenant', function () {
    [$tenantA, $adminOfA] = tenantWithUser(MembershipRole::Admin);
    [, $strangerOfB] = tenantWithUser(MembershipRole::Barber);

    // A policy de SELECT libera a própria membership — é o que permite a
    // `ResolveTenant` listar as barbearias de quem logou —, então é o dono da
    // linha quem a enxerga. Para enxergar a linha alheia, o contexto teria de
    // ser o de outra pessoa, e é exatamente isso que o RLS impede.
    actingAsUser($strangerOfB);

    $membershipOfB = Membership::query()->where('user_id', $strangerOfB->id)->firstOrFail();

    // Contexto em A, membership de B: nem admin de A cruza a fronteira.
    actingInTenant($tenantA);

    expect($adminOfA->can('view', $membershipOfB))->toBeFalse()
        ->and($adminOfA->can('update', $membershipOfB))->toBeFalse()
        ->and($adminOfA->can('delete', $membershipOfB))->toBeFalse();
});

it('denies every action when no tenant is in context', function () {
    [, $admin, $membership] = tenantWithUser(MembershipRole::Admin);

    // O fixture `tenantWithUser()` entra na barbearia que acabou de criar; este
    // teste é sobre o estado oposto, então a saída é explícita.
    actingWithoutTenant();

    expect(app(TenantContext::class)->hasTenant())->toBeFalse();

    expect($admin->can('viewAny', Membership::class))->toBeFalse()
        ->and($admin->can('view', $membership))->toBeFalse()
        ->and($admin->can('update', $membership))->toBeFalse();
});

it('blocks the privilege escalation of the only admin', function () {
    [$tenant, $adminOfA, $membershipOfAdmin] = tenantWithUser(MembershipRole::Admin);

    actingInTenant($tenant);

    // Rebaixar o único admin deixaria a barbearia sem quem conceda roles.
    expect($adminOfA->can('update', $membershipOfAdmin))->toBeFalse();
});

it('allows demoting an admin when another one remains', function () {
    [$tenant, $adminOfA] = tenantWithUser(MembershipRole::Admin);
    [, $membershipOfSecondAdmin] = addMemberTo($tenant, MembershipRole::Admin);

    actingInTenant($tenant);

    expect($adminOfA->can('update', $membershipOfSecondAdmin))->toBeTrue();
});

it('allows revoking an inactive admin', function () {
    [$tenant, $adminOfA] = tenantWithUser(MembershipRole::Admin);
    [, $membershipOfExAdmin] = addMemberTo($tenant, MembershipRole::Admin);

    // Membership já revogada: não conta como admin para a trava do último admin,
    // mas continua sendo um registro que o admin pode apagar.
    $membershipOfExAdmin->update(['is_active' => false]);

    actingInTenant($tenant);

    expect($adminOfA->can('update', $membershipOfExAdmin->fresh()))->toBeTrue();
});

it('denies a revoked admin even inside its own tenant', function () {
    [$tenant, , $revokedMembership] = tenantWithUser(MembershipRole::Admin);
    $formerAdmin = $revokedMembership->user;

    // A revogação é escrita com o tenant no contexto: sob RLS, um UPDATE sem
    // `app.current_tenant` casa zero linhas e volta `true` sem alterar nada —
    // falha silenciosa, muito mais difícil de enxergar que uma exceção.
    actingInTenant($tenant);
    $revokedMembership->update(['is_active' => false]);

    expect($formerAdmin->fresh()->can('viewAny', Membership::class))->toBeFalse();
});

it('denies an inactive tenant to an active admin', function () {
    $tenant = Tenant::factory()->inactive()->create();
    $admin = User::factory()->create();

    actingInTenant($tenant);
    actingAsUser($admin);

    $membership = Membership::factory()->create([
        'user_id' => $admin->id,
        'tenant_id' => $tenant->id,
        'role' => MembershipRole::Admin,
    ]);

    expect($admin->can('viewAny', Membership::class))->toBeFalse()
        ->and($admin->can('view', $membership))->toBeFalse();
});
