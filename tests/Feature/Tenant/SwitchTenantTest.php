<?php

use App\Application\Shared\Tenancy\ResolutionStatus;
use App\Application\Shared\Tenancy\TenantContext;
use App\Application\Shared\Tenancy\TenantResolution;
use App\Domain\Tenant\Enums\MembershipRole;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/**
 * A troca de tenant pela rota real, e o efeito do middleware `tenant` nas
 * demais telas.
 */
describe('resolucao automatica nas rotas', function () {
    it('abre a tela da equipe ja na unica barbearia da pessoa', function () {
        [$tenant, $user, $membership] = tenantWithUser(MembershipRole::Admin);

        $this->actingAs($user)
            ->get(route('app.team.index'))
            ->assertOk();

        expect(app(TenantContext::class)->id())->toBe((string) $tenant->id);
    });

    it('nega a tela da equipe quando a pessoa ainda nao escolheu', function () {
        [$tenantA] = tenantWithUser();
        [$tenantB] = tenantWithUser();

        $user = User::factory()->create();
        joinsTenant($user, $tenantA, MembershipRole::Admin);
        joinsTenant($user, $tenantB, MembershipRole::Admin);

        $this->actingAs($user)
            ->get(route('app.team.index'))
            ->assertForbidden();

        // O dashboard continua acessível: ele é onde a escolha vai acontecer.
        $this->actingAs($user)
            ->get(route('app.dashboard'))
            ->assertOk();

        expect(app(TenantContext::class)->id())->toBeNull();
    });

    it('recusa tela tenant-scoped para quem nao pertence a nenhuma barbearia', function () {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('app.team.index'))
            ->assertForbidden();
    });

    it('ignora tenant_id enviado na query string', function () {
        [$tenantDaPessoa, $user] = tenantWithUser(MembershipRole::Admin);
        [$tenantAlheio] = tenantWithUser();

        $this->actingAs($user)
            ->get(route('app.team.index', ['tenant_id' => $tenantAlheio->id]))
            ->assertOk();

        expect(app(TenantContext::class)->id())->toBe((string) $tenantDaPessoa->id);
    });
});

describe('troca de barbearia pela rota', function () {
    it('troca para uma barbearia da propria pessoa', function () {
        [$tenantA] = tenantWithUser();
        [$tenantB] = tenantWithUser();

        $user = User::factory()->create();
        joinsTenant($user, $tenantA, MembershipRole::Admin);
        joinsTenant($user, $tenantB, MembershipRole::Admin);

        $this->actingAs($user)
            ->post(route('app.tenant.switch'), ['tenant_id' => $tenantB->id])
            ->assertRedirect(route('app.dashboard'));

        expect(app(TenantContext::class)->id())->toBe((string) $tenantB->id);
    });

    it('recusa trocar para barbearia alheia', function () {
        [$tenantDaPessoa, $user, $membership] = tenantWithUser(MembershipRole::Admin);
        [$tenantAlheio] = tenantWithUser();

        $this->actingAs($user)
            ->post(route('app.tenant.switch'), ['tenant_id' => $tenantAlheio->id])
            ->assertForbidden();

        expect(app(TenantContext::class)->id())->toBe((string) $tenantDaPessoa->id);
    });

    it('recusa trocar para barbearia desativada', function () {
        [$tenantDesativada, $user] = tenantWithUser(MembershipRole::Admin);
        $tenantDesativada->update(['is_active' => false]);

        $this->actingAs($user)
            ->post(route('app.tenant.switch'), ['tenant_id' => $tenantDesativada->id])
            ->assertForbidden();
    });

    it('recusa trocar com membership revogada', function () {
        [$tenant, $user, $membership] = tenantWithUser(MembershipRole::Admin);

        // A revogação é escrita dentro do tenant: fora dele o UPDATE casaria zero
        // linhas e voltaria `true` sem revogar nada.
        actingInTenant($tenant);
        $membership->update(['is_active' => false]);

        $this->actingAs($user)
            ->post(route('app.tenant.switch'), ['tenant_id' => $tenant->id])
            ->assertForbidden();
    });

    it('recusa superadmin sem membership', function () {
        [$tenant] = tenantWithUser();
        $superadmin = User::factory()->superadmin()->create();

        $this->actingAs($superadmin)
            ->post(route('app.tenant.switch'), ['tenant_id' => $tenant->id])
            ->assertForbidden();

        expect(app(TenantContext::class)->id())->toBeNull();
    });

    it('valida o campo antes de consultar barbearia', function () {
        [, $user] = tenantWithUser(MembershipRole::Admin);

        $this->actingAs($user)
            ->post(route('app.tenant.switch'))
            ->assertSessionHasErrors('tenant_id');

        $this->actingAs($user)
            ->post(route('app.tenant.switch'), ['tenant_id' => '999999'])
            ->assertSessionHasErrors('tenant_id');

        $this->actingAs($user)
            ->post(route('app.tenant.switch'), ['tenant_id' => 'abc'])
            ->assertSessionHasErrors('tenant_id');
    });

    it('exige autenticacao', function () {
        [$tenant] = tenantWithUser();

        $this->post(route('app.tenant.switch'), ['tenant_id' => $tenant->id])
            ->assertRedirect(route('login'));
    });

    it('regenera a sessao ao trocar de contexto de autorizacao', function () {
        [$tenantA] = tenantWithUser();
        [$tenantB] = tenantWithUser();

        $user = User::factory()->create();
        joinsTenant($user, $tenantA, MembershipRole::Admin);
        joinsTenant($user, $tenantB, MembershipRole::Admin);

        $this->actingAs($user);

        $before = session()->getId();

        $this->post(route('app.tenant.switch'), ['tenant_id' => $tenantB->id])
            ->assertRedirect(route('app.dashboard'));

        expect(session()->getId())->not->toBe($before);
    });

    it('mantem a pessoa autenticada depois da troca', function () {
        [$tenantA] = tenantWithUser();
        [$tenantB] = tenantWithUser();

        $user = User::factory()->create();
        joinsTenant($user, $tenantA, MembershipRole::Admin);
        joinsTenant($user, $tenantB, MembershipRole::Admin);

        $this->actingAs($user)
            ->post(route('app.tenant.switch'), ['tenant_id' => $tenantB->id]);

        $this->get(route('app.team.index'))
            ->assertOk()
            ->assertViewHas('memberships', fn ($paginator): bool => true);
    });
});

