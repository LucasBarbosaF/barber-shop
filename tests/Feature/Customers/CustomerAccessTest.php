<?php

use App\Domain\Tenant\Enums\MembershipRole;
use App\Models\Customer;

describe('acesso a clientes', function () {
    it('lista somente customers do tenant autenticado', function () {
        [$tenantA, $adminA] = tenantWithUser(MembershipRole::Admin);
        [$tenantB] = tenantWithUser(MembershipRole::Admin);

        actingInTenant($tenantA);
        $customerA = Customer::factory()->create([
            'tenant_id' => $tenantA->getKey(),
            'name' => 'Cliente A',
            'phone' => '5511999111001',
        ]);

        actingInTenant($tenantB);
        $customerB = Customer::factory()->create([
            'tenant_id' => $tenantB->getKey(),
            'name' => 'Cliente B',
            'phone' => '5511999111002',
        ]);

        actingWithoutTenant();

        $this->withSession(['current_tenant_id' => (string) $tenantA->getKey()])
            ->actingAs($adminA)
            ->getJson(route('app.customers.index'))
            ->assertOk()
            ->assertJsonPath('data.0.id', $customerA->getKey())
            ->assertJsonMissing(['id' => $customerB->getKey()]);
    });

    it('responde o mesmo 404 para id alheio e inexistente', function () {
        [$tenantA, $adminA] = tenantWithUser(MembershipRole::Admin);
        [$tenantB] = tenantWithUser(MembershipRole::Admin);

        actingInTenant($tenantB);
        $customerB = Customer::factory()->create([
            'tenant_id' => $tenantB->getKey(),
            'phone' => '5511999111003',
        ]);
        actingWithoutTenant();

        $client = $this->withSession(['current_tenant_id' => (string) $tenantA->getKey()])
            ->actingAs($adminA);

        $client->getJson(route('app.customers.show', $customerB))
            ->assertNotFound()
            ->assertJsonStructure(['message']);

        $client->getJson(route('app.customers.show', 999999))
            ->assertNotFound()
            ->assertJsonStructure(['message']);
    });

    it('executa CRUD sem confiar em tenant_id enviado pelo cliente', function () {
        [$tenantA, $adminA] = tenantWithUser(MembershipRole::Admin);
        [$tenantB] = tenantWithUser(MembershipRole::Admin);
        actingWithoutTenant();

        $client = $this->withSession(['current_tenant_id' => (string) $tenantA->getKey()])
            ->actingAs($adminA);

        $created = $client->postJson(route('app.customers.store'), [
            'name' => 'Cliente Novo',
            'phone' => '5511999111004',
            'notes' => 'Preferência por horário da manhã.',
            'tenant_id' => $tenantB->getKey(),
        ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Cliente Novo')
            ->assertJsonPath('data.tenant_id', (string) $tenantA->getKey());

        $customerId = $created->json('data.id');

        $client->patchJson(route('app.customers.update', $customerId), [
            'name' => 'Cliente Atualizado',
        ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Cliente Atualizado');

        $client->deleteJson(route('app.customers.destroy', $customerId))
            ->assertNoContent();

        $client->getJson(route('app.customers.show', $customerId))->assertNotFound();
    });

    it('renderiza e persiste as telas html de clientes', function () {
        [$tenant, $admin] = tenantWithUser(MembershipRole::Admin);
        actingInTenant($tenant);
        $customer = Customer::factory()->create([
            'tenant_id' => $tenant->getKey(),
            'name' => 'Cliente existente',
            'phone' => '5511999111010',
        ]);
        actingWithoutTenant();

        $client = $this->withSession(['current_tenant_id' => (string) $tenant->getKey()])
            ->actingAs($admin);

        $client->get(route('app.customers.index'))
            ->assertOk()
            ->assertSee('Cliente existente')
            ->assertSee(route('app.customers.create'), escape: false)
            ->assertSee(route('app.customers.edit', $customer), escape: false)
            ->assertDontSee('"phone":"5511999111010"');

        $client->get(route('app.customers.create'))
            ->assertOk()
            ->assertSee('Cadastrar cliente')
            ->assertSee('name="phone"', escape: false);

        $client->get(route('app.customers.edit', $customer))
            ->assertOk()
            ->assertSee('Editar cliente')
            ->assertSee('value="Cliente existente"', escape: false);

        $client->post(route('app.customers.store'), [
            'name' => 'Cliente cadastrado',
            'phone' => '5511999111011',
            'notes' => 'Agendar pela manhã.',
        ])
            ->assertRedirect(route('app.customers.index'))
            ->assertSessionHas('status', 'Cliente cadastrado com sucesso.');

        $created = Customer::query()->where('phone', '5511999111011')->firstOrFail();

        $client->put(route('app.customers.update', $created), [
            'name' => 'Cliente atualizado',
            'phone' => '5511999111011',
            'notes' => 'Preferência atualizada.',
        ])
            ->assertRedirect(route('app.customers.index'))
            ->assertSessionHas('status', 'Cliente atualizado com sucesso.');

        expect($created->refresh()->name)->toBe('Cliente atualizado');

        $client->delete(route('app.customers.destroy', $created))
            ->assertRedirect(route('app.customers.index'))
            ->assertSessionHas('status', 'Cliente excluído com sucesso.');
    });

    it('aplica permissions de create update e delete', function () {
        [$tenant, $barber] = tenantWithUser(MembershipRole::Barber);
        actingInTenant($tenant);
        $customer = Customer::factory()->create([
            'tenant_id' => $tenant->getKey(),
            'phone' => '5511999111005',
        ]);
        actingWithoutTenant();

        $this->withSession(['current_tenant_id' => (string) $tenant->getKey()])
            ->actingAs($barber)
            ->postJson(route('app.customers.store'), [
                'name' => 'Cliente de barbeiro',
                'phone' => '5511999111006',
            ])
            ->assertCreated();

        $this->patchJson(route('app.customers.update', $customer), ['name' => 'Sem permissão'])
            ->assertForbidden();

        $this->deleteJson(route('app.customers.destroy', $customer))
            ->assertForbidden();
    });
});
