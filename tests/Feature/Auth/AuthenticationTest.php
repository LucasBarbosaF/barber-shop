<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

it('has no public registration route', function () {
    $this->get('/register')->assertNotFound();
    $this->post('/register', [
        'name' => 'Intruso',
        'email' => 'intruso@barber.test',
        'password' => 'Password123!',
        'password_confirmation' => 'Password123!',
    ])->assertNotFound();

    expect(User::query()->where('email', 'intruso@barber.test')->exists())->toBeFalse();
});

it('does not advertise a registration link on the public page', function () {
    $this->get('/')
        ->assertRedirect(route('login'));

    $this->get(route('login'))
        ->assertOk()
        ->assertSee('login-page', escape: false)
        ->assertSee('auth-login-shell', escape: false)
        ->assertSee('auth-login-card', escape: false)
        ->assertSee('name="email"', escape: false)
        ->assertSee('name="password"', escape: false)
        ->assertSee('data-toggle-password', escape: false)
        ->assertSee('Manter conectado')
        ->assertSee('Esqueceu a senha?')
        ->assertDontSee('Cadastre-se');
});

it('redirects authenticated users from the home page to the app', function () {
    $this->actingAs(User::factory()->create())
        ->get('/')
        ->assertRedirect(route('app.dashboard'));
});

it('logs a user in and out', function () {
    User::factory()->create([
        'email' => 'dono@barber.test',
        'password' => Hash::make('Password123!'),
    ]);

    $this->post('/login', [
        'email' => 'dono@barber.test',
        'password' => 'Password123!',
    ])->assertRedirect('/app');

    $this->assertAuthenticated();

    $this->post('/logout');

    $this->assertGuest();
});

it('rejects invalid login credentials', function () {
    User::factory()->create([
        'email' => 'admin@barber.test',
        'password' => Hash::make('Password123!'),
    ]);

    $this->from('/login')->post('/login', [
        'email' => 'admin@barber.test',
        'password' => 'wrong-password',
    ])->assertRedirect('/login');

    $this->get('/login')
        ->assertOk()
        ->assertSee('Não foi possível entrar.')
        ->assertSee('name="email"', escape: false);

    $this->assertGuest();
});

it('protects the app dashboard behind auth', function () {
    $this->get('/app')->assertRedirect('/login');

    $this->actingAs(User::factory()->create())
        ->get('/app')->assertOk();
});

it('redirects a user with a temporary password to the password setup', function () {
    $owner = User::factory()->mustChangePassword()->create();

    $this->actingAs($owner)->get('/app')->assertRedirect(route('password.setup'));

    $this->actingAs($owner)->get(route('password.setup'))->assertOk();
});

it('allows a user to replace the temporary password once', function () {
    $owner = User::factory()->mustChangePassword()->create([
        'password' => Hash::make('Provisoria123'),
    ]);

    $this->actingAs($owner)
        ->from(route('password.setup'))
        ->post(route('password.setup.store'), [
            'current_password' => 'Provisoria123',
            'password' => 'Definitiva123',
            'password_confirmation' => 'Definitiva123',
        ])
        ->assertRedirect('/app');

    expect($owner->refresh())
        ->must_change_password->toBeFalse()
        ->and(Hash::check('Definitiva123', $owner->password))->toBeTrue();

    $this->actingAs($owner)->get('/app')->assertOk();
});

it('keeps the temporary password when the current one is wrong', function () {
    $owner = User::factory()->mustChangePassword()->create([
        'password' => Hash::make('Provisoria123'),
    ]);

    $this->actingAs($owner)
        ->from(route('password.setup'))
        ->post(route('password.setup.store'), [
            'current_password' => 'errada',
            'password' => 'Definitiva123',
            'password_confirmation' => 'Definitiva123',
        ])
        ->assertSessionHasErrors('current_password');

    expect($owner->refresh()->must_change_password)->toBeTrue();
});
