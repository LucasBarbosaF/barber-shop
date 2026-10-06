<?php

use App\Application\Shared\Tenancy\TenantResolution;
use App\Domain\Tenant\Enums\MembershipRole;
use App\Domain\Tenant\Models\Membership;
use App\Domain\Tenant\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;

/*
 * Sprint 4 — a cadeia inteira, ponta a ponta.
 *
 * As sprints anteriores provaram cada peça isoladamente: o Gate responde
 * certo, a Policy filtra o recurso certo, o TenantResolver escolhe a
 * barbearia. Este arquivo é o que fecha a promessa — que a ordem
 * sessão → tenant → RBAC → recurso funciona quando as peças estão ligadas.
 *
 * A diferença em relação aos testes por unidade não é cobertura, é
 * superfície: aqui não se injeta o TenantContext, ele é preenchido pelo
 * middleware a partir da sessão. Um teste que passa com `actingInTenant()`
 * e falha aqui está com o bug no wiring, que é justamente o que a Sprint 4
 * toca.
 *
 * Nenhuma linha aqui monta sessão à mão: o login passa pelo Fortify, para
 * que o caminho testado seja o caminho real.
 */

/**
 * Cria a pessoa e a deixa autenticada de verdade, via POST /login.
 *
 * O `password.set` das rotas exige senha fora do estado provisório, então a
 * senha precisa estar hasheada antes do POST — não depois.
 */
function loginAs(User $user): User
{
    $password = 'Password123!';

    User::whereKey($user->getKey())->update(['password' => Hash::make($password)]);

    $user->forceFill(['password' => Hash::make($password)])->save();

    test()->post('/login', [
        'email' => $user->email,
        'password' => $password,
    ])->assertRedirect('/app');

    return $user;
}

describe('autenticacao por sessao, sem JWT', function () {
    it('mantem a pessoa autenticada entre requisicoes, sem token algum', function () {
        [$tenant, $user] = tenantWithUser(MembershipRole::Admin);

        loginAs($user);

        $this->get('/app')->assertOk();
        $this->assertAuthenticatedAs($user);

        // Nenhuma resposta trouxe token. A sessão é o portador da identidade:
        // o que atravessa a rede é o id de sessão, no cookie.
        $this->get('/app')->assertOk()->assertHeaderMissing('authorization');
    });

    it('recusa quem nao tem sessao, com 401 em json e login em html', function () {
        $this->getJson('/app/equipe')
            ->assertUnauthorized()
            ->assertJsonStructure(['message']);

        $this->get('/app/equipe')
            ->assertRedirect('/login');
    });

    it('destroi a sessao no logout', function () {
        [, $user] = tenantWithUser(MembershipRole::Admin);

        loginAs($user);

        $this->post('/logout')->assertRedirect('/');

        $this->assertGuest();
        $this->get('/app')->assertRedirect('/login');
    });

    it('nao resolve tenant em requisicao sem sessao', function () {
        /*
         * O ponto do grupo `authenticated`: ele é sessão + tenant, nessa
         * ordem. Um guard de token não tem `current_tenant_id`, e resolver
         * mesmo assim produziria um "escolha uma barbearia" falso em toda
         * chamada stateless.
         *
         * A rota de teste não usa o grupo de propósito — é uma requisição
         * sem `StartSession`, como a de uma API com token.
         */
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create();
        joinsTenant($user, $tenant, MembershipRole::Admin);

        $captured = null;

        Route::middleware(['auth', 'authenticated'])
            ->get('/teste-sem-sessao', function (Request $request) use (&$captured) {
                $captured = $request->attributes->get(
                    TenantResolution::ATTRIBUTE,
                );

                return response('ok');
            });

        $this->actingAs($user)->get('/teste-sem-sessao')->assertOk();

        expect($captured)->toBeNull();
    });
});

describe('usuario com uma unica barbearia', function () {
    it('entra direto, sem passar por tela de escolha', function () {
        [$tenant, $user] = tenantWithUser(MembershipRole::Admin);

        loginAs($user);

        $this->get('/app')->assertOk();
        $this->assertSame((string) $tenant->getKey(), session('current_tenant_id'));
    });

    it('abre a equipe da propria barbearia', function () {
        [$tenant, $user] = tenantWithUser(MembershipRole::Admin);

        loginAs($user);

        $this->get('/app/equipe')->assertOk();
    });
});

