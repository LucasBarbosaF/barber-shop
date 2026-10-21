<?php

use App\Application\Recurrence\Actions\ActivateSubscription;
use App\Application\Recurrence\Actions\RecordSubscriptionPayment;
use App\Domain\Agenda\Enums\AppointmentStatus;
use App\Domain\Recurrence\Enums\PaymentStatus;
use App\Domain\Recurrence\Enums\SubscriptionStatus;
use App\Domain\Tenant\Enums\MembershipRole;
use App\Domain\Tenant\Models\Tenant;
use App\Models\Appointment;
use App\Models\Barber;
use App\Models\BusinessHour;
use App\Models\Customer;
use App\Models\RecurrencePlan;
use App\Models\RecurrenceSubscription;
use App\Models\RecurrenceSubscriptionPayment;
use App\Models\Service;
use Carbon\CarbonImmutable;

/*
 * Site público de recorrência: catálogo de planos, assinatura com corte de
 * abertura, portal "minha recorrência" pelo public_id e agendamento dos
 * próximos cortes. Sem login de cliente no MVP — o uuid público é a chave.
 */

/**
 * @return array{barber: Barber, service: Service, plan: RecurrencePlan, tomorrow: CarbonImmutable}
 */
function publicRecurrenceFixture(Tenant $tenant, array $planAttributes = [], string $serviceSuffix = ''): array
{
    [$barberUser] = addMemberTo($tenant, MembershipRole::Barber);
    actingInTenant($tenant);

    $barber = Barber::factory()->create([
        'tenant_id' => $tenant->getKey(),
        'name' => 'Barbeiro Site'.$serviceSuffix,
        'user_id' => $barberUser->getKey(),
    ]);
    $service = Service::factory()->create([
        'tenant_id' => $tenant->getKey(),
        'name' => 'Corte'.$serviceSuffix,
        'duration_minutes' => 30,
        'price' => '40.00',
    ]);
    $barber->services()->attach($service->getKey(), ['tenant_id' => $tenant->getKey()]);

    $tomorrow = CarbonImmutable::now(config('app.timezone'))->addDay();
    // Expediente todos os dias: os testes agendam em dias consecutivos.
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
        'name' => 'Clube Site',
        'duration_months' => 6,
        'sessions_per_month' => 2,
        'max_sessions_per_week' => 2,
        'price' => '200.00',
    ], $planAttributes));

    actingWithoutTenant();

    return compact('barber', 'service', 'plan', 'tomorrow');
}

function publicSubscribe(Tenant $tenant, RecurrencePlan $plan, Barber $barber, CarbonImmutable $date, array $overrides = [])
{
    return test()->post(
        route('booking.plans.subscribe', ['tenant' => $tenant->slug, 'planId' => $plan->getKey()]),
        array_merge([
            'barber_id' => $barber->getKey(),
            'date' => $date->toDateString(),
            'time' => '09:00',
            'customer_name' => 'Cliente Site',
            'customer_phone' => '11999887766',
        ], $overrides)
    );
}

/**
 * Ativa e paga todos os meses do contrato. Os testes do portal medem
 * agendamento e exibição, não cobrança — sem isto, o gate de mês pago
 * recusaria o agendamento quando "amanhã" cruza a virada do mês e só o mês 1
 * (da ativação) estaria confirmado.
 */
function activateAndPayAllMonths(RecurrenceSubscription $subscription, string $amount): void
{
    app(ActivateSubscription::class)->execute([
        'subscription' => $subscription,
        'amount' => $amount,
        'method' => 'pix',
    ]);

    $first = $subscription->starts_at->toImmutable()->startOfMonth();
    foreach (range(0, $subscription->duration_months - 1) as $index) {
        $month = $first->addMonths($index);
        if ($subscription->hasPaidMonthFor($month)) {
            continue;
        }

        RecurrenceSubscriptionPayment::query()->create([
            'recurrence_subscription_id' => $subscription->getKey(),
            'period_month' => $month->toDateString(),
            'amount' => $subscription->price,
            'status' => PaymentStatus::Paid,
            'method' => 'pix',
            'paid_at' => now(),
        ]);
    }
}

