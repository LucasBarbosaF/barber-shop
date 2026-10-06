<?php

use App\Domain\Tenant\Enums\MembershipRole;
use App\Models\Barber;
use App\Models\Service;

describe('acesso a barbeiros e serviços', function () {
    it('renderiza listagem, cadastro e edição de serviços para o navegador', function () {
        [$tenant, $admin] = tenantWithUser(MembershipRole::Admin);
        actingInTenant($tenant);
        $service = Service::factory()->create([
            'tenant_id' => $tenant->getKey(),
            'name' => 'Corte Tradicional',
            'duration_minutes' => 45,
            'price' => '55.00',
        ]);
        actingWithoutTenant();

        $client = $this->withSession(['current_tenant_id' => (string) $tenant->getKey()])
            ->actingAs($admin);

        $client->get(route('app.services.index'))
            ->assertOk()
            ->assertSee('Corte Tradicional')
            ->assertSee('R$ 55,00')
            ->assertSee(route('app.services.create'), escape: false)
            ->assertSee(route('app.services.edit', $service), escape: false)
            ->assertDontSee('"duration_minutes":45');

        $client->get(route('app.services.create'))
            ->assertOk()
            ->assertSee('Cadastrar serviço')
            ->assertSee('name="duration_minutes"', escape: false);

        $client->get(route('app.services.edit', $service))
            ->assertOk()
            ->assertSee('Editar serviço')
            ->assertSee('value="Corte Tradicional"', escape: false)
            ->assertSee('value="55.00"', escape: false);
    });

    it('persiste formulários html e redireciona de volta para listagem', function () {
        [$tenant, $admin] = tenantWithUser(MembershipRole::Admin);
        actingWithoutTenant();

        $client = $this->withSession(['current_tenant_id' => (string) $tenant->getKey()])
            ->actingAs($admin);

        $client->post(route('app.services.store'), [
            'name' => 'Barba',
            'description' => 'Barba com toalha quente.',
            'duration_minutes' => '30',
            'price' => '35.00',
            'is_active' => '1',
        ])
            ->assertRedirect(route('app.services.index'))
            ->assertSessionHas('status', 'Serviço cadastrado com sucesso.');

        $service = Service::query()->where('name', 'Barba')->firstOrFail();

        $client->put(route('app.services.update', $service), [
            'name' => 'Barba Premium',
            'description' => 'Barba completa.',
            'duration_minutes' => '40',
            'price' => '45.00',
            'is_active' => '0',
        ])
            ->assertRedirect(route('app.services.index'))
            ->assertSessionHas('status', 'Serviço atualizado com sucesso.');

        expect($service->refresh()->is_active)->toBeFalse();

        $client->delete(route('app.services.destroy', $service))
            ->assertRedirect(route('app.services.index'))
            ->assertSessionHas('status', 'Serviço excluído com sucesso.');

        expect(Service::query()->whereKey($service->getKey())->exists())->toBeFalse();
    });

    it('renderiza e persiste as telas html de barbeiros', function () {
        [$tenant, $admin] = tenantWithUser(MembershipRole::Admin);
        actingInTenant($tenant);
        $barber = Barber::factory()->create([
            'tenant_id' => $tenant->getKey(),
            'name' => 'Barbeiro existente',
            'phone' => '5511888000010',
        ]);
        actingWithoutTenant();

        $client = $this->withSession(['current_tenant_id' => (string) $tenant->getKey()])
            ->actingAs($admin);

        $client->get(route('app.barbers.index'))
            ->assertOk()
            ->assertSee('Barbeiro existente')
            ->assertSee(route('app.team.create', ['role' => 'barber']), escape: false)
            ->assertSee('Cadastrar barbeiro')
            ->assertSee(route('app.barbers.edit', $barber), escape: false)
            ->assertDontSee('"phone":"5511888000010"');

        $client->get(route('app.barbers.create'))
            ->assertOk()
            ->assertSee('Cadastrar barbeiro')
            ->assertSee('name="is_active"', escape: false);

        $client->get(route('app.barbers.edit', $barber))
            ->assertOk()
            ->assertSee('Editar barbeiro')
            ->assertSee('value="Barbeiro existente"', escape: false);

        actingInTenant($tenant);
        $service = Service::factory()->create([
            'tenant_id' => $tenant->getKey(),
            'name' => 'Corte de cabelo',
        ]);
        actingWithoutTenant();

        $client->post(route('app.barbers.store'), [
            'name' => 'Novo barbeiro',
            'phone' => '5511888000011',
            'email' => 'novo-barbeiro@example.test',
            'bio' => 'Especialista em barba.',
            'is_active' => '1',
            'service_ids' => [$service->getKey()],
        ])
            ->assertRedirect(route('app.barbers.index'))
            ->assertSessionHas('status', 'Barbeiro cadastrado com sucesso.');

        $created = Barber::query()->where('phone', '5511888000011')->firstOrFail();

        $client->put(route('app.barbers.update', $created), [
            'name' => 'Barbeiro atualizado',
            'phone' => '5511888000011',
            'email' => 'novo-barbeiro@example.test',
            'bio' => 'Especialista em corte.',
            'is_active' => '0',
            'service_ids' => [$service->getKey()],
        ])
            ->assertRedirect(route('app.barbers.index'))
            ->assertSessionHas('status', 'Barbeiro atualizado com sucesso.');

        expect($created->refresh()->name)->toBe('Barbeiro atualizado')
            ->and($created->is_active)->toBeFalse()
            ->and($created->services()->pluck('services.id')->all())->toBe([$service->getKey()]);

        $client->delete(route('app.barbers.destroy', $created))
            ->assertRedirect(route('app.barbers.index'))
            ->assertSessionHas('status', 'Barbeiro excluído com sucesso.');
    });

    it('vincula barbeiro à conta da equipe e salva o expediente semanal', function () {
        [$tenant, $admin] = tenantWithUser(MembershipRole::Admin);
        [$barberUser] = addMemberTo($tenant, MembershipRole::Barber, [
            'name' => 'Conta do Barbeiro',
            'email' => 'conta-barbeiro@example.test',
        ]);
        actingInTenant($tenant);
        $service = Service::factory()->create([
            'tenant_id' => $tenant->getKey(),
            'name' => 'Corte agenda',
        ]);
        actingWithoutTenant();

        $client = $this->withSession(['current_tenant_id' => (string) $tenant->getKey()])
            ->actingAs($admin);

        $client->get(route('app.barbers.create'))
            ->assertOk()
            ->assertSee('Conta do Barbeiro')
            ->assertSee('Expediente semanal')
            ->assertSee('Corte agenda');

        $client->post(route('app.barbers.store'), [
            'name' => 'Barbeiro com agenda',
            'phone' => '5511888000012',
            'user_id' => $barberUser->getKey(),
            'is_active' => '1',
            'service_ids' => [$service->getKey()],
            'business_hours' => [
                1 => ['opens_at' => '09:00', 'closes_at' => '18:00'],
                2 => ['opens_at' => '', 'closes_at' => ''],
                3 => ['opens_at' => '', 'closes_at' => ''],
                4 => ['opens_at' => '', 'closes_at' => ''],
                5 => ['opens_at' => '', 'closes_at' => ''],
                6 => ['opens_at' => '', 'closes_at' => ''],
                0 => ['opens_at' => '', 'closes_at' => ''],
            ],
        ])
            ->assertRedirect(route('app.barbers.index'))
            ->assertSessionHas('status', 'Barbeiro cadastrado com sucesso.');

        actingInTenant($tenant);
        $barber = Barber::query()->where('phone', '5511888000012')->firstOrFail();

        expect($barber->user_id)->toBe($barberUser->getKey())
            ->and($barber->services()->pluck('services.id')->all())->toBe([$service->getKey()])
            ->and($barber->businessHours()->where('weekday', 1)->value('opens_at'))->toStartWith('09:00');
    });

    it('faz CRUD dos dois recursos no tenant atual e ignora tenant_id enviado', function () {
        [$tenantA, $admin] = tenantWithUser(MembershipRole::Admin);
        [$tenantB] = tenantWithUser(MembershipRole::Admin);
        actingWithoutTenant();

        $client = $this->withSession(['current_tenant_id' => (string) $tenantA->getKey()])
            ->actingAs($admin);

        $service = $client->postJson(route('app.services.store'), [
            'name' => 'Corte e barba',
            'description' => 'Corte com acabamento de barba.',
            'duration_minutes' => 60,
            'price' => '75.50',
            'tenant_id' => $tenantB->getKey(),
        ])
            ->assertCreated()
            ->assertJsonPath('data.tenant_id', (string) $tenantA->getKey())
            ->assertJsonPath('data.price', '75.50');

        $serviceId = $service->json('data.id');
        $barber = $client->postJson(route('app.barbers.store'), [
            'name' => 'João Barbeiro',
            'phone' => '5511888000006',
            'email' => 'joao@example.test',
            'bio' => 'Especialista em corte clássico.',
            'tenant_id' => $tenantB->getKey(),
            'service_ids' => [$serviceId],
        ])
            ->assertCreated()
            ->assertJsonPath('data.tenant_id', (string) $tenantA->getKey())
            ->assertJsonPath('data.name', 'João Barbeiro');

        $barberId = $barber->json('data.id');

        $client->patchJson(route('app.barbers.update', $barberId), [
            'name' => 'João Atualizado',
        ])
            ->assertOk()
            ->assertJsonPath('data.name', 'João Atualizado');

        $client->putJson(route('app.services.update', $serviceId), [
            'name' => 'Corte premium',
            'duration_minutes' => 75,
            'price' => '89.90',
        ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Corte premium')
            ->assertJsonPath('data.price', '89.90');

        $client->getJson(route('app.barbers.index'))
            ->assertOk()
            ->assertJsonPath('data.0.id', $barberId);

        $client->getJson(route('app.services.index'))
            ->assertOk()
            ->assertJsonPath('data.0.id', $serviceId);

        $client->deleteJson(route('app.barbers.destroy', $barberId))->assertNoContent();
        $client->deleteJson(route('app.services.destroy', $serviceId))->assertNoContent();

        $client->getJson(route('app.barbers.show', $barberId))->assertNotFound();
        $client->getJson(route('app.services.show', $serviceId))->assertNotFound();
    });

    it('retorna o mesmo 404 para ids de outro tenant e inexistentes', function () {
        [$tenantA, $admin] = tenantWithUser(MembershipRole::Admin);
        [$tenantB] = tenantWithUser(MembershipRole::Admin);

        actingInTenant($tenantB);
        $barberB = Barber::factory()->create([
            'tenant_id' => $tenantB->getKey(),
            'phone' => '5511888000007',
        ]);
        $serviceB = Service::factory()->create([
            'tenant_id' => $tenantB->getKey(),
            'name' => 'Serviço secreto',
        ]);
        actingWithoutTenant();

        $client = $this->withSession(['current_tenant_id' => (string) $tenantA->getKey()])
            ->actingAs($admin);

        $client->getJson(route('app.barbers.show', $barberB))->assertNotFound()->assertJsonStructure(['message']);
        $client->getJson(route('app.barbers.show', 999999))->assertNotFound()->assertJsonStructure(['message']);
        $client->getJson(route('app.services.show', $serviceB))->assertNotFound()->assertJsonStructure(['message']);
        $client->getJson(route('app.services.show', 999999))->assertNotFound()->assertJsonStructure(['message']);
    });

    it('aplica permissions de escrita para barbeiros e serviços', function () {
        [$tenant, $barberUser] = tenantWithUser(MembershipRole::Barber);
        actingInTenant($tenant);
        $barber = Barber::factory()->create([
            'tenant_id' => $tenant->getKey(),
            'phone' => '5511888000008',
        ]);
        $service = Service::factory()->create([
            'tenant_id' => $tenant->getKey(),
            'name' => 'Corte da role',
        ]);
        actingWithoutTenant();

        $client = $this->withSession(['current_tenant_id' => (string) $tenant->getKey()])
            ->actingAs($barberUser);

        $client->getJson(route('app.barbers.index'))->assertOk();
        $client->getJson(route('app.services.index'))->assertOk();
        $client->postJson(route('app.barbers.store'), [
            'name' => 'Outro barbeiro',
            'phone' => '5511888000009',
        ])->assertForbidden();
        $client->postJson(route('app.services.store'), [
            'name' => 'Novo serviço',
            'duration_minutes' => 30,
            'price' => '30.00',
        ])->assertForbidden();
        $client->patchJson(route('app.barbers.update', $barber), ['name' => 'Negado'])->assertForbidden();
        $client->patchJson(route('app.services.update', $service), ['name' => 'Negado'])->assertForbidden();
    });
});
