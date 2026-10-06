<?php

use App\Domain\Tenant\Enums\MembershipRole;
use App\Domain\Tenant\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

/**
 * @return array<string, string>
 */
function barbershopPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Barbearia do Zé',
        'document' => '12.345.678/0001-90',
        'email' => 'contato@zedbarber.test',
        'phone' => '(11) 99999-0000',
        'owner_name' => 'Zé da Silva',
        'owner_email' => 'ze@barber.test',
    ], $overrides);
}

it('redirects guests to login', function () {
    $this->get('/admin/barbearias')->assertRedirect('/login');
});

it('forbids non superadmins from managing barbershops', function () {
    $owner = User::factory()->create();

    $this->actingAs($owner)->get('/admin/barbearias')->assertForbidden();
    $this->actingAs($owner)->get('/admin/barbearias/nova')->assertForbidden();
    $this->actingAs($owner)->post('/admin/barbearias', barbershopPayload())->assertForbidden();

    expect(Tenant::query()->count())->toBe(0);
});

it('lets a superadmin reach the barbershop list', function () {
    $this->actingAs(User::factory()->superadmin()->create())
        ->get('/admin/barbearias')
        ->assertOk();
});

it('creates a barbershop together with its owner', function () {
    $this->actingAs(User::factory()->superadmin()->create())
        ->post('/admin/barbearias', barbershopPayload())
        ->assertRedirect('/admin/barbearias')
        ->assertSessionHas('created_password');

    $tenant = Tenant::query()->firstOrFail();

    expect($tenant->name)->toBe('Barbearia do Zé')
        ->and($tenant->slug)->toBe('barbearia-do-ze')
        ->and($tenant->document)->toBe('12.345.678/0001-90')
        ->and($tenant->phone)->toBe('(11) 99999-0000')
        ->and($tenant->is_active)->toBeTrue()
        ->and($tenant->trial_ends_at)->not->toBeNull();

    $admin = User::query()->where('email', 'ze@barber.test')->firstOrFail();

    expect($admin->name)->toBe('Zé da Silva')
        ->and($admin->is_superadmin)->toBeFalse()
        ->and($admin->must_change_password)->toBeTrue();

    $membership = $admin->memberships()->firstOrFail();

    expect($membership->tenant_id)->toBe($tenant->id)
        ->and($membership->role)->toBe(MembershipRole::Admin)
        ->and($membership->is_active)->toBeTrue();
});

it('shows the generated password only once', function () {
    $superadmin = User::factory()->superadmin()->create();

    $this->actingAs($superadmin)
        ->post('/admin/barbearias', barbershopPayload())
        ->assertRedirect('/admin/barbearias');

    $password = session('created_password');

    expect($password)->toBeString()->not->toBeEmpty();

    $admin = User::query()->where('email', 'ze@barber.test')->firstOrFail();

    expect(Hash::check($password, $admin->password))->toBeTrue()
        ->and($admin->password)->not->toBe($password);

    // D-01: a senha é hasheada uma única vez. A assinatura de um hash duplo
    // (bcrypt sobre bcrypt) é justamente o valor armazenado verificar contra
    // ele mesmo — aqui isso tem que ser falso.
    expect(Hash::check($admin->password, $admin->password))->toBeFalse();

    // O redirect imediatamente após o cadastro mostra a senha uma única vez.
    $this->get('/admin/barbearias')
        ->assertOk()
        ->assertSee('Barbearia do Zé')
        ->assertSee($password, escape: false);

    // Recarregar a listagem não a exibe mais.
    $this->get('/admin/barbearias')
        ->assertOk()
        ->assertDontSee($password, escape: false);
});

it('generates a unique slug when the name repeats', function () {
    $superadmin = User::factory()->superadmin()->create();

    $this->actingAs($superadmin)->post('/admin/barbearias', barbershopPayload());

    $this->actingAs($superadmin)->post('/admin/barbearias', barbershopPayload([
        'owner_email' => 'outro-ze@barber.test',
    ]));

    expect(Tenant::query()->pluck('slug')->all())
        ->toBe(['barbearia-do-ze', 'barbearia-do-ze-2']);
});

it('rejects a duplicate admin email without creating anything', function () {
    User::factory()->create(['email' => 'ze@barber.test']);

    $this->actingAs(User::factory()->superadmin()->create())
        ->from('/admin/barbearias/nova')
        ->post('/admin/barbearias', barbershopPayload())
        ->assertRedirect('/admin/barbearias/nova')
        ->assertSessionHasErrors('owner_email');

    expect(Tenant::query()->count())->toBe(0);
});

it('validates the required fields', function () {
    $this->actingAs(User::factory()->superadmin()->create())
        ->post('/admin/barbearias', [])
        ->assertSessionHasErrors(['name', 'owner_name', 'owner_email']);
});

it('lets the admin reach the dashboard after setting the password', function () {
    $this->actingAs(User::factory()->superadmin()->create())
        ->post('/admin/barbearias', barbershopPayload());

    $password = session('created_password');

    $this->post('/logout');

    $this->post('/login', [
        'email' => 'ze@barber.test',
        'password' => $password,
    ])->assertRedirect(route('password.setup'));

    $this->post(route('password.setup.store'), [
        'current_password' => $password,
        'password' => 'Definitiva123',
        'password_confirmation' => 'Definitiva123',
    ])->assertRedirect('/app');

    $this->get('/app')->assertOk()->assertDontSee('Cadastrar barbearia');
});
