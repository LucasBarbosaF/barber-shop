<?php

use App\Domain\Tenant\Enums\MembershipRole;
use App\Domain\Tenant\Models\Membership;
use App\Models\Barber;
use App\Models\Service;
use App\Models\User;

describe('gerenciamento da equipe', function () {
    it('cria conta, membership e perfil de barbeiro vinculado', function () {
        [$tenant, $admin] = tenantWithUser(MembershipRole::Admin);
        actingInTenant($tenant);
        $service = Service::factory()->create([
            'tenant_id' => $tenant->getKey(),
            'name' => 'Corte de cabelo',
        ]);
        actingWithoutTenant();
        $client = $this->withSession(['current_tenant_id' => (string) $tenant->getKey()])
            ->actingAs($admin);

        $client->get(route('app.team.create'))
            ->assertOk()
            ->assertSee('Adicionar à equipe')
            ->assertSee('value="barber"', escape: false);

        $client->get(route('app.team.create', ['role' => 'barber']))
            ->assertOk()
            ->assertSee('Cadastrar barbeiro na equipe')
            ->assertSee('value="barber" selected', escape: false)
            ->assertSee('Cadastrar barbeiro e criar acesso')
            ->assertSee('Corte de cabelo')
            ->assertSee('name="commission_percentage"', escape: false)
            ->assertSee('Expediente semanal')
            ->assertSee('business_hours[1][opens_at]', escape: false);

        $client->post(route('app.team.store'), [
            'name' => 'Barbeiro novo',
            'email' => 'barbeiro.novo@example.test',
            'role' => MembershipRole::Barber->value,
            'phone' => '(11) 99210-8613',
            'service_ids' => [$service->getKey()],
            'commission_percentage' => '35.50',
            'business_hours' => [
                1 => ['opens_at' => '09:00', 'closes_at' => '18:00'],
            ],
        ])
            ->assertRedirect(route('app.team.index'))
            ->assertSessionHas('team_member_password')
            ->assertSessionHas('team_member_email', 'barbeiro.novo@example.test');

        $user = User::query()->where('email', 'barbeiro.novo@example.test')->firstOrFail();
        actingInTenant($tenant);
        $membership = Membership::query()->where('user_id', $user->getKey())->firstOrFail();
        $barber = Barber::query()->where('user_id', $user->getKey())->firstOrFail();

        expect($membership->role)->toBe(MembershipRole::Barber)
            ->and($membership->is_active)->toBeTrue()
            ->and($user->must_change_password)->toBeTrue()
            ->and($barber->name)->toBe('Barbeiro novo')
            ->and($barber->phone)->toBe('11992108613')
            ->and($barber->is_active)->toBeTrue()
            ->and($barber->commission_percentage)->toBe('35.50')
            ->and($barber->businessHours()->where('weekday', 1)->value('opens_at'))->toStartWith('09:00')
            ->and($barber->businessHours()->where('weekday', 1)->value('closes_at'))->toStartWith('18:00')
            ->and($barber->services()->pluck('services.id')->all())->toBe([$service->getKey()]);

        actingWithoutTenant();
        $client->get(route('app.team.index'))
            ->assertOk()
            ->assertSee('Barbeiro novo')
            ->assertSee('Editar perfil profissional')
            ->assertSee('Revisar perfil profissional')
            ->assertSee(route('app.barbers.edit', $barber), escape: false);

        $client->get(route('app.barbers.index'))
            ->assertOk()
            ->assertSee('barbeiro.novo@example.test')
            ->assertSee('Corte de cabelo');
    });

    it('recusa criar membro sem telefone válido para barbeiro', function () {
        [$tenant, $admin] = tenantWithUser(MembershipRole::Admin);

        $this->withSession(['current_tenant_id' => (string) $tenant->getKey()])
            ->actingAs($admin)
            ->from(route('app.team.create'))
            ->post(route('app.team.store'), [
                'name' => 'Barbeiro sem telefone',
                'email' => 'sem.telefone@example.test',
                'role' => MembershipRole::Barber->value,
            ])
            ->assertRedirect(route('app.team.create'))
            ->assertSessionHasErrors('phone');

        expect(User::query()->where('email', 'sem.telefone@example.test')->exists())->toBeFalse();
    });

    it('exige ao menos um serviço válido ao adicionar barbeiro', function () {
        [$tenant, $admin] = tenantWithUser(MembershipRole::Admin);
        actingInTenant($tenant);
        $service = Service::factory()->create([
            'tenant_id' => $tenant->getKey(),
            'name' => 'Corte',
        ]);
        actingWithoutTenant();

        $this->withSession(['current_tenant_id' => (string) $tenant->getKey()])
            ->actingAs($admin)
            ->from(route('app.team.create', ['role' => 'barber']))
            ->post(route('app.team.store'), [
                'name' => 'Sem serviço',
                'email' => 'sem.servico@example.test',
                'role' => MembershipRole::Barber->value,
                'phone' => '11992108613',
                'service_ids' => [],
                'commission_percentage' => '30',
                'business_hours' => [1 => ['opens_at' => '09:00', 'closes_at' => '18:00']],
            ])
            ->assertRedirect(route('app.team.create', ['role' => 'barber']))
            ->assertSessionHasErrors('service_ids');

        expect(User::query()->where('email', 'sem.servico@example.test')->exists())->toBeFalse();
    });

    it('exige expediente semanal e comissão no cadastro do barbeiro', function () {
        [$tenant, $admin] = tenantWithUser(MembershipRole::Admin);
        actingInTenant($tenant);
        $service = Service::factory()->create(['tenant_id' => $tenant->getKey()]);
        actingWithoutTenant();

        $this->withSession(['current_tenant_id' => (string) $tenant->getKey()])
            ->actingAs($admin)
            ->from(route('app.team.create', ['role' => 'barber']))
            ->post(route('app.team.store'), [
                'name' => 'Barbeiro sem expediente',
                'email' => 'sem.expediente@example.test',
                'role' => MembershipRole::Barber->value,
                'phone' => '11992108615',
                'service_ids' => [$service->getKey()],
                'commission_percentage' => '',
                'business_hours' => [
                    1 => ['opens_at' => '', 'closes_at' => ''],
                ],
            ])
            ->assertRedirect(route('app.team.create', ['role' => 'barber']))
            ->assertSessionHasErrors(['commission_percentage', 'business_hours']);

        expect(User::query()->where('email', 'sem.expediente@example.test')->exists())->toBeFalse();
    });

    it('permite criar membros apenas a quem tem users.manage', function () {
        [$tenant, $receptionist] = tenantWithUser(MembershipRole::Receptionist);

        $this->withSession(['current_tenant_id' => (string) $tenant->getKey()])
            ->actingAs($receptionist)
            ->get(route('app.team.create'))
            ->assertForbidden();

        $this->withSession(['current_tenant_id' => (string) $tenant->getKey()])
            ->actingAs($receptionist)
            ->post(route('app.team.store'), [
                'name' => 'Sem autorização',
                'email' => 'sem.auth@example.test',
                'role' => MembershipRole::Barber->value,
                'phone' => '11992108613',
            ])
            ->assertForbidden();
    });
});
