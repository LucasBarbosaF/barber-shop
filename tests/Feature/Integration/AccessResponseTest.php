<?php

use App\Domain\Tenant\Enums\MembershipRole;
use App\Http\Responses\AccessDeniedResponse;
use App\Http\Responses\UnauthenticatedResponse;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;

/*
 * 401 e 403 padronizados.
 *
 * As duas respostas têm o mesmo formato JSON (`{"message": ...}`) de propósito:
 * um cliente que trata erro de autenticação e erro de autorização não deveria
 * precisar de dois parsers. E o HTML tem destino, não é uma página em branco.
 *
 * Cada caso é exercitado por um caminho de negação diferente — guest, Gate,
 * Policy, `tenant.selected` — porque a padronização só vale se alcança todos
 * eles. Um `abort(403)` num canto novo que monta a própria resposta quebra a
 * promessa sem quebrar nenhum teste destes.
 *
 * ## Onde o 403 virou 404, e por que isso é uma correção
 *
 * A Sprint 4 recusava 404 para id de outra barbearia porque "403 aqui, 404 ali"
 * reconstrói a base alheia id por id. O argumento segue certo; o 403 não
 * resolvia, porque o oráculo estava no par de respostas e não na tela.
 *
 * Com o RLS da Sprint 5, o registro alheio nunca chega ao binding e a resposta é
 * 404 — e o id que não existe dá o mesmo 404. O par deixou de existir. Os testes
 * abaixo que fixam isso comparam os dois lado a lado, justamente para que
 * reintroduzir o 403 pareça uma escolha e não um detalhe.
 */

describe('401', function () {
    it('manda o guest html para o login', function () {
        $this->get('/app/equipe')->assertRedirect(route('login'));
    });

    it('responde 401 com message em json', function () {
        $this->getJson('/app/equipe')
            ->assertUnauthorized()
            ->assertJsonStructure(['message'])
            ->assertJsonMissingPath('exception')
            ->assertJsonMissingPath('trace');
    });

    it('usa o mesmo envelope nas duas portas', function () {
        $html = $this->get('/app/equipe');
        $json = $this->getJson('/app/equipe');

        expect($html->getStatusCode())->toBe(302);
        expect($json->getStatusCode())->toBe(401);

        // O envelope JSON é o mesmo que o do 403: só o status muda.
        expect(array_keys($json->json()))->toBe(['message']);
    });

    it('trata /api/* como json mesmo sem accept explicito', function () {
        // O contrato do endpoint não depende do cliente lembrar do header.
        Route::middleware('auth')->get('/api/sem-header', fn () => response('ok'));

        $this->get('/api/sem-header')
            ->assertUnauthorized()
            ->assertJsonStructure(['message']);
    });
});