describe('contexto nao sobrevive a requisicao', function () {
    it('nao vaza tenant em memoria entre requisicoes', function () {
        [$tenantA, $user] = tenantWithUser(MembershipRole::Admin);
        [$tenantB] = tenantWithUser(MembershipRole::Admin);

        $this->actingAs($user)->get(route('app.dashboard'))->assertOk();

        expect(app(TenantContext::class)->id())->toBe((string) $tenantA->id);

        // A sessao guardou o tenant; a instancia em memoria foi descartada no
        // terminate do middleware, como em um worker de longa duracao.
        expect(session('current_tenant_id'))->toBe((string) $tenantA->id);
        expect($tenantB->id)->not->toBe($tenantA->id);
    });

    it('esquece a instancia do contexto ao fim da requisicao', function () {
        [$tenant, $user] = tenantWithUser(MembershipRole::Admin);

        $before = app(TenantContext::class);

        $this->actingAs($user)->get(route('app.dashboard'))->assertOk();

        // O `terminate` do middleware descarta a instância singleton: em worker de
        // longa duração o valor em memória não pode vazar para a requisição
        // seguinte. O que sobrevive é a sessão, que é o estado legítimo.
        expect(app(TenantContext::class))->not->toBe($before);
        expect(app(TenantContext::class)->id())->toBe((string) $tenant->id);
    });
});

it('a resolucao fica na request para a tela de selecao', function () {
    [$tenantA] = tenantWithUser();
    [$tenantB] = tenantWithUser();

    $user = User::factory()->create();
    joinsTenant($user, $tenantA);
    joinsTenant($user, $tenantB);

    $captured = null;

    // `web` entra na lista porque esta rota é registrada aqui, e não em
    // `routes/web.php`: sem o grupo não há `StartSession`, e a resolução — que
    // é sessão — corretamente não acontece. É a mesma razão pela qual
    // `ResolveTenant` não roda em requisição sem sessão.
    //
    // `authenticated` é o grupo da Sprint 4 (sessão + tenant resolvido), o
    // mesmo que as rotas de `routes/web.php` usam.
    Route::middleware(['web', 'auth', 'password.set', 'authenticated'])
        ->get('/teste-resolucao', function (Request $request) use (&$captured) {
            $captured = $request->attributes->get(TenantResolution::ATTRIBUTE);

            return response('ok');
        });

    $this->actingAs($user)->get('/teste-resolucao')->assertOk();

    expect($captured)->toBeInstanceOf(TenantResolution::class);
    expect($captured->status)->toBe(ResolutionStatus::SelectionRequired);
    expect($captured->availableTenantIds)->toBe([
        (string) $tenantA->id,
        (string) $tenantB->id,
    ]);
});