describe('usuario com varias barbearias', function () {
    /**
     * A mesma pessoa em três barbearias, com roles diferentes — é este o
     * cenário em que "admin" deixa de ser um rótulo e vira uma afirmação
     * sobre a barbearia onde se está.
     */
    function personInThreeBarbershops(): array
    {
        // A ordem de `tenantWithUser()` é [$tenant, $user, $membership]:
        // pular a *pessoa* é `[$tenant, , $membership]`, não `[, ...]`. Pular o
        // elemento errado entrega um User no lugar da Barbearia, e o erro só
        // aparece quando os ids das duas tabelas divergem — o que faz o teste
        // passar sozinho e falhar na suíte inteira.
        [$adminTenant, $user, $adminMembership] = tenantWithUser(MembershipRole::Admin);
        [$barberTenant, , $barberMembership] = tenantWithUser(MembershipRole::Barber);
        [$otherTenant, , $otherMembership] = tenantWithUser(MembershipRole::Barber);

        foreach ([$barberTenant, $otherTenant] as $tenant) {
            joinsTenant($user, $tenant, MembershipRole::Barber);
        }

        return [$user, $adminTenant, $barberTenant, $adminMembership, $barberMembership, $otherMembership];
    }

    it('nao escolhe sozinho: mais de uma barbearia exige escolha', function () {
        $user = personInThreeBarbershops()[0];

        loginAs($user);

        $this->get('/app')->assertOk();

        // O middleware resolve, mas não inventa autorização. Sem escolha, o
        // contexto fica vazio e a rota que exige tenant barra.
        expect(session('current_tenant_id'))->toBeNull();

        $this->get('/app/equipe')->assertForbidden();
    });

    it('abre a equipe depois que a escolha e feita', function () {
        [$user, $adminTenant] = personInThreeBarbershops();

        loginAs($user);

        $this->post('/app/barbearia', ['tenant_id' => $adminTenant->getKey()])
            ->assertRedirect('/app');

        $this->get('/app/equipe')->assertOk();
    });

    it('valida a role dentro do tenant escolhido, nao a role de outra barbearia', function () {
        /*
         * A pessoa é admin em A e barbeiro em B. Ao escolher B, a rota de
         * equipe tem de negar: não existe atalho por "em algum lugar ela é
         * admin".
         */
        [$user, $adminTenant, $barberTenant, $adminMembership] = personInThreeBarbershops();

        loginAs($user);

        // Em A, admin: abre.
        $this->post('/app/barbearia', ['tenant_id' => $adminTenant->getKey()]);
        $this->get('/app/equipe')->assertOk()->assertSee($adminMembership->id);

        // Em B, só barbeiro: a mesma rota nega.
        $this->post('/app/barbearia', ['tenant_id' => $barberTenant->getKey()]);
        $this->get('/app/equipe')->assertForbidden();
    });

    it('recusa trocar para uma barbearia de quem nao participa', function () {
        [$user] = personInThreeBarbershops();
        [$strangerTenant] = tenantWithUser(MembershipRole::Admin);

        loginAs($user);

        $this->post('/app/barbearia', ['tenant_id' => $strangerTenant->getKey()])
            ->assertForbidden();

        expect(session('current_tenant_id'))->toBeNull();
    });

    it('mantem a escolha antiga quando a membership foi revogada', function () {
        /*
         * O `current_tenant_id` foi gravado na sessão. Se a membership cair,
         * a sessão deixa de ser acreditada e o contexto é descartado — sem
         * isso, bastaria plantar o id para escrever na barbearia alheia.
         */
        [$tenant, $user, $membership] = tenantWithUser(MembershipRole::Admin);

        loginAs($user);
        $this->post('/app/barbearia', ['tenant_id' => $tenant->getKey()]);

        revokingMembership($membership);

        $this->get('/app/equipe')->assertForbidden();

        expect(session('current_tenant_id'))->toBeNull();
    });
});

describe('usuario sem membership', function () {
    it('entra, mas nao tem nada de barbearia para ver', function () {
        $user = loginAs(User::factory()->create());

        $this->get('/app')->assertOk();

        // "Nenhuma barbearia" é estado legítimo de navegação: a pessoa está
        // autenticada e autenticada sem nenhum vínculo. O que não pode é
        // aparecer dado de barbearia.
        $this->get('/app/equipe')->assertForbidden();
        $this->assertSame(0, Membership::query()->count());
    });

    it('nao ganha nada por ser superadmin', function () {
        /*
         * O superadmin é global e não pertence a barbearia nenhuma. Sem
         * tenant no contexto o Gate nega para todo mundo, inclusive ele:
         * liberar aqui reabriria o bypass cross-tenant.
         */
        $superadmin = loginAs(User::factory()->superadmin()->create());

        $this->get('/app/equipe')->assertForbidden();

        // O painel do superadmin, por outro lado, abre.
        $this->get('/admin/barbearias')->assertOk();

        expect($superadmin->is_superadmin)->toBeTrue();
    });
});

