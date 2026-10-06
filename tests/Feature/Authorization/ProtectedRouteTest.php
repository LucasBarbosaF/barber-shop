<?php

use App\Domain\Tenant\Enums\MembershipRole;
use App\Domain\Tenant\Models\Tenant;
use App\Http\Middleware\EnsurePermission;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/*
 * Cobertura da porta HTTP: a rota /app/equipe é guardada por
 * `permission:users.view`, então ela exercita middleware + Gate + Policy +
 * TenantContext juntos.
 */

it('sends a guest to login', function () {
    $this->get('/app/equipe')->assertRedirect('/login');
});

it('answers 401 to an unauthenticated json request', function () {
    $this->getJson('/app/equipe')->assertUnauthorized();
});

it('forbids an authenticated user without the permission', function () {
    [, $barber] = tenantWithUser(MembershipRole::Barber);

    $this->actingAs($barber)->get('/app/equipe')->assertForbidden();
});

it('forbids a user without any membership', function () {
    $this->actingAs(User::factory()->create())
        ->get('/app/equipe')
        ->assertForbidden();
});

it('lets a role with the permission through', function (MembershipRole $role): void {
    [$tenant, $user] = tenantWithUser($role);

    actingInTenant($tenant);

    $this->actingAs($user)->get('/app/equipe')->assertOk();
})->with([
    MembershipRole::Admin,
    MembershipRole::Manager,
    MembershipRole::Supervisor,
]);

it('lists only the members of the current tenant', function () {
    [$tenantA, $adminOfA, $colleagueOfA] = tenantWithUser(MembershipRole::Admin);
    [$tenantB, $strangerOfB] = tenantWithUser(MembershipRole::Barber);

    actingInTenant($tenantA);

    $this->actingAs($adminOfA)
        ->get('/app/equipe')
        ->assertOk()
        ->assertSee($colleagueOfA->name)
        ->assertDontSee($strangerOfB->name);
});

it('ignores a tenant_id injected in the request', function () {
    [$tenantA, $adminOfA] = tenantWithUser(MembershipRole::Admin);
    [$tenantB, $strangerOfB] = tenantWithUser(MembershipRole::Barber);

    // Contexto em A, mas a requisição insiste em B.
    actingInTenant($tenantA);

    $this->actingAs($adminOfA)
        ->get('/app/equipe?tenant_id='.$tenantB->getKey())
        ->assertOk()
        ->assertDontSee($strangerOfB->name);
});

it('denies a superadmin the tenant scoped route without a tenant', function () {
    // O superadmin cadastra barbearias, mas não pertence a nenhuma: sem
    // TenantContext ele não tem o que ver em /app/equipe.
    $superadmin = User::factory()->superadmin()->create();

    $this->actingAs($superadmin)->get('/app/equipe')->assertForbidden();
});

it('lets a superadmin through when it is a member of the selected tenant', function () {
    // Superadmin global não passa por Gate tenant-scoped. Se ele também for
    // admin daquela barbearia, quem o autoriza é a membership, como qualquer
    // outra pessoa — e aí a rota abre.
    [$tenant, $adminOfA] = tenantWithUser(MembershipRole::Admin);
    $superadmin = User::factory()->superadmin()->create();

    // A membership do superadmin nasce como qualquer outra: escrita por quem tem
    // o tenant no contexto. O RLS não dá passe livre a INSERT de linha nova,
    // nem para superadmin — ele ganha acesso às linhas *já* existentes.
    creatingMembershipIn($tenant, $superadmin, MembershipRole::Admin);

    actingInTenant($tenant);

    $this->actingAs($superadmin)->get('/app/equipe')->assertOk()->assertSee($adminOfA->name);
});

it('fails loudly when a route asks for a permission that does not exist', function () {
    // Um alias de rota com permission inválida é erro de configuração.
    // Falhar alto é melhor do que liberar a rota silenciosamente.
    actingInTenant(Tenant::factory()->create());

    $request = Request::create('/rota-quebrada');
    $request->setUserResolver(fn () => User::factory()->superadmin()->create());

    $middleware = new EnsurePermission;

    expect(fn () => $middleware->handle($request, fn () => new Response('ok'), 'nao.existe'))
        ->toThrow(HttpException::class);
});
