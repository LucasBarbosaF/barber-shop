<?php

use App\Application\Recurrence\Actions\CreateSubscription;
use App\Application\Recurrence\Actions\ScheduleSubscriptionCut;
use App\Application\Recurrence\RecurrenceQuota;
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
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/*
 * As três cotas da recorrência: mensal (sem acúmulo), semanal (teto 2) e
 * total (duração × mensal). Contagem = agendamentos vinculados com status
 * != canceled; no_show consome, cancelar devolve.
 */

/**
 * @return array{barber: Barber, service: Service, plan: RecurrencePlan, customer: Customer}
 */
function quotaFixture(Tenant $tenant, array $planAttributes = []): array
{
    [$barberUser] = addMemberTo($tenant, MembershipRole::Barber);
    actingInTenant($tenant);

    $barber = Barber::factory()->create([
        'tenant_id' => $tenant->getKey(),
        'name' => 'Barbeiro Cota',
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
        'name' => 'Clube Cota',
        'duration_months' => 6,
        'sessions_per_month' => 2,
        'max_sessions_per_week' => 2,
        'price' => '200.00',
    ], $planAttributes));

    $customer = Customer::factory()->create([
        'tenant_id' => $tenant->getKey(),
        'name' => 'Cliente Cota',
        'phone' => '11999887744',
    ]);

    actingWithoutTenant();

    return compact('barber', 'service', 'plan', 'customer');
}

function quotaSubscription(Tenant $tenant, RecurrencePlan $plan, Customer $customer, Barber $barber, CarbonImmutable $startsAt): RecurrenceSubscription
{
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
        'starts_at' => $startsAt->toDateString(),
        'ends_at' => $startsAt->addMonths($plan->duration_months)->toDateString(),
        'status' => SubscriptionStatus::Active,
        'paid_amount' => $plan->price,
        'paid_at' => now(),
    ]);
    markQuotaMonthsPaid($tenant, $subscription);
    actingWithoutTenant();

    return $subscription;
}

/**
 * Toda a janela do contrato marcada como paga: os testes de cota medem cotas,
 * não cobrança — o gate de mês pago (RecurrenceQuota::assertCanSchedule) não
 * pode ser a causa da negação num teste de cota.
 */
function markQuotaMonthsPaid(Tenant $tenant, RecurrenceSubscription $subscription): void
{
    actingInTenant($tenant);

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

    actingWithoutTenant();
}

function quotaLinkCut(RecurrenceSubscription $subscription, CarbonImmutable $startsAt, AppointmentStatus $status = AppointmentStatus::Confirmed): Appointment
{
    return Appointment::query()->create([
        'customer_id' => $subscription->customer_id,
        'barber_id' => $subscription->barber_id,
        'service_id' => $subscription->service_id,
        'recurrence_subscription_id' => $subscription->getKey(),
        'starts_at' => $startsAt,
        'ends_at' => $startsAt->addMinutes(30),
        'status' => $status,
    ]);
}

