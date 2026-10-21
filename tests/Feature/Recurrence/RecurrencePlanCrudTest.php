<?php

use App\Domain\Tenant\Models\Tenant;
use App\Domain\Tenant\Enums\MembershipRole;
use App\Models\Barber;
use App\Models\BusinessHour;
use App\Models\Customer;
use App\Models\RecurrencePlan;
use App\Models\RecurrenceSubscription;
use App\Models\Service;
use Illuminate\Support\Str;

/*
 * CRUD do catálogo de planos no painel, no mesmo formato dos testes de
 * serviços: telas HTML + persistência + recusa de exclusão quando há
 * assinatura (a FK restrict vira mensagem amigável, não 500).
 */

/**
 * Barbearia com barbeiro, serviço, plano e cliente prontos para os testes.
 *
 * @return array{barber: Barber, service: Service, plan: RecurrencePlan, customer: Customer}
 */
function recurrenceCrudFixture(Tenant $tenant, array $planAttributes = []): array
{
    [$barberUser] = addMemberTo($tenant, MembershipRole::Barber);
    actingInTenant($tenant);

    $barber = Barber::factory()->create([
        'tenant_id' => $tenant->getKey(),
        'name' => 'Barbeiro Recorrência',
        'user_id' => $barberUser->getKey(),
    ]);
    $service = Service::factory()->create([
        'tenant_id' => $tenant->getKey(),
        'name' => 'Corte',
        'duration_minutes' => 30,
        'price' => '40.00',
    ]);
    $barber->services()->attach($service->getKey(), ['tenant_id' => $tenant->getKey()]);

    foreach ([0, 1, 2, 3, 4, 5, 6] as $weekday) {
        BusinessHour::query()->create([
            'tenant_id' => $tenant->getKey(),
            'barber_id' => $barber->getKey(),
            'weekday' => $weekday,
            'opens_at' => '09:00',
            'closes_at' => '12:00',
        ]);
    }

    $plan = RecurrencePlan::factory()->create(array_merge([
        'tenant_id' => $tenant->getKey(),
        'service_id' => $service->getKey(),
        'name' => 'Clube do Corte',
        'duration_months' => 6,
        'sessions_per_month' => 2,
        'max_sessions_per_week' => 2,
        'price' => '200.00',
    ], $planAttributes));

    $customer = Customer::factory()->create([
        'tenant_id' => $tenant->getKey(),
        'name' => 'Cliente Recorrente',
        'phone' => '11999887766',
    ]);

    actingWithoutTenant();

    return compact('barber', 'service', 'plan', 'customer');
}

function recurrenceCrudSubscription(Tenant $tenant, RecurrencePlan $plan, Customer $customer, Barber $barber): RecurrenceSubscription
{
    actingInTenant($tenant);
    $subscription = RecurrenceSubscription::query()->create([
        'public_id' => (string) Str::uuid(),
        'customer_id' => $customer->getKey(),
        'plan_id' => $plan->getKey(),
        'barber_id' => $barber->getKey(),
        'service_id' => $plan->service_id,
        'plan_name' => $plan->name,
        'price' => $plan->price,
        'duration_months' => $plan->duration_months,
        'sessions_per_month' => $plan->sessions_per_month,
        'max_sessions_per_week' => $plan->max_sessions_per_week,
        'starts_at' => now()->toDateString(),
        'ends_at' => now()->addMonths($plan->duration_months)->toDateString(),
        'status' => 'pending_payment',
    ]);
    actingWithoutTenant();

    return $subscription;
}

