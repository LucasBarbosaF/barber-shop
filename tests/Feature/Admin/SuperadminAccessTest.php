<?php

use App\Domain\Tenant\Enums\MembershipRole;
use App\Domain\Tenant\Models\Tenant;
use App\Models\User;

/*
 * `/admin/*` é a única porta de entrada de barbearias no produto, então ela é
 * exclusiva do superadmin global.
 *
 * Note a diferença de papéis: o superadmin não passa por Gate tenant-scoped
 * (ver PermissionGateTest); aqui ele é autorizado pelo middleware `superadmin`,
 * que é a decisão certa para telas que existem fora de qualquer barbearia.
 */

it('sends a guest to login', function () {
    $this->get('/admin/barbearias')->assertRedirect('/login');
});

it('forbids a user without the superadmin flag', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/admin/barbearias')->assertForbidden();
});

it('forbids a tenant admin, who is not a global superadmin', function () {
    // Admin da própria barbearia: pode mexer na equipe, mas não cadastrar
    // barbearias novas.
    [$tenant, $admin] = tenantWithUser(MembershipRole::Admin);

    actingInTenant($tenant);

    $this->actingAs($admin)->get('/admin/barbearias')->assertForbidden();
});

it('forbids every admin route to a regular user', function (string $route): void {
    $this->actingAs(User::factory()->create())
        ->get($route)
        ->assertForbidden();
})->with([
    '/admin/barbearias',
    '/admin/barbearias/nova',
]);

it('refuses to register a barbershop without the superadmin flag', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->from('/admin/barbearias/nova')
        ->post('/admin/barbearias', [
            'name' => 'Barbearia Pirateada',
            'owner_name' => 'Pirata',
            'owner_email' => 'pirata@barber.test',
        ])
        ->assertForbidden();

    // Nenhuma barbearia foi criada.
    expect(Tenant::query()->where('name', 'Barbearia Pirateada')->exists())
        ->toBeFalse();
});

it('lets the superadmin reach the admin panel', function () {
    $superadmin = User::factory()->superadmin()->create();

    $this->actingAs($superadmin)->get('/admin/barbearias')->assertOk();
    $this->actingAs($superadmin)->get('/admin/barbearias/nova')->assertOk();
});

it('keeps a tenant admin away even with the superadmin flag on another account', function () {
    // Dois usuários: um admin de barbearia e um superadmin global. O segundo
    // não pode "vazar" acesso pelo primeiro.
    [$tenant, $admin] = tenantWithUser(MembershipRole::Admin);
    User::factory()->superadmin()->create();

    actingInTenant($tenant);

    $this->actingAs($admin)->get('/admin/barbearias')->assertForbidden();
});