describe('acesso ao recurso', function () {
    it('abre um membro da propria equipe', function () {
        [$tenant, $admin] = tenantWithUser(MembershipRole::Admin);
        [, $colleagueMembership] = addMemberTo($tenant, MembershipRole::Barber);

        loginAs($admin);

        $this->get(route('app.team.show', $colleagueMembership))
            ->assertOk()
            ->assertSee($colleagueMembership->user->name);
    });

    it('recusa o membro de outra barbearia, mesmo com a permissao', function () {
        /*
         * O caso que a segunda camada fecha. A pessoa tem `users.view` em A e
         * tenta o id de um vínculo em B.
         *
         * Antes do RLS a resposta era 403, e a `MembershipPolicy` recusava. Agora
         * é 404, e a recusa acontece um passo antes: o id vira route model
         * binding, o banco não devolve a linha de B porque `app.current_tenant`
         * é A, e o `findOrFail` não acha. A Policy nem chega a ser consultada.
         *
         * A diferença importa para quem tenta: 403 onde existe e 404 onde não
         * existe é oráculo de existência, e a Sprint 4 já apontava isso. Com RLS
         * os dois dão 404, então enumerar ids não rende nada. O teste abaixo
         * fixa o par — o id de B e um id inventado têm de responder igual.
         */
        [$tenantA, $adminOfA] = tenantWithUser(MembershipRole::Admin);
        [$tenantB, , $membershipOfB] = tenantWithUser(MembershipRole::Barber);

        loginAs($adminOfA);

        $this->post('/app/barbearia', ['tenant_id' => $tenantA->getKey()]);

        $this->get(route('app.team.show', $membershipOfB))
            ->assertNotFound()
            ->assertDontSee($membershipOfB->user->name);

        $this->get(route('app.team.show', 999999))->assertNotFound();
    });

    it('recusa quando a pessoa nao tem a permissao, mesmo no proprio tenant', function () {
        [$tenant, $barber, $ownMembership] = tenantWithUser(MembershipRole::Barber);

        loginAs($barber);

        $this->post('/app/barbearia', ['tenant_id' => $tenant->getKey()]);

        /*
         * Este é o 403 que sobrevive ao RLS, e é ele que prova que a Policy ainda
         * trabalha: a membership é da **própria** barbearia, então o banco a
         * devolve e o binding monta o modelo. O que nega é a falta de
         * `users.view`.
         *
         * A policy de RLS conhece tenant, não permissão — se ela recusasse aqui,
         * trocar de `users.view` por `customers.update` na barra da equipe não
         * teria efeito. O RLS diz de quem é a linha; a Policy diz o que se pode
         * fazer com ela.
         */
        $this->get(route('app.team.show', $ownMembership))->assertForbidden();
    });

    it('exige sessao e tenant escolhido para ler o recurso', function () {
        [$tenant, , $membership] = tenantWithUser(MembershipRole::Admin);

        $this->get(route('app.team.show', $membership))->assertRedirect('/login');

        /*
         * Autenticado sem barbearia escolhida: 403, e não o 404 que a binding daria
         * por si só.
         *
         * Sem contexto, o RLS esconderia a linha e o `findOrFail` responderia 404
         * — o que seria tecnicamente seguro e completamente inútil: "não encontrei"
         * sugere que o id não existe, quando o que falta é a pessoa escolher uma
         * barbearia. `EnsureTenantSelected` está na prioridade de middleware
         * antes de `SubstituteBindings` por causa disso.
         *
         * A ordem importa nos dois sentidos: antes de `EnsureTenantSelected` viria
         * `EnsurePermission`, que leria o Gate sem contexto e recusaria com "Você
         * não tem permissão para esta ação" — mandando a pessoa caçar uma
         * permissão que ela tem.
         */
        $this->actingAs(User::factory()->create())
            ->get(route('app.team.show', $membership))
            ->assertForbidden()
            ->assertSee('barbearia', escape: false)
            ->assertDontSee('não tem permissão', escape: false);
    });
});