describe('recorrência no site público', function () {
    it('lista apenas planos ativos no catálogo', function () {
        [$tenant] = tenantWithUser(MembershipRole::Admin);
        $fixture = publicRecurrenceFixture($tenant);
        $inativo = publicRecurrenceFixture($tenant, ['name' => 'Plano Inativo', 'is_active' => false], ' Inativo');

        $this->get(route('booking.plans', ['tenant' => $tenant->slug]))
            ->assertOk()
            ->assertSee('Clube Site')
            ->assertDontSee('Plano Inativo');

        $this->get(route('booking.plans.show', ['tenant' => $tenant->slug, 'planId' => $fixture['plan']->getKey()]))
            ->assertOk()
            ->assertSee('Clube Site')
            ->assertSee($fixture['barber']->name);

        $this->get(route('booking.plans.show', ['tenant' => $tenant->slug, 'planId' => $inativo['plan']->getKey()]))
            ->assertNotFound();
    });

    it('assina pelo site com corte de abertura e redireciona para o portal', function () {
        [$tenant] = tenantWithUser(MembershipRole::Admin);
        $fixture = publicRecurrenceFixture($tenant);

        $response = publicSubscribe($tenant, $fixture['plan'], $fixture['barber'], $fixture['tomorrow']);

        actingInTenant($tenant);
        $subscription = RecurrenceSubscription::query()->firstOrFail();

        $response->assertRedirect(route('booking.subscription.manage', [
            'tenant' => $tenant->slug,
            'subscriptionPublicId' => $subscription->public_id,
        ]));

        expect($subscription->status)->toBe(SubscriptionStatus::PendingPayment)
            ->and($subscription->plan_name)->toBe('Clube Site')
            ->and($subscription->appointments()->count())->toBe(1)
            ->and(Customer::query()->where('phone', '11999887766')->count())->toBe(1);

        actingWithoutTenant();
    });

    it('mostra o portal com a cota e esconde o agendamento enquanto pendente', function () {
        [$tenant] = tenantWithUser(MembershipRole::Admin);
        $fixture = publicRecurrenceFixture($tenant);

        publicSubscribe($tenant, $fixture['plan'], $fixture['barber'], $fixture['tomorrow']);

        actingInTenant($tenant);
        $subscription = RecurrenceSubscription::query()->firstOrFail();
        $publicId = $subscription->public_id;
        actingWithoutTenant();

        $response = $this->get(route('booking.subscription.manage', [
            'tenant' => $tenant->slug,
            'subscriptionPublicId' => $publicId,
        ]));

        $response->assertOk()
            ->assertSee('Aguardando pagamento')
            ->assertSee('Clube Site')
            ->assertDontSee('id="schedule-cut-form"', false);

        // A disponibilidade explica o motivo em vez de devolver slots vazios.
        $this->getJson(route('booking.subscription.availability', [
            'tenant' => $tenant->slug,
            'subscriptionPublicId' => $publicId,
        ]).'?'.http_build_query([
            'barber_id' => $fixture['barber']->getKey(),
            'date' => $fixture['tomorrow']->toDateString(),
        ]))
            ->assertOk()
            ->assertJson([
                'data' => [],
                'reason' => 'Aguarde a barbearia confirmar o pagamento do plano para agendar novos cortes.',
            ]);
    });

    it('permite agendar os próximos cortes após a ativação', function () {
        [$tenant] = tenantWithUser(MembershipRole::Admin);
        $fixture = publicRecurrenceFixture($tenant);

        publicSubscribe($tenant, $fixture['plan'], $fixture['barber'], $fixture['tomorrow']);

        actingInTenant($tenant);
        $subscription = RecurrenceSubscription::query()->firstOrFail();
        $publicId = $subscription->public_id;

        activateAndPayAllMonths($subscription, '200.00');
        actingWithoutTenant();

        $nextDay = $fixture['tomorrow']->addDay();

        $this->getJson(route('booking.subscription.availability', [
            'tenant' => $tenant->slug,
            'subscriptionPublicId' => $publicId,
        ]).'?'.http_build_query([
            'barber_id' => $fixture['barber']->getKey(),
            'date' => $nextDay->toDateString(),
        ]))
            ->assertOk()
            ->assertJsonPath('reason', null)
            ->assertJsonPath('data.0', '09:00');

        $this->post(route('booking.subscription.cuts.store', [
            'tenant' => $tenant->slug,
            'subscriptionPublicId' => $publicId,
        ]), [
            'barber_id' => $fixture['barber']->getKey(),
            'date' => $nextDay->toDateString(),
            'time' => '09:00',
        ])
            ->assertRedirect(route('booking.subscription.manage', [
                'tenant' => $tenant->slug,
                'subscriptionPublicId' => $publicId,
            ]));

        actingInTenant($tenant);
        expect($subscription->refresh()->appointments()->count())->toBe(2);
        actingWithoutTenant();
    });

    it('explica a cota esgotada no portal e na disponibilidade', function () {
        [$tenant] = tenantWithUser(MembershipRole::Admin);
        $fixture = publicRecurrenceFixture($tenant, ['sessions_per_month' => 1]);

        publicSubscribe($tenant, $fixture['plan'], $fixture['barber'], $fixture['tomorrow']);

        actingInTenant($tenant);
        $subscription = RecurrenceSubscription::query()->firstOrFail();
        $publicId = $subscription->public_id;

        activateAndPayAllMonths($subscription, '100.00');
        actingWithoutTenant();

        $thirdDay = $fixture['tomorrow']->addDays(2);

        $this->getJson(route('booking.subscription.availability', [
            'tenant' => $tenant->slug,
            'subscriptionPublicId' => $publicId,
        ]).'?'.http_build_query([
            'barber_id' => $fixture['barber']->getKey(),
            'date' => $thirdDay->toDateString(),
        ]))
            ->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('reason', 'Você já usou os 1 cortes de '.$thirdDay->locale('pt_BR')->isoFormat('MMMM').'. O próximo mês libera novos cortes.');

        // O portal continua mostrando a cota mesmo sem conseguir agendar.
        $this->get(route('booking.subscription.manage', [
            'tenant' => $tenant->slug,
            'subscriptionPublicId' => $publicId,
        ]))
            ->assertOk()
            ->assertSee('1 de 1');
    });

    it('recusa assinar plano inativo e não vê uuid de outra barbearia', function () {
        [$tenantA] = tenantWithUser(MembershipRole::Admin, ['name' => 'Barbearia A']);
        [$tenantB] = tenantWithUser(MembershipRole::Admin, ['name' => 'Barbearia B']);
        $fixtureA = publicRecurrenceFixture($tenantA);
        $fixtureB = publicRecurrenceFixture($tenantB, ['name' => 'Clube B']);

        // Assinatura criada em B.
        publicSubscribe($tenantB, $fixtureB['plan'], $fixtureB['barber'], $fixtureB['tomorrow']);
        actingInTenant($tenantB);
        $subscriptionB = RecurrenceSubscription::query()->firstOrFail();
        $publicIdB = $subscriptionB->public_id;
        actingWithoutTenant();

        // O uuid de B não resolve pelo slug de A.
        $this->get(route('booking.subscription.manage', [
            'tenant' => $tenantA->slug,
            'subscriptionPublicId' => $publicIdB,
        ]))->assertNotFound();

        $this->post(route('booking.subscription.cuts.store', [
            'tenant' => $tenantA->slug,
            'subscriptionPublicId' => $publicIdB,
        ]), [
            'barber_id' => $fixtureA['barber']->getKey(),
            'date' => $fixtureA['tomorrow']->toDateString(),
            'time' => '09:00',
        ])->assertNotFound();

        // O catálogo de A não mostra o plano de B.
        $this->get(route('booking.plans', ['tenant' => $tenantA->slug]))
            ->assertOk()
            ->assertSee('Clube Site')
            ->assertDontSee('Clube B');
    });

    it('valida telefone e horário na assinatura', function () {
        [$tenant] = tenantWithUser(MembershipRole::Admin);
        $fixture = publicRecurrenceFixture($tenant);

        publicSubscribe($tenant, $fixture['plan'], $fixture['barber'], $fixture['tomorrow'], [
            'customer_phone' => '123',
        ])->assertSessionHasErrors('customer_phone');

        publicSubscribe($tenant, $fixture['plan'], $fixture['barber'], $fixture['tomorrow'], [
            'time' => '25:99',
        ])->assertSessionHasErrors('time');

        publicSubscribe($tenant, $fixture['plan'], $fixture['barber'], $fixture['tomorrow'], [
            'time' => '20:00',
        ])->assertSessionHasErrors('time');

        publicSubscribe($tenant, $fixture['plan'], $fixture['barber'], $fixture['tomorrow'], [
            'barber_id' => 999999,
        ])->assertSessionHasErrors('barber_id');
    });

    it('não agenda horário já ocupado pelo próprio corte de abertura', function () {
        [$tenant] = tenantWithUser(MembershipRole::Admin);
        $fixture = publicRecurrenceFixture($tenant);

        publicSubscribe($tenant, $fixture['plan'], $fixture['barber'], $fixture['tomorrow']);

        actingInTenant($tenant);
        $subscription = RecurrenceSubscription::query()->firstOrFail();
        $publicId = $subscription->public_id;

        activateAndPayAllMonths($subscription, '200.00');
        actingWithoutTenant();

        // O slot das 09:00 já está ocupado pelo corte de abertura.
        $this->post(route('booking.subscription.cuts.store', [
            'tenant' => $tenant->slug,
            'subscriptionPublicId' => $publicId,
        ]), [
            'barber_id' => $fixture['barber']->getKey(),
            'date' => $fixture['tomorrow']->toDateString(),
            'time' => '09:00',
        ])->assertSessionHasErrors('time');

        actingInTenant($tenant);
        expect($subscription->refresh()->appointments()->count())->toBe(1);

        // O slot das 09:00 já está ocupado pelo corte de abertura (30 min).
        $this->getJson(route('booking.subscription.availability', [
            'tenant' => $tenant->slug,
            'subscriptionPublicId' => $publicId,
        ]).'?'.http_build_query([
            'barber_id' => $fixture['barber']->getKey(),
            'date' => $fixture['tomorrow']->toDateString(),
        ]))
            ->assertOk()
            ->assertJsonPath('data.0', '09:30');

        actingInTenant($tenant);
        $first = Appointment::query()->where('recurrence_subscription_id', $subscription->getKey())->firstOrFail();
        $first->update(['status' => AppointmentStatus::Canceled]);
        actingWithoutTenant();

        // Com o abertura cancelado o slot volta.
        $this->getJson(route('booking.subscription.availability', [
            'tenant' => $tenant->slug,
            'subscriptionPublicId' => $publicId,
        ]).'?'.http_build_query([
            'barber_id' => $fixture['barber']->getKey(),
            'date' => $fixture['tomorrow']->toDateString(),
        ]))
            ->assertOk()
            ->assertJsonPath('data.0', '09:00');
    });

    it('mostra as mensalidades no portal e libera o mês assim que o pagamento entra', function () {
        [$tenant] = tenantWithUser(MembershipRole::Admin);
        $fixture = publicRecurrenceFixture($tenant);

        publicSubscribe($tenant, $fixture['plan'], $fixture['barber'], $fixture['tomorrow']);

        actingInTenant($tenant);
        $subscription = RecurrenceSubscription::query()->firstOrFail();
        $publicId = $subscription->public_id;

        app(ActivateSubscription::class)->execute([
            'subscription' => $subscription,
            'amount' => '200.00',
            'method' => 'pix',
        ]);
        actingWithoutTenant();

        // O portal lista as mensalidades: mês 1 pago, os demais aguardando.
        $this->get(route('booking.subscription.manage', [
            'tenant' => $tenant->slug,
            'subscriptionPublicId' => $publicId,
        ]))
            ->assertOk()
            ->assertSee('MENSALIDADES')
            ->assertSee('Pagamentos do contrato')
            ->assertSee('Pago')
            ->assertSee('Aguardando pagamento');

        // Mês seguinte sem pagamento: disponibilidade explica e o corte é recusado.
        $nextMonthDate = $subscription->starts_at->toImmutable()->startOfMonth()->addMonth()->addDays(3);
        $expectedReason = sprintf(
            'O pagamento de %s ainda não foi confirmado. Registre o pagamento para liberar os cortes deste mês.',
            $nextMonthDate->startOfMonth()->locale('pt_BR')->isoFormat('MMMM [de] YYYY'),
        );

        $availabilityUrl = route('booking.subscription.availability', [
            'tenant' => $tenant->slug,
            'subscriptionPublicId' => $publicId,
        ]).'?'.http_build_query([
            'barber_id' => $fixture['barber']->getKey(),
            'date' => $nextMonthDate->toDateString(),
        ]);

        $this->getJson($availabilityUrl)
            ->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('reason', $expectedReason);

        $this->post(route('booking.subscription.cuts.store', [
            'tenant' => $tenant->slug,
            'subscriptionPublicId' => $publicId,
        ]), [
            'barber_id' => $fixture['barber']->getKey(),
            'date' => $nextMonthDate->toDateString(),
            'time' => '09:00',
        ])->assertSessionHasErrors('date');

        // Registrado o pagamento daquele mês, a disponibilidade volta.
        actingInTenant($tenant);
        app(RecordSubscriptionPayment::class)->execute([
            'subscription' => $subscription,
            'period_month' => $nextMonthDate->startOfMonth()->toDateString(),
            'amount' => '200.00',
            'method' => 'pix',
        ]);
        actingWithoutTenant();

        $this->getJson($availabilityUrl)
            ->assertOk()
            ->assertJsonPath('reason', null)
            ->assertJsonPath('data.0', '09:00');

        $this->post(route('booking.subscription.cuts.store', [
            'tenant' => $tenant->slug,
            'subscriptionPublicId' => $publicId,
        ]), [
            'barber_id' => $fixture['barber']->getKey(),
            'date' => $nextMonthDate->toDateString(),
            'time' => '09:00',
        ])->assertRedirect(route('booking.subscription.manage', [
            'tenant' => $tenant->slug,
            'subscriptionPublicId' => $publicId,
        ]));
    });
});
