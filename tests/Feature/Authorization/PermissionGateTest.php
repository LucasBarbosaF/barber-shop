<?php

use App\Application\Shared\Tenancy\TenantContext;
use App\Domain\Authorization\Enums\Permission;
use App\Domain\Authorization\RolePermissions;
use App\Domain\Tenant\Enums\MembershipRole;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/*
 * Cobertura da porta "Gate": a decisão por permission, resolvida contra o
 * tenant do TenantContext. A porta "Policy" está em MembershipPolicyTest.
 *
 * Referência: docs/sprint-1-authorization.md
 */

it('registers one gate per permission', function (Permission $permission): void {
    expect(array_keys(Gate::abilities()))->toContain($permission->value);
})->with(Permission::cases());

it('grants the permission to the role that owns it in the current tenant', function (Permission $permission): void {
    [$tenant, $user] = tenantWithUser(roleGranting($permission));

    actingInTenant($tenant);

    expect($user->can($permission->value))->toBeTrue();
})->with(Permission::cases());

it('denies the permission to a role that does not own it', function (Permission $permission): void {
    $denyingRole = roleDenying($permission);

    // Permissions de leitura compartilhadas pelas seis roles não têm negador:
    // não existe cenário de negação para testar.
    if ($denyingRole === null) {
        $this->markTestSkipped("Todas as roles concedem {$permission->value}.");
    }

    [$tenant, $user] = tenantWithUser($denyingRole);

    actingInTenant($tenant);

    expect($user->can($permission->value))->toBeFalse();
})->with(Permission::cases());

it('denies every permission to a guest', function (Permission $permission): void {
    expect(Gate::forUser(null)->allows($permission->value))->toBeFalse();
})->with(Permission::cases());

it('denies the permission to a superadmin without a membership in the tenant', function (Permission $permission): void {
    // O superadmin é global e não pertence a nenhuma barbearia. O acesso dele às
    // telas globais é do middleware `superadmin`; dentro de uma barbearia ele
    // responde pela membership, como qualquer outra pessoa.
    $superadmin = User::factory()->superadmin()->create();

    expect($superadmin->can($permission->value))->toBeFalse();
})->with(Permission::cases());

it('authorizes a superadmin that has a membership in the current tenant', function (Permission $permission): void {
    [$tenant, $superadmin] = tenantWithUser(roleGranting($permission));

    $superadmin->forceFill(['is_superadmin' => true])->save();

    actingInTenant($tenant);

    expect($superadmin->fresh()->can($permission->value))->toBeTrue();
})->with(Permission::cases());

it('denies every permission when there is no tenant in context', function (Permission $permission): void {
    [, $user] = tenantWithUser(MembershipRole::Admin);

    expect(app(TenantContext::class)->hasTenant())->toBeFalse();
    expect($user->can($permission->value))->toBeFalse();
})->with(Permission::cases());

it('denies every permission to a user without any membership', function (Permission $permission): void {
    $user = User::factory()->create();

    expect($user->can($permission->value))->toBeFalse();
})->with(Permission::cases());

it('denies the permission when the membership is revoked', function (Permission $permission): void {
    [$tenant, $user, $membership] = tenantWithUser(roleGranting($permission));

    actingInTenant($tenant);
    $membership->update(['is_active' => false]);

    expect($user->fresh()->can($permission->value))->toBeFalse();
})->with(Permission::cases());

it('denies the permission when the tenant is deactivated', function (Permission $permission): void {
    [$tenant, $user] = tenantWithUser(roleGranting($permission));

    actingInTenant($tenant);
    $tenant->update(['is_active' => false]);

    expect($user->fresh()->can($permission->value))->toBeFalse();
})->with(Permission::cases());

it('never authorizes a role that belongs to another tenant', function (Permission $permission): void {
    // admin na barbearia A, mas o contexto aponta para a barbearia B.
    [$tenantA, $adminOfA] = tenantWithUser(MembershipRole::Admin, ['name' => 'Barbearia A']);
    [$tenantB] = tenantWithUser(MembershipRole::Barber, ['name' => 'Barbearia B']);

    actingInTenant($tenantB);

    // O admin de A não concede nada em B: a membership dele não existe lá.
    expect($adminOfA->can($permission->value))->toBeFalse();

    // E continua valendo em A, para provar que não é um bloqueio global.
    actingInTenant($tenantA);

    expect($adminOfA->can($permission->value))->toBeTrue();
})->with(Permission::cases());

it('never trusts a tenant_id sent by the client', function (Permission $permission): void {
    // A recepção pertence à barbearia A, que é o tenant do contexto. A requisição
    // mente e manda o tenant_id de B: a decisão tem de continuar sendo a da role
    // da recepção em A, provando que o input da requisição não participa.
    [$tenantA, $receptionist] = tenantWithUser(MembershipRole::Receptionist, ['name' => 'Barbearia A']);
    [$tenantB] = tenantWithUser(MembershipRole::Barber, ['name' => 'Barbearia B']);

    actingInTenant($tenantA);

    $request = Request::create('/qualquer', 'GET', [
        'tenant_id' => (string) $tenantB->getKey(),
    ]);

    expect($request->query('tenant_id'))->toBe((string) $tenantB->getKey())
        // A resposta continua sendo a da role no tenant do contexto, e não a de
        // admin de B nem a de admin de A.
        ->and($receptionist->can($permission->value))->toBe(
            in_array($permission, RolePermissions::for(MembershipRole::Receptionist), true)
        );
})->with(Permission::cases());

it('ignores the cross tenant bypass helper', function (Permission $permission): void {
    // S-01: hasRoleInAnyTenant() responde "em algum tenant". Se alguém
    // autorizasse por ele, o admin de A passaria na checagem de B.
    [$tenantA, $adminOfA] = tenantWithUser(MembershipRole::Admin, ['name' => 'Barbearia A']);
    [$tenantB] = tenantWithUser(MembershipRole::Barber, ['name' => 'Barbearia B']);

    // `hasRoleInAnyTenant` lê `memberships`, então precisa do usuário declarado
    // no banco. É o que `SetUserDatabaseContext` faz em toda requisição real;
    // sem isso a policy de RLS devolve zero linhas e o helper responderia
    // "não é admin em lugar nenhum" — conclusão errada, mas derivada de um
    // contexto ausente, não da regra de negócio.
    actingAsUser($adminOfA);

    expect($adminOfA->hasRoleInAnyTenant([MembershipRole::Admin]))->toBeTrue();

    actingInTenant($tenantB);

    expect($adminOfA->can($permission->value))->toBeFalse();
})->with(Permission::cases());
