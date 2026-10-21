<?php

use App\Domain\Agenda\Enums\AppointmentStatus;
use App\Domain\Cash\Enums\CashRegisterStatus;
use App\Domain\Cash\Enums\CashTransactionType;
use App\Domain\Recurrence\Enums\PaymentStatus;
use App\Domain\Recurrence\Enums\SubscriptionStatus;
use App\Domain\Tenant\Enums\MembershipRole;
use App\Domain\Tenant\Models\Tenant;
use App\Models\Appointment;
use App\Models\Barber;
use App\Models\BusinessHour;
use App\Models\CashRegister;
use App\Models\CashTransaction;
use App\Models\Customer;
use App\Models\RecurrencePlan;
use App\Models\RecurrenceSubscription;
use App\Models\RecurrenceSubscriptionPayment;
use App\Models\Service;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/*
 * Ciclo de vida da assinatura no painel: criação pela recepção (nasce
 * pending_payment), ativação com/sem caixa aberto, lançamento tardio no
 * caixa, agendamento de cortes, cancelamento com cortes futuros e o comando
 * recurrence:expire multi-tenant.
 */

/**
 * Barbearia com barbeiro reservável, serviço, plano e cliente para o fluxo.
 *
 * @return array{barber: Barber, service: Service, plan: RecurrencePlan, customer: Customer, tomorrow: CarbonImmutable}
 */
function subscriptionFlowFixture(Tenant $tenant, array $planAttributes = []): array
{
    [$barberUser] = addMemberTo($tenant, MembershipRole::Barber);
    actingInTenant($tenant);

    $barber = Barber::factory()->create([
        'tenant_id' => $tenant->getKey(),
        'name' => 'Barbeiro Assinatura',
        'user_id' => $barberUser->getKey(),
    ]);
    $service = Service::factory()->create([
        'tenant_id' => $tenant->getKey(),
        'name' => 'Corte',
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
        'name' => 'Clube Assinatura',
        'duration_months' => 6,
        'sessions_per_month' => 2,
        'max_sessions_per_week' => 2,
        'price' => '200.00',
    ], $planAttributes));

    $customer = Customer::factory()->create([
        'tenant_id' => $tenant->getKey(),
        'name' => 'Cliente Assinatura',
        'phone' => '11999887733',
    ]);

    actingWithoutTenant();

    return compact('barber', 'service', 'plan', 'customer', 'tomorrow');
}

/**
 * Assinatura ativa direto no model, sem depender do corte de abertura.
 *
 * `markMonthsPaid` grava todas as mensalidades do contrato como `paid` — o
 * estado padrão dos testes que agendam, já que a liberação do mês é gate de
 * agendamento. Os testes de ativação e de lançamento manual pedem `false`,
 * porque justamente exercitam a criação dessas linhas.
 */
function activeSubscriptionFor(
    Tenant $tenant,
    RecurrencePlan $plan,
    Customer $customer,
    Barber $barber,
    bool $markMonthsPaid = true,
): RecurrenceSubscription {
    actingInTenant($tenant);
    $subscription = RecurrenceSubscription::query()->create([
        'public_id' => Str::uuid(),
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
        'status' => SubscriptionStatus::Active,
        'paid_amount' => $plan->price,
        'paid_at' => now(),
    ]);

    if ($markMonthsPaid) {
        $first = $subscription->starts_at->toImmutable()->startOfMonth();
        foreach (range(0, $subscription->duration_months - 1) as $index) {
            RecurrenceSubscriptionPayment::query()->create([
                'recurrence_subscription_id' => $subscription->getKey(),
                'period_month' => $first->addMonths($index)->toDateString(),
                'amount' => $subscription->price,
                'status' => PaymentStatus::Paid,
                'method' => 'pix',
                'paid_at' => now(),
            ]);
        }
    }

    actingWithoutTenant();

    return $subscription;
}

