<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;

/*
 * F-01: o discovery afirma que o login está sem rate limiting porque a chave
 * 'login' resolveria um limiter inexistente. Este arquivo é a prova de que a
 * afirmação está errada — o limiter é registrado em
 * FortifyServiceProvider::boot() e o login é limitado de verdade.
 */

function attemptLogin(string $email, string $password = 'errada', ?string $ip = null): TestResponse
{
    // O throttle key do Fortify é "e-mail|ip", então o ip precisa ser real.
    return test()->withServerVariables($ip === null ? [] : ['REMOTE_ADDR' => $ip])
        // Sem um "de onde" o Laravel redirecionaria para '/', e não daria para
        // distinguir "senha errada" de "bloqueado".
        ->from('/login')
        ->post('/login', ['email' => $email, 'password' => $password]);
}

it('registers the login rate limiter', function () {
    expect(RateLimiter::limiter('login'))->not->toBeNull();
});

it('answers 429 once the login is throttled', function () {
    User::factory()->create([
        'email' => 'alvo@barber.test',
        'password' => Hash::make('Password123!'),
    ]);

    for ($attempt = 1; $attempt <= 5; $attempt++) {
        attemptLogin('alvo@barber.test')
            ->assertRedirect('/login')
            ->assertSessionHasErrors('email');
    }

    // A sexta tentativa nem chega a conferir a senha.
    attemptLogin('alvo@barber.test')->assertStatus(429);

    $this->assertGuest();

    // E a senha correta também não entra enquanto o bloqueio estiver valendo.
    attemptLogin('alvo@barber.test', 'Password123!')->assertStatus(429);

    $this->assertGuest();
});

it('lets a different account in while another one is locked', function () {
    User::factory()->create([
        'email' => 'alvo@barber.test',
        'password' => Hash::make('Password123!'),
    ]);
    User::factory()->create([
        'email' => 'outro@barber.test',
        'password' => Hash::make('Password123!'),
    ]);

    for ($attempt = 1; $attempt <= 5; $attempt++) {
        attemptLogin('alvo@barber.test');
    }

    attemptLogin('alvo@barber.test')->assertStatus(429);

    // A chave do limiter inclui o e-mail: um bloqueio não derruba todo mundo.
    attemptLogin('outro@barber.test', 'Password123!')->assertRedirect('/app');

    $this->assertAuthenticated();
});

it('counts attempts per account and ip', function () {
    User::factory()->create([
        'email' => 'alvo@barber.test',
        'password' => Hash::make('Password123!'),
    ]);

    // Cinco tentativas vindas de um ip...
    for ($attempt = 1; $attempt <= 5; $attempt++) {
        attemptLogin('alvo@barber.test', 'errada', '10.0.0.1');
    }

    attemptLogin('alvo@barber.test', 'errada', '10.0.0.1')->assertStatus(429);

    // ...e a mesma senha correta a partir de outro ip ainda passa.
    attemptLogin('alvo@barber.test', 'Password123!', '10.0.0.2')->assertRedirect('/app');

    $this->assertAuthenticated();
});

it('does not reset the counter after a successful login', function () {
    User::factory()->create([
        'email' => 'alvo@barber.test',
        'password' => Hash::make('Password123!'),
    ]);

    for ($attempt = 1; $attempt <= 4; $attempt++) {
        attemptLogin('alvo@barber.test');
    }

    // A tentativa bem-sucedida também conta para o limite.
    attemptLogin('alvo@barber.test', 'Password123!')->assertRedirect('/app');

    $this->post('/logout');

    // Esta versão do Fortify não chama RateLimiter::clear() no sucesso: o
    // contador continua em 5 e a próxima tentativa já responde 429, mesmo
    // tendo sido a senha certa. O comportamento está documentado aqui em vez
    // de "corrigido" — zerar o contador no sucesso é decisão de produto
    // (destrava quem erra a senha uma vez), não uma correção óbvia.
    attemptLogin('alvo@barber.test', 'Password123!')->assertStatus(429);

    $this->assertGuest();
});