describe('cotas da recorrência', function () {
    it('esgota a cota mensal sem acumular para o próximo mês', function () {
        [$tenant] = tenantWithUser(MembershipRole::Admin);
        $fixture = quotaFixture($tenant);
        $startsAt = CarbonImmutable::now(config('app.timezone'))->startOfMonth();
        $subscription = quotaSubscription($tenant, $fixture['plan'], $fixture['customer'], $fixture['barber'], $startsAt);

        $day5 = $startsAt->addDays(4)->setTime(9, 0);
        $day10 = $startsAt->addDays(9)->setTime(10, 0);
        $day15 = $startsAt->addDays(14)->setTime(9, 0);
        $nextMonth = $startsAt->addMonth()->addDays(2)->setTime(9, 0);

        actingInTenant($tenant);
        quotaLinkCut($subscription, $day5);
        quotaLinkCut($subscription, $day10);

        $quota = app(RecurrenceQuota::class);

        expect($quota->usedInMonth($subscription, $day5))->toBe(2)
            ->and($quota->remainingInMonth($subscription, $day5))->toBe(0)
            ->and($quota->usedInTerm($subscription))->toBe(2)
            ->and($quota->remainingInTerm($subscription))->toBe(10);

        // Terceiro corte no mesmo mês é recusado.
        expect(fn () => $quota->assertCanSchedule($subscription, $day15))
            ->toThrow(ValidationException::class, 'Você já usou os 2 cortes de');

        // O mês seguinte libera de novo (cota sem acúmulo, mas teto total continua).
        $quota->assertCanSchedule($subscription, $nextMonth);

        actingWithoutTenant();
    });

    it('impede mais de dois cortes na mesma semana', function () {
        [$tenant] = tenantWithUser(MembershipRole::Admin);
        // sessions_per_month > max_sessions_week para isolar o teto semanal
        // do teto mensal (a checagem mensal vem primeiro na action).
        $fixture = quotaFixture($tenant, [
            'sessions_per_month' => 3,
            'max_sessions_per_week' => 2,
        ]);
        $startsAt = CarbonImmutable::now(config('app.timezone'))->startOfMonth();
        $subscription = quotaSubscription($tenant, $fixture['plan'], $fixture['customer'], $fixture['barber'], $startsAt);

        $monday = $startsAt->next(CarbonImmutable::MONDAY)->setTime(9, 0);
        $tuesday = $monday->addDay()->setTime(9, 0);
        $wednesday = $monday->addDays(2)->setTime(9, 0);

        actingInTenant($tenant);
        quotaLinkCut($subscription, $monday);
        quotaLinkCut($subscription, $tuesday);

        $quota = app(RecurrenceQuota::class);

        expect(fn () => $quota->assertCanSchedule($subscription, $wednesday))
            ->toThrow(ValidationException::class, 'Máximo de 2 cortes por semana');

        // Próxima semana do mesmo mês: semanal liberada, mensal ainda não estourou.
        $nextWeek = $monday->addWeek()->setTime(9, 0);
        $quota->assertCanSchedule($subscription, $nextWeek);

        actingWithoutTenant();
    });

    it('respeita o teto total duration × mensal', function () {
        [$tenant] = tenantWithUser(MembershipRole::Admin);
        $fixture = quotaFixture($tenant, [
            'duration_months' => 1,
            'sessions_per_month' => 2,
            'max_sessions_per_week' => 2,
        ]);
        $startsAt = CarbonImmutable::now(config('app.timezone'))->startOfMonth();
        $subscription = quotaSubscription($tenant, $fixture['plan'], $fixture['customer'], $fixture['barber'], $startsAt);

        $day3 = $startsAt->addDays(2)->setTime(9, 0);
        $day10 = $startsAt->addDays(9)->setTime(9, 0);
        $day20 = $startsAt->addDays(19)->setTime(9, 0);

        actingInTenant($tenant);
        quotaLinkCut($subscription, $day3);
        quotaLinkCut($subscription, $day10);

        $quota = app(RecurrenceQuota::class);

        expect($quota->usedInTerm($subscription))->toBe(2)
            ->and($quota->remainingInTerm($subscription))->toBe(0);

        expect(fn () => $quota->assertCanSchedule($subscription, $day20))
            ->toThrow(ValidationException::class, 'Os 2 cortes do seu plano já foram usados.');

        actingWithoutTenant();
    });

    it('cancelar devolve a cota e no_show continua consumindo', function () {
        [$tenant] = tenantWithUser(MembershipRole::Admin);
        $fixture = quotaFixture($tenant);
        $startsAt = CarbonImmutable::now(config('app.timezone'))->startOfMonth();
        $subscription = quotaSubscription($tenant, $fixture['plan'], $fixture['customer'], $fixture['barber'], $startsAt);

        $day5 = $startsAt->addDays(4)->setTime(9, 0);
        $day10 = $startsAt->addDays(9)->setTime(9, 0);
        $day15 = $startsAt->addDays(14)->setTime(9, 0);

        actingInTenant($tenant);
        $first = quotaLinkCut($subscription, $day5);
        $second = quotaLinkCut($subscription, $day10);

        $quota = app(RecurrenceQuota::class);
        expect($quota->remainingInMonth($subscription, $day5))->toBe(0);

        // Cancelar um dos dois devolve a cota mensal.
        $first->update(['status' => AppointmentStatus::Canceled]);

        expect($quota->usedInMonth($subscription, $day5))->toBe(1)
            ->and($quota->remainingInMonth($subscription, $day5))->toBe(1);

        $quota->assertCanSchedule($subscription, $day15);

        // no_show ocupa o horário e consome corte: volta a zerar a cota.
        $second->update(['status' => AppointmentStatus::NoShow]);

        expect($quota->usedInMonth($subscription, $day10))->toBe(1)
            ->and($quota->remainingInMonth($subscription, $day10))->toBe(1);

        // Ainda cabe o terceiro corte do mês (1 no_show + 1 cancelado = 1 usado).
        $quota->assertCanSchedule($subscription, $day15);

        // Quarto tenta estourar: mas o plano é 2/mês, e já há 1 usado (no_show).
        // Criar mais um confirmed para zerar de novo.
        quotaLinkCut($subscription, $day15);

        expect($quota->usedInMonth($subscription, $day15))->toBe(2)
            ->and($quota->remainingInMonth($subscription, $day15))->toBe(0);

        $nextDay = $startsAt->addDays(20)->setTime(9, 0);
        expect(fn () => $quota->assertCanSchedule($subscription, $nextDay))
            ->toThrow(ValidationException::class, 'Você já usou os 2 cortes de');

        actingWithoutTenant();
    });

    it('recusa data fora da vigência da assinatura', function () {
        [$tenant] = tenantWithUser(MembershipRole::Admin);
        $fixture = quotaFixture($tenant, ['duration_months' => 1]);
        $startsAt = CarbonImmutable::now(config('app.timezone'))->startOfMonth();
        $subscription = quotaSubscription($tenant, $fixture['plan'], $fixture['customer'], $fixture['barber'], $startsAt);

        $before = $startsAt->subDay()->setTime(9, 0);
        $after = $startsAt->addMonthsNoOverflow(1)->addDay()->setTime(9, 0);

        $quota = app(RecurrenceQuota::class);

        actingInTenant($tenant);
        expect(fn () => $quota->assertCanSchedule($subscription, $before))
            ->toThrow(ValidationException::class, 'Seu plano vale de');

        expect(fn () => $quota->assertCanSchedule($subscription, $after))
            ->toThrow(ValidationException::class, 'Seu plano vale de');

        actingWithoutTenant();
    });

    it('recusa agendar sem ter pago, exceto o corte de abertura', function () {
        [$tenant] = tenantWithUser(MembershipRole::Admin);
        $fixture = quotaFixture($tenant);
        $startsAt = CarbonImmutable::now(config('app.timezone'))->startOfMonth();

        actingInTenant($tenant);
        $subscription = RecurrenceSubscription::query()->create([
            'public_id' => Str::uuid(),
            'customer_id' => $fixture['customer']->getKey(),
            'plan_id' => $fixture['plan']->getKey(),
            'barber_id' => $fixture['barber']->getKey(),
            'service_id' => $fixture['service']->getKey(),
            'plan_name' => $fixture['plan']->name,
            'price' => $fixture['plan']->price,
            'duration_months' => 6,
            'sessions_per_month' => 2,
            'max_sessions_per_week' => 2,
            'starts_at' => $startsAt->toDateString(),
            'ends_at' => $startsAt->addMonths(6)->toDateString(),
            'status' => SubscriptionStatus::PendingPayment,
        ]);
        actingWithoutTenant();

        actingInTenant($tenant);
        $quota = app(RecurrenceQuota::class);
        $date = $startsAt->addDays(3)->setTime(9, 0);

        expect(fn () => $quota->assertCanSchedule($subscription, $date))
            ->toThrow(ValidationException::class, 'Aguarde a barbearia confirmar o pagamento');

        // O corte de abertura (allowUnpaid) continua permitido, cotas valem.
        $quota->assertCanSchedule($subscription, $date, allowUnpaid: true);

        actingWithoutTenant();
    });

    it('revalida a cota dentro da transação que cria o agendamento', function () {
        [$tenant] = tenantWithUser(MembershipRole::Admin);
        $fixture = quotaFixture($tenant);
        $tomorrow = CarbonImmutable::now(config('app.timezone'))->addDay()->setTime(9, 0);

        actingInTenant($tenant);
        $subscription = RecurrenceSubscription::query()->create([
            'public_id' => Str::uuid(),
            'customer_id' => $fixture['customer']->getKey(),
            'plan_id' => $fixture['plan']->getKey(),
            'barber_id' => $fixture['barber']->getKey(),
            'service_id' => $fixture['service']->getKey(),
            'plan_name' => $fixture['plan']->name,
            'price' => $fixture['plan']->price,
            'duration_months' => 1,
            'sessions_per_month' => 1,
            'max_sessions_per_week' => 2,
            'starts_at' => now()->toDateString(),
            'ends_at' => now()->addMonth()->toDateString(),
            'status' => SubscriptionStatus::Active,
            'paid_amount' => '100.00',
            'paid_at' => now(),
        ]);

        // Sem mensalidade paga o gate de mês recusaria antes da cota.
        markQuotaMonthsPaid($tenant, $subscription);
        actingInTenant($tenant);

        $scheduleCut = app(ScheduleSubscriptionCut::class);

        $result = $scheduleCut->execute([
            'subscription' => $subscription,
            'barber_id' => $fixture['barber']->getKey(),
            'date' => $tomorrow->toDateString(),
            'time' => '09:00',
        ]);

        expect($result['appointment']->recurrence_subscription_id)->toBe($subscription->getKey());

        // Segundo corte no mesmo mês: o teto total (1 mês × 1 corte = 1) é o
        // primeiro a recusar, antes da checagem mensal.
        expect(fn () => $scheduleCut->execute([
            'subscription' => $subscription->refresh(),
            'barber_id' => $fixture['barber']->getKey(),
            'date' => $tomorrow->addDay()->toDateString(),
            'time' => '09:00',
        ]))->toThrow(ValidationException::class, 'Os 1 cortes do seu plano já foram usados.');

        actingWithoutTenant();
    });

    it('cria assinatura pelo site com corte de abertura cobrando a cota', function () {
        [$tenant] = tenantWithUser(MembershipRole::Admin);
        $fixture = quotaFixture($tenant);
        $tomorrow = CarbonImmutable::now(config('app.timezone'))->addDay();

        actingInTenant($tenant);
        $result = app(CreateSubscription::class)->execute([
            'plan_id' => $fixture['plan']->getKey(),
            'starts_at' => $tomorrow->toDateString(),
            'customer_name' => 'Cliente Site',
            'customer_phone' => '11999887755',
            'barber_id' => $fixture['barber']->getKey(),
            'first_appointment_date' => $tomorrow->toDateString(),
            'first_appointment_time' => '09:00',
        ]);

        $subscription = $result['subscription'];

        expect($subscription->status)->toBe(SubscriptionStatus::PendingPayment)
            ->and($result['appointment'])->not->toBeNull()
            ->and($result['appointment']->recurrence_subscription_id)->toBe($subscription->getKey());

        // O corte de abertura conta na cota mensal.
        $quota = app(RecurrenceQuota::class);
        expect($quota->usedInMonth($subscription, $tomorrow))->toBe(1)
            ->and($quota->usedInTerm($subscription))->toBe(1);

        actingWithoutTenant();
    });
});
