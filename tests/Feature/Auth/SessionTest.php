<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;

/*
 * Sessão válida, inválida e expirada — o checklist da Sprint 1 pede as três.
 *
 * Os testes usam o driver `database`, o mesmo de produção, de propósito: com
 * `array` o estado da sessão fica num objeto em memória e nunca é relido, e
 * "sessão expirada" passaria sem provar nada.
 */

/**
 * Faz login de verdade (POST), para que exista uma sessão persistida.
 *
 * @return array{0: User, 1: string} usuário e o id da sessão criada
 */
function loginForSession(string $password = 'Password123!'): array
{
    $user = User::factory()->create(['password' => Hash::make($password)]);

    test()->post('/login', [
        'email' => $user->email,
        'password' => $password,
    ])->assertRedirect('/app');

    return [$user, session()->getId()];
}

/**
 * Faz o próximo request como se viesse de uma requisição nova do navegador.
 *
 * Três coisas precisam ser esquecidas, senão o container responde com o estado
 * em memória e a sessão nunca é lida do servidor:
 *
 *   1. os guards resolvidos (o usuário ficaria logado de memória);
 *   2. os drivers de sessão (`SessionManager::forgetDrivers()`);
 *   3. o singleton `session.store`, que guarda o `Store` já carregado.
 *
 * O cookie também precisa ser reenviado: o cliente de teste do Laravel não
 * repassa cookies de resposta.
 */
function requestWithSession(string $sessionId, string $url): TestResponse
{
    app('auth')->forgetGuards();
    app('session')->forgetDrivers();
    app()->forgetInstance('session.store');

    return test()
        ->withCookie((string) config('session.cookie'), $sessionId)
        ->get($url);
}

it('keeps a valid session authenticated across requests', function () {
    [$user, $sessionId] = loginForSession();

    expect(DB::table('sessions')->where('user_id', $user->id)->exists())->toBeTrue();

    requestWithSession($sessionId, '/app')->assertOk();

    $this->assertAuthenticatedAs($user);
});

it('rejects a session invalidated on the server', function () {
    [$user, $sessionId] = loginForSession();

    // Logout em outra aba, limpeza do storage ou rotação do id: o que importa é
    // que o servidor não reconhece mais aquela sessão.
    DB::table('sessions')->where('user_id', $user->id)->delete();

    requestWithSession($sessionId, '/app')->assertRedirect('/login');

    $this->assertGuest();
});

it('rejects an unknown session id', function () {
    // Cookie apontando para uma sessão que nunca existiu.
    requestWithSession('sessao-que-nunca-existiu', '/app')->assertRedirect('/login');

    $this->assertGuest();
});

it('rejects an expired session', function () {
    [$user, $sessionId] = loginForSession();

    DB::table('sessions')
        ->where('user_id', $user->id)
        ->update([
            'last_activity' => now()
                ->subMinutes((int) config('session.lifetime') + 1)
                ->getTimestamp(),
        ]);

    requestWithSession($sessionId, '/app')->assertRedirect('/login');

    $this->assertGuest();
});

it('keeps the session alive while it is within the lifetime', function () {
    [$user, $sessionId] = loginForSession();

    DB::table('sessions')
        ->where('user_id', $user->id)
        ->update(['last_activity' => now()->subMinute()->getTimestamp()]);

    requestWithSession($sessionId, '/app')->assertOk();
});

it('ends the session on logout', function () {
    [$user, $sessionId] = loginForSession();

    // Como um browser faria: reenviando o cookie da sessão. Sem o cookie o
    // logout esvaziaria outra sessão e a do login sobreviveria no servidor.
    test()
        ->withCookie((string) config('session.cookie'), $sessionId)
        ->post('/logout')
        ->assertRedirect('/');

    $this->assertGuest();

    // A sessão do login deixa de existir no servidor...
    expect(DB::table('sessions')->where('id', $sessionId)->exists())->toBeFalse();

    // ...e o cookie antigo não autentica mais ninguém.
    requestWithSession($sessionId, '/app')->assertRedirect('/login');
});