describe('planos de recorrência no painel', function () {
    it('renderiza listagem, cadastro e edição para o navegador', function () {
        [$tenant, $admin] = tenantWithUser(MembershipRole::Admin);
        $fixture = recurrenceCrudFixture($tenant);

        $client = $this->withSession(['current_tenant_id' => (string) $tenant->getKey()])
            ->actingAs($admin);

        $client->get(route('app.recurrence.index'))
            ->assertOk()
            ->assertSee('Recorrência')
            ->assertSee('Clube do Corte')
            ->assertSee('R$ 200,00')
            ->assertSee(route('app.recurrence.create'), escape: false)
            ->assertSee(route('app.recurrence.edit', $fixture['plan']), escape: false);

        $client->get(route('app.recurrence.create'))
            ->assertOk()
            ->assertSee('Novo plano de recorrência')
            ->assertSee('name="duration_months"', escape: false)
            ->assertSee($fixture['service']->name);

        $client->get(route('app.recurrence.edit', $fixture['plan']))
            ->assertOk()
            ->assertSee('Editar plano de recorrência')
            ->assertSee('value="Clube do Corte"', escape: false)
            ->assertSee('value="200.00"', escape: false);
    });

    it('persiste formulários html e redireciona de volta', function () {
        [$tenant, $admin] = tenantWithUser(MembershipRole::Admin);
        $fixture = recurrenceCrudFixture($tenant);

        $client = $this->withSession(['current_tenant_id' => (string) $tenant->getKey()])
            ->actingAs($admin);

        $client->post(route('app.recurrence.store'), [
            'name' => 'Plano Mensal',
            'description' => 'Dois cortes por mês.',
            'service_id' => $fixture['service']->getKey(),
            'duration_months' => '3',
            'sessions_per_month' => '2',
            'max_sessions_per_week' => '2',
            'price' => '150.00',
            'is_active' => '1',
        ])
            ->assertRedirect()
            ->assertSessionHas('status', 'Plano de recorrência cadastrado com sucesso.');

        $plan = RecurrencePlan::query()->where('name', 'Plano Mensal')->firstOrFail();

        expect($plan->total_sessions)->toBe(6);

        $client->put(route('app.recurrence.update', $plan), [
            'name' => 'Plano Trimestral',
            'description' => 'Atualizado.',
            'service_id' => $fixture['service']->getKey(),
            'duration_months' => '6',
            'sessions_per_month' => '2',
            'max_sessions_per_week' => '1',
            'price' => '280.00',
            'is_active' => '0',
        ])
            ->assertRedirect(route('app.recurrence.index'))
            ->assertSessionHas('status', 'Plano de recorrência atualizado com sucesso.');

        $plan->refresh();

        expect($plan->name)->toBe('Plano Trimestral')
            ->and($plan->is_active)->toBeFalse()
            ->and((int) $plan->max_sessions_per_week)->toBe(1);

        $client->delete(route('app.recurrence.destroy', $plan))
            ->assertRedirect(route('app.recurrence.index'))
            ->assertSessionHas('status', 'Plano de recorrência excluído com sucesso.');

        expect(RecurrencePlan::query()->whereKey($plan->getKey())->exists())->toBeFalse();
    });

    it('recusa excluir plano que já tem assinaturas', function () {
        [$tenant, $admin] = tenantWithUser(MembershipRole::Admin);
        $fixture = recurrenceCrudFixture($tenant);
        $subscription = recurrenceCrudSubscription(
            $tenant,
            $fixture['plan'],
            $fixture['customer'],
            $fixture['barber'],
        );

        $client = $this->withSession(['current_tenant_id' => (string) $tenant->getKey()])
            ->actingAs($admin);

        $client->delete(route('app.recurrence.destroy', $fixture['plan']))
            ->assertRedirect()
            ->assertSessionHasErrors('plan');

        expect(RecurrencePlan::query()->whereKey($fixture['plan']->getKey())->exists())->toBeTrue()
            ->and(RecurrenceSubscription::query()->whereKey($subscription->getKey())->exists())->toBeTrue();
    });

    it('exige nome único por barbearia', function () {
        [$tenant, $admin] = tenantWithUser(MembershipRole::Admin);
        $fixture = recurrenceCrudFixture($tenant);

        $client = $this->withSession(['current_tenant_id' => (string) $tenant->getKey()])
            ->actingAs($admin);

        $client->post(route('app.recurrence.store'), [
            'name' => $fixture['plan']->name,
            'service_id' => $fixture['service']->getKey(),
            'duration_months' => '6',
            'sessions_per_month' => '2',
            'price' => '100.00',
            'is_active' => '1',
        ])->assertSessionHasErrors('name');
    });

    it('lista apenas planos da própria barbearia', function () {
        [$tenantA, $adminA] = tenantWithUser(MembershipRole::Admin, ['name' => 'Barbearia A']);
        [$tenantB] = tenantWithUser(MembershipRole::Admin, ['name' => 'Barbearia B']);
        $fixtureA = recurrenceCrudFixture($tenantA, ['name' => 'Plano Visível A']);
        $fixtureB = recurrenceCrudFixture($tenantB, ['name' => 'Plano Invisível B']);

        $client = $this->withSession(['current_tenant_id' => (string) $tenantA->getKey()])
            ->actingAs($adminA);

        $client->get(route('app.recurrence.index'))
            ->assertOk()
            ->assertSee('Plano Visível A')
            ->assertDontSee('Plano Invisível B');

        $client->get(route('app.recurrence.edit', $fixtureB['plan']))
            ->assertNotFound();
    });

    it('esconde o CRUD de quem só tem visualização', function () {
        [$tenant, $receptionist] = tenantWithUser(MembershipRole::Receptionist);
        $fixture = recurrenceCrudFixture($tenant);

        $client = $this->withSession(['current_tenant_id' => (string) $tenant->getKey()])
            ->actingAs($receptionist);

        $client->get(route('app.recurrence.index'))
            ->assertOk()
            ->assertDontSee(route('app.recurrence.create'), escape: false);

        $client->get(route('app.recurrence.create'))->assertForbidden();
        $client->post(route('app.recurrence.store'), [])->assertForbidden();
        $client->get(route('app.recurrence.edit', $fixture['plan']))->assertForbidden();
        $client->delete(route('app.recurrence.destroy', $fixture['plan']))->assertForbidden();
    });
});