describe('assinaturas de recorrência no painel', function () {
    it('cria assinatura pela recepção com corte de abertura', function () {
        [$tenant, $receptionist] = tenantWithUser(MembershipRole::Receptionist);
        $fixture = subscriptionFlowFixture($tenant);

        $client = $this->withSession(['current_tenant_id' => (string) $tenant->getKey()])
            ->actingAs($receptionist);

        $client->post(route('app.recurrence.subscriptions.store'), [
            'plan_id' => $fixture['plan']->getKey(),
            'customer_id' => $fixture['customer']->getKey(),
            'barber_id' => $fixture['barber']->getKey(),
            'starts_at' => now()->toDateString(),
            'first_appointment_date' => $fixture['tomorrow']->toDateString(),
            'first_appointment_time' => '09:00',
        ])
            ->assertRedirect()
            ->assertSessionHas('status', 'Assinatura criada. Confirme o pagamento para ativá-la.');

        actingInTenant($tenant);
        $subscription = RecurrenceSubscription::query()->firstOrFail();

        expect($subscription->status)->toBe(SubscriptionStatus::PendingPayment)
            ->and($subscription->plan_name)->toBe('Clube Assinatura')
            ->and($subscription->appointments()->count())->toBe(1);

        actingWithoutTenant();
    });

    it('ativa com caixa aberto e lança a entrada no mesmo movimento', function () {
        [$tenant, $receptionist] = tenantWithUser(MembershipRole::Receptionist);
        $fixture = subscriptionFlowFixture($tenant);
        $subscription = activeSubscriptionFor($tenant, $fixture['plan'], $fixture['customer'], $fixture['barber'], markMonthsPaid: false);

        actingInTenant($tenant);
        $register = CashRegister::query()->create([
            'status' => CashRegisterStatus::Open,
            'opening_amount' => '0.00',
            'opened_at' => now(),
        ]);
        actingWithoutTenant();

        $client = $this->withSession(['current_tenant_id' => (string) $tenant->getKey()])
            ->actingAs($receptionist);

        // A assinatura foi criada como ativa; rebaixamos para pendente para exercitar a ativação.
        actingInTenant($tenant);
        $subscription->update(['status' => SubscriptionStatus::PendingPayment, 'paid_at' => null, 'paid_amount' => null]);
        actingWithoutTenant();

        $client->post(route('app.recurrence.subscriptions.activate', $subscription), [
            'amount' => '200.00',
            'method' => 'pix',
        ])
            ->assertRedirect()
            ->assertSessionHas('status', 'Pagamento confirmado. Assinatura ativa e valor lançado no caixa.');

        actingInTenant($tenant);
        $subscription->refresh();

        expect($subscription->status)->toBe(SubscriptionStatus::Active)
            ->and($subscription->paid_amount)->toBe('200.00')
            ->and($subscription->paid_method)->toBe('pix')
            ->and($subscription->cash_posted_at)->not->toBeNull()
            ->and($register->transactions()->count())->toBe(1);

        $transaction = CashTransaction::query()->where('cash_register_id', $register->getKey())->firstOrFail();
        expect($transaction->type)->toBe(CashTransactionType::CashIn)
            ->and($transaction->amount)->toBe('200.00')
            ->and($transaction->description)->toContain('Clube Assinatura');

        actingWithoutTenant();
    });

    it('ativa sem caixa e permite lançar depois', function () {
        [$tenant, $receptionist] = tenantWithUser(MembershipRole::Receptionist);
        $fixture = subscriptionFlowFixture($tenant);
        $subscription = activeSubscriptionFor($tenant, $fixture['plan'], $fixture['customer'], $fixture['barber'], markMonthsPaid: false);

        actingInTenant($tenant);
        $subscription->update(['status' => SubscriptionStatus::PendingPayment, 'paid_at' => null, 'paid_amount' => null]);
        actingWithoutTenant();

        $client = $this->withSession(['current_tenant_id' => (string) $tenant->getKey()])
            ->actingAs($receptionist);

        $client->post(route('app.recurrence.subscriptions.activate', $subscription), [
            'amount' => '180.00',
            'method' => 'cash',
        ])
            ->assertSessionHas('status', 'Pagamento confirmado. Assinatura ativa — não há caixa aberto para lançar a entrada agora.');

        actingInTenant($tenant);
        $subscription->refresh();

        expect($subscription->status)->toBe(SubscriptionStatus::Active)
            ->and($subscription->cash_posted_at)->toBeNull();

        // Sem caixa aberto o lançamento é recusado.
        $client->post(route('app.recurrence.subscriptions.cash', $subscription))
            ->assertSessionHasErrors('cash_register');

        $register = CashRegister::query()->create([
            'status' => CashRegisterStatus::Open,
            'opening_amount' => '0.00',
            'opened_at' => now(),
        ]);
        actingWithoutTenant();

        $client->post(route('app.recurrence.subscriptions.cash', $subscription))
            ->assertSessionHas('status', 'Pagamento lançado no caixa.');

        actingInTenant($tenant);
        $subscription->refresh();

        expect($subscription->cash_posted_at)->not->toBeNull()
            ->and($register->transactions()->count())->toBe(1);

        // Idempotente: segundo clique não duplica.
        $client->post(route('app.recurrence.subscriptions.cash', $subscription))
            ->assertSessionHasErrors('cash');

        actingWithoutTenant();
    });

    it('agenda cortes de assinatura ativa e recusa pendente ou fora da cota', function () {
        [$tenant, $receptionist] = tenantWithUser(MembershipRole::Receptionist);
        $fixture = subscriptionFlowFixture($tenant);
        $subscription = activeSubscriptionFor($tenant, $fixture['plan'], $fixture['customer'], $fixture['barber']);

        $client = $this->withSession(['current_tenant_id' => (string) $tenant->getKey()])
            ->actingAs($receptionist);

        $client->post(route('app.recurrence.subscriptions.cuts.store', $subscription), [
            'barber_id' => $fixture['barber']->getKey(),
            'date' => $fixture['tomorrow']->toDateString(),
            'time' => '09:00',
        ])
            ->assertSessionHas('status', 'Corte agendado.');

        actingInTenant($tenant);
        expect($subscription->appointments()->count())->toBe(1);
        actingWithoutTenant();

        // Pendente não agenda além do corte de abertura.
        $pending = activeSubscriptionFor($tenant, $fixture['plan'], $fixture['customer'], $fixture['barber']);
        actingInTenant($tenant);
        $pending->update(['status' => SubscriptionStatus::PendingPayment]);
        actingWithoutTenant();

        $client->post(route('app.recurrence.subscriptions.cuts.store', $pending), [
            'barber_id' => $fixture['barber']->getKey(),
            'date' => $fixture['tomorrow']->toDateString(),
            'time' => '10:00',
        ])->assertSessionHasErrors('subscription');

        // Cota mensal esgotada recusa o terceiro corte do mês.
        $dayAfter = $fixture['tomorrow']->addDay();
        $client->post(route('app.recurrence.subscriptions.cuts.store', $subscription), [
            'barber_id' => $fixture['barber']->getKey(),
            'date' => $dayAfter->toDateString(),
            'time' => '09:00',
        ])->assertSessionHas('status', 'Corte agendado.');

        $thirdDay = $dayAfter->addDay();
        $client->post(route('app.recurrence.subscriptions.cuts.store', $subscription), [
            'barber_id' => $fixture['barber']->getKey(),
            'date' => $thirdDay->toDateString(),
            'time' => '09:00',
        ])->assertSessionHasErrors('date');
    });

    it('registra mensalidades pelo painel com validações de mês, duplicidade e caixa', function () {
        [$tenant, $receptionist] = tenantWithUser(MembershipRole::Receptionist);
        $fixture = subscriptionFlowFixture($tenant);
        $subscription = activeSubscriptionFor($tenant, $fixture['plan'], $fixture['customer'], $fixture['barber'], markMonthsPaid: false);

        $client = $this->withSession(['current_tenant_id' => (string) $tenant->getKey()])
            ->actingAs($receptionist);

        $firstMonth = $subscription->starts_at->toImmutable()->startOfMonth();
        $secondMonth = $firstMonth->addMonth();

        // Assinatura pendente não aceita pagamento.
        actingInTenant($tenant);
        $subscription->update(['status' => SubscriptionStatus::PendingPayment]);
        actingWithoutTenant();

        $client->post(route('app.recurrence.subscriptions.payments.store', $subscription), [
            'period_month' => $secondMonth->toDateString(),
            'amount' => '200.00',
            'method' => 'pix',
        ])->assertSessionHasErrors('status');

        actingInTenant($tenant);
        $subscription->update(['status' => SubscriptionStatus::Active]);
        actingWithoutTenant();

        // Fora da janela do contrato: 6 meses a partir do mês de início.
        $outOfRange = $firstMonth->addMonths($fixture['plan']->duration_months);
        $client->post(route('app.recurrence.subscriptions.payments.store', $subscription), [
            'period_month' => $outOfRange->toDateString(),
            'amount' => '200.00',
            'method' => 'pix',
        ])->assertSessionHasErrors('period_month');

        // Mês 2 registrado sem caixa aberto: pago, mas fora do fechamento.
        $client->post(route('app.recurrence.subscriptions.payments.store', $subscription), [
            'period_month' => $secondMonth->toDateString(),
            'amount' => '180.00',
            'method' => 'pix',
        ])->assertSessionHas('status', 'Pagamento do mês registrado. Não há caixa aberto para lançar a entrada agora.');

        actingInTenant($tenant);
        $payment = RecurrenceSubscriptionPayment::query()
            ->where('recurrence_subscription_id', $subscription->getKey())
            ->where('period_month', $secondMonth->toDateString())
            ->firstOrFail();
        expect($payment->status)->toBe(PaymentStatus::Paid)
            ->and($payment->amount)->toBe('180.00')
            ->and($payment->method)->toBe('pix')
            ->and($payment->cash_posted_at)->toBeNull();
        actingWithoutTenant();

        // Mesmo mês de novo é duplicidade.
        $client->post(route('app.recurrence.subscriptions.payments.store', $subscription), [
            'period_month' => $secondMonth->toDateString(),
            'amount' => '200.00',
            'method' => 'pix',
        ])->assertSessionHasErrors('period_month');

        // O detalhe exibe a tabela de mensalidades do contrato.
        $client->get(route('app.recurrence.subscriptions.show', $subscription))
            ->assertOk()
            ->assertSee('Mensalidades')
            ->assertSee('Aguardando pagamento');

        // Caixa aberto: o botão lança o pagamento confirmado.
        actingInTenant($tenant);
        $register = CashRegister::query()->create([
            'status' => CashRegisterStatus::Open,
            'opening_amount' => '0.00',
            'opened_at' => now(),
        ]);
        actingWithoutTenant();

        $client->post(route('app.recurrence.subscriptions.cash', $subscription))
            ->assertSessionHas('status', 'Pagamento lançado no caixa.');

        actingInTenant($tenant);
        $payment->refresh();
        $transaction = CashTransaction::query()
            ->where('cash_register_id', $register->getKey())
            ->firstOrFail();
        expect($payment->cash_posted_at)->not->toBeNull()
            ->and($transaction->type)->toBe(CashTransactionType::CashIn)
            ->and($transaction->amount)->toBe('180.00')
            ->and($transaction->description)->toContain($secondMonth->format('m/Y'));
        actingWithoutTenant();

        // Nada pendente: o segundo clique não duplica.
        $client->post(route('app.recurrence.subscriptions.cash', $subscription))
            ->assertSessionHasErrors('cash');
    });

    it('recusa agendar em mês sem pagamento e libera após registrar', function () {
        [$tenant, $receptionist] = tenantWithUser(MembershipRole::Receptionist);
        $fixture = subscriptionFlowFixture($tenant);
        $subscription = activeSubscriptionFor($tenant, $fixture['plan'], $fixture['customer'], $fixture['barber'], markMonthsPaid: false);

        $client = $this->withSession(['current_tenant_id' => (string) $tenant->getKey()])
            ->actingAs($receptionist);

        $schedule = fn (string $date) => $client->post(
            route('app.recurrence.subscriptions.cuts.store', $subscription),
            [
                'barber_id' => $fixture['barber']->getKey(),
                'date' => $date,
                'time' => '09:00',
            ],
        );

        $schedule($fixture['tomorrow']->toDateString())
            ->assertSessionHasErrors('date');

        // Registra o mês do corte pretendido (amanhã pode cruzar a virada).
        $client->post(route('app.recurrence.subscriptions.payments.store', $subscription), [
            'period_month' => $fixture['tomorrow']->toImmutable()->startOfMonth()->toDateString(),
            'amount' => '200.00',
            'method' => 'cash',
        ])->assertSessionHas('status', 'Pagamento do mês registrado. Não há caixa aberto para lançar a entrada agora.');

        $schedule($fixture['tomorrow']->toDateString())
            ->assertSessionHas('status', 'Corte agendado.');

        actingInTenant($tenant);
        expect($subscription->appointments()->count())->toBe(1);
        actingWithoutTenant();
    });

    it('cancela assinatura e opcionalmente os cortes futuros', function () {
        [$tenant, $receptionist] = tenantWithUser(MembershipRole::Receptionist);
        $fixture = subscriptionFlowFixture($tenant);
        $subscription = activeSubscriptionFor($tenant, $fixture['plan'], $fixture['customer'], $fixture['barber']);

        actingInTenant($tenant);
        $past = Appointment::query()->create([
            'customer_id' => $fixture['customer']->getKey(),
            'barber_id' => $fixture['barber']->getKey(),
            'service_id' => $fixture['service']->getKey(),
            'recurrence_subscription_id' => $subscription->getKey(),
            'starts_at' => now()->subDays(7),
            'ends_at' => now()->subDays(7)->addMinutes(30),
            'status' => AppointmentStatus::Completed,
        ]);
        $future = Appointment::query()->create([
            'customer_id' => $fixture['customer']->getKey(),
            'barber_id' => $fixture['barber']->getKey(),
            'service_id' => $fixture['service']->getKey(),
            'recurrence_subscription_id' => $subscription->getKey(),
            'starts_at' => $fixture['tomorrow'],
            'ends_at' => $fixture['tomorrow']->addMinutes(30),
            'status' => AppointmentStatus::Confirmed,
        ]);
        actingWithoutTenant();

        $client = $this->withSession(['current_tenant_id' => (string) $tenant->getKey()])
            ->actingAs($receptionist);

        $client->post(route('app.recurrence.subscriptions.cancel', $subscription), [
            'reason' => 'Cliente mudou de cidade.',
            'cancel_future_appointments' => '1',
        ])
            ->assertRedirect()
            ->assertSessionHas('status', 'Assinatura cancelada. 1 corte(s) futuro(s) também cancelado(s).');

        actingInTenant($tenant);
        $subscription->refresh();

        expect($subscription->status)->toBe(SubscriptionStatus::Canceled)
            ->and($subscription->cancelation_reason)->toBe('Cliente mudou de cidade.')
            ->and($future->refresh()->status)->toBe(AppointmentStatus::Canceled)
            ->and($past->refresh()->status)->toBe(AppointmentStatus::Completed);

        actingWithoutTenant();
    });

    it('expira assinaturas vencidas em todas as barbearias ativas', function () {
        [$tenantA, $adminA] = tenantWithUser(MembershipRole::Admin);
        [$tenantB] = tenantWithUser(MembershipRole::Admin);
        $fixtureA = subscriptionFlowFixture($tenantA);
        $fixtureB = subscriptionFlowFixture($tenantB);

        actingInTenant($tenantA);
        $vencidaA = RecurrenceSubscription::query()->create([
            'public_id' => Str::uuid(),
            'customer_id' => $fixtureA['customer']->getKey(),
            'plan_id' => $fixtureA['plan']->getKey(),
            'barber_id' => $fixtureA['barber']->getKey(),
            'service_id' => $fixtureA['service']->getKey(),
            'plan_name' => $fixtureA['plan']->name,
            'price' => $fixtureA['plan']->price,
            'duration_months' => 1,
            'sessions_per_month' => 2,
            'max_sessions_per_week' => 2,
            'starts_at' => now()->subMonths(2)->toDateString(),
            'ends_at' => now()->subMonth()->toDateString(),
            'status' => SubscriptionStatus::Active,
        ]);
        $vigenteA = activeSubscriptionFor($tenantA, $fixtureA['plan'], $fixtureA['customer'], $fixtureA['barber']);
        actingInTenant($tenantB);
        $vencidaB = RecurrenceSubscription::query()->create([
            'public_id' => Str::uuid(),
            'customer_id' => $fixtureB['customer']->getKey(),
            'plan_id' => $fixtureB['plan']->getKey(),
            'barber_id' => $fixtureB['barber']->getKey(),
            'service_id' => $fixtureB['service']->getKey(),
            'plan_name' => $fixtureB['plan']->name,
            'price' => $fixtureB['plan']->price,
            'duration_months' => 1,
            'sessions_per_month' => 2,
            'max_sessions_per_week' => 2,
            'starts_at' => now()->subMonths(2)->toDateString(),
            'ends_at' => now()->subMonth()->toDateString(),
            'status' => SubscriptionStatus::Active,
        ]);
        actingWithoutTenant();

        $this->artisan('recurrence:expire')
            ->expectsOutput('2 assinatura(s) encerrada(s).')
            ->assertExitCode(0);

        // O comando seta app.current_tenant por tenant; limpa o resíduo da última iteração.
        actingWithoutTenant();

        actingInTenant($tenantA);
        expect($vencidaA->refresh()->status)->toBe(SubscriptionStatus::Expired)
            ->and($vigenteA->refresh()->status)->toBe(SubscriptionStatus::Active);

        actingInTenant($tenantB);
        expect($vencidaB->refresh()->status)->toBe(SubscriptionStatus::Expired);

        actingWithoutTenant();
    });

    it('isola assinaturas entre barbearias e protege o detalhe pelas permissões', function () {
        [$tenantA, $adminA] = tenantWithUser(MembershipRole::Admin);
        [$tenantB] = tenantWithUser(MembershipRole::Admin);
        $fixtureA = subscriptionFlowFixture($tenantA);
        $fixtureB = subscriptionFlowFixture($tenantB);
        $subscriptionB = activeSubscriptionFor($tenantB, $fixtureB['plan'], $fixtureB['customer'], $fixtureB['barber']);

        $client = $this->withSession(['current_tenant_id' => (string) $tenantA->getKey()])
            ->actingAs($adminA);

        $client->get(route('app.recurrence.subscriptions.show', $subscriptionB))
            ->assertNotFound();

        // Financeiro tem só visualização: vê o detalhe, mas não ativa nem cancela.
        [$tenantC, $financeiro] = tenantWithUser(MembershipRole::Financeiro);
        $fixtureC = subscriptionFlowFixture($tenantC);
        $subscriptionC = activeSubscriptionFor($tenantC, $fixtureC['plan'], $fixtureC['customer'], $fixtureC['barber']);

        $clientFinanceiro = $this->withSession(['current_tenant_id' => (string) $tenantC->getKey()])
            ->actingAs($financeiro);

        $clientFinanceiro->get(route('app.recurrence.subscriptions.show', $subscriptionC))
            ->assertOk();

        $clientFinanceiro->post(route('app.recurrence.subscriptions.activate', $subscriptionC), [
            'amount' => '100.00',
            'method' => 'pix',
        ])->assertForbidden();

        $clientFinanceiro->post(route('app.recurrence.subscriptions.cuts.store', $subscriptionC), [
            'barber_id' => $fixtureC['barber']->getKey(),
            'date' => $fixtureC['tomorrow']->toDateString(),
            'time' => '09:00',
        ])->assertForbidden();

        $clientFinanceiro->post(route('app.recurrence.subscriptions.cancel', $subscriptionC))
            ->assertForbidden();
    });
});