describe('403', function () {
    it('usa o mesmo envelope de mensagem do 401', function () {
        [, $barber] = tenantWithUser(MembershipRole::Barber);

        $this->actingAs($barber)
            ->getJson('/app/equipe')
            ->assertForbidden()
            ->assertJsonStructure(['message'])
            ->assertJsonMissingPath('exception');
    });

    it('leva a mensagem do ponto de decisao ate a tela', function () {
        [, $user, $membership] = tenantWithUser(MembershipRole::Barber);

        loginAsForResponses($user);

        // A mensagem vem de onde a regra foi avaliada. Um 403 genérico aqui
        // transformaria todo caso de permissão em "acesso negado", e quem está
        // na tela não teria o que fazer além de abrir chamado. O texto padrão
        // do framework, "This action is unauthorized", é exatamente esse caso.
        $this->get(route('app.team.show', $membership))
            ->assertForbidden()
            ->assertSee('Você não tem acesso a este recurso nesta barbearia.')
            ->assertDontSee('This action is unauthorized');
    });

    it('nao diz de qual barbearia e o registro recusado', function () {
        [$tenantA, $adminOfA] = tenantWithUser(MembershipRole::Admin);
        [$tenantB, , $membershipOfB] = tenantWithUser(MembershipRole::Barber);

        loginAsForResponses($adminOfA);
        $this->post('/app/barbearia', ['tenant_id' => $tenantA->getKey()]);

        /*
         * 404, e não o 403 que a Sprint 4 exigia aqui.
         *
         * A Sprint 4 recusava o 404 de propósito: "403 aqui, 404 ali" reconstrói
         * a base alheia id por id, porque quem enumera ids descobre que aquele
         * registro existe em outra barbearia.
         *
         * Sob RLS essa resposta deixa de ser escolha. O id chega pela rota e vira
         * route model binding; para a Policy ser chamada, o banco precisa enxergar
         * a linha — e ele só enxerga com `app.current_tenant` declarado, que é a
         * barbearia de quem está Asking. A linha de B nunca aparece, o binding
         * não acha e o `findOrFail` devolve 404 antes de qualquer código de
         * autorização rodar.
         *
         * O oráculo também não sobrou: agora **todo** id de outra barbearia dá
         * 404, exatamente como o id que não existe. Não há par de respostas para
         * comparar. Era o que a Sprint 4 queria; só que ele vinha da segunda
         * camada, e não da primeira.
         */
        $this->get(route('app.team.show', $membershipOfB))
            ->assertNotFound()
            ->assertDontSee($tenantB->name)
            ->assertDontSee('outra barbearia');

        // Um id que nunca existiu responde igual. Sem isso, a distinção acima é
        // só uma afirmação no comentário.
        $this->get(route('app.team.show', 999999))->assertNotFound();
    });

    it('distingue 401 de 403 para quem esta autenticado', function () {
        [, $user] = tenantWithUser(MembershipRole::Barber);

        loginAsForResponses($user);

        $this->get('/app/equipe')->assertForbidden();
    });

    it('nao deixa o superadmin global atravessar rota tenant-scoped', function () {
        $superadmin = User::factory()->superadmin()->create();

        loginAsForResponses($superadmin);

        $this->get('/app/equipe')->assertForbidden();
        $this->get('/admin/barbearias')->assertOk();
    });

    it('cobre o abort(403) do middleware de tenant nao escolhido', function () {
        [$tenantA] = tenantWithUser(MembershipRole::Admin);
        [, $user] = tenantWithUser(MembershipRole::Admin);

        // Duas barbearias: sem escolha, `EnsureTenantSelected` barra.
        joinsTenant($user, $tenantA, MembershipRole::Admin);

        loginAsForResponses($user);

        $this->get('/app/equipe')
            ->assertForbidden()
            ->assertSee('barbearia', escape: false);
    });

    it('nao deixa nenhum outro status passar pelo formatador do 403', function () {
        // O callback de `HttpExceptionInterface` só existe para o 403; um 404
        // continua 404 e não vira "sem permissão".
        Route::middleware('auth')->get('/erro-404', fn () => abort(404, 'Someu o recurso.'));

        $this->actingAs(User::factory()->create())
            ->get('/erro-404')
            ->assertNotFound();
    });
});

describe('as respostas isoladas', function () {
    it('a 401 escolhe a porta pelo perfil da requisicao', function () {
        expect(UnauthenticatedResponse::expectsJson(Request::create('/api/user')))->toBeTrue();
        expect(UnauthenticatedResponse::expectsJson(Request::create('/app/equipe')))->toBeFalse();
        expect(UnauthenticatedResponse::expectsJson(Request::create('/app', server: [
            'HTTP_ACCEPT' => 'application/json',
        ])))->toBeTrue();
    });

    it('a 403 nunca fica sem mensagem', function () {
        $request = Request::create('/app/equipe');
        $request->headers->set('Accept', 'application/json');

        $response = AccessDeniedResponse::for($request);

        expect($response->getStatusCode())->toBe(403)
            ->and($response->getData(true)['message'])->not->toBeEmpty();
    });

    it('a 403 em html devolve uma pagina, nao um corpo vazio', function () {
        $response = AccessDeniedResponse::for(Request::create('/app/equipe'), 'Você não tem permissão.');

        expect($response->getStatusCode())->toBe(403);

        $content = $response->getContent();

        expect($content)->toContain('Acesso negado')
            ->and($content)->toContain('Você não tem permissão.');
    });
});

/**
 * Login de verdade, para que o teste exercite o mesmo caminho do produto.
 */
function loginAsForResponses(User $user): void
{
    $password = 'Password123!';

    User::whereKey($user->getKey())->update(['password' => Hash::make($password)]);

    test()->post('/login', [
        'email' => $user->email,
        'password' => $password,
    ])->assertRedirect('/app');
}
