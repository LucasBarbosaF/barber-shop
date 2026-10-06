<?php

use App\Application\Attendance\Actions\AddAttendanceItem;
use App\Domain\Agenda\Enums\AppointmentStatus;
use App\Domain\Attendance\Enums\AttendanceStatus;
use App\Domain\Commissions\Enums\CommissionCalculationType;
use App\Domain\Commissions\Enums\CommissionPeriodStatus;
use App\Domain\Tenant\Enums\MembershipRole;
use App\Domain\Tenant\Models\Tenant;
use App\Models\Appointment;
use App\Models\Attendance;
use App\Models\AttendanceItem;
use App\Models\Barber;
use App\Models\Commission;
use App\Models\CommissionPayment;
use App\Models\CommissionRule;
use App\Models\Customer;
use App\Models\Service;
use Illuminate\Support\Carbon;

/**
 * @return array{Attendance, Barber, Service, AttendanceItem}
 */
function commissionTestAttendance(
    Tenant $tenant,
    string $closedAt = '2026-10-06 12:00:00',
    string $serviceName = 'Corte clássico',
): array {
    actingInTenant($tenant);
    $barber = Barber::factory()->create([
        'tenant_id' => $tenant->getKey(),
        'commission_percentage' => '30.00',
    ]);
    $customer = Customer::factory()->create(['tenant_id' => $tenant->getKey()]);
    $service = Service::factory()->create([
        'tenant_id' => $tenant->getKey(),
        'name' => $serviceName,
        'price' => '60.00',
    ]);
    $appointment = Appointment::query()->create([
        'customer_id' => $customer->getKey(),
        'barber_id' => $barber->getKey(),
        'service_id' => $service->getKey(),
        'starts_at' => Carbon::parse($closedAt)->subHours(2),
        'ends_at' => Carbon::parse($closedAt)->subHour(),
        'status' => AppointmentStatus::Completed,
    ]);
    $attendance = Attendance::query()->create([
        'appointment_id' => $appointment->getKey(),
        'status' => AttendanceStatus::Closed,
        'opened_at' => Carbon::parse($closedAt)->subHours(2),
        'closed_at' => Carbon::parse($closedAt),
    ]);
    $item = AttendanceItem::query()->create([
        'attendance_id' => $attendance->getKey(),
        'service_id' => $service->getKey(),
        'quantity' => 1,
        'unit_price_snapshot' => '60.00',
        'commission_percentage_snapshot' => '30.00',
        'commission_amount_snapshot' => '18.00',
    ]);
    actingWithoutTenant();

    return [$attendance, $barber, $service, $item];
}

describe('comissões e repasses', function () {
    it('usa regra específica no snapshot e preserva o valor após alterar a regra', function () {
        [$tenant, $admin] = tenantWithUser(MembershipRole::Admin);
        actingInTenant($tenant);
        $barber = Barber::factory()->create([
            'tenant_id' => $tenant->getKey(),
            'commission_percentage' => '25.00',
        ]);
        $customer = Customer::factory()->create(['tenant_id' => $tenant->getKey()]);
        $service = Service::factory()->create([
            'tenant_id' => $tenant->getKey(),
            'price' => '100.00',
        ]);
        $barber->services()->attach($service->getKey(), ['tenant_id' => $tenant->getKey()]);
        $appointment = Appointment::query()->create([
            'customer_id' => $customer->getKey(),
            'barber_id' => $barber->getKey(),
            'service_id' => $service->getKey(),
            'starts_at' => now()->addHour(),
            'ends_at' => now()->addHours(2),
            'status' => AppointmentStatus::Confirmed,
        ]);
        $attendance = Attendance::query()->create([
            'appointment_id' => $appointment->getKey(),
            'status' => AttendanceStatus::Open,
            'opened_at' => now(),
        ]);
        CommissionRule::query()->create([
            'barber_id' => $barber->getKey(),
            'percentage' => '20.00',
        ]);
        $serviceRule = CommissionRule::query()->create([
            'barber_id' => $barber->getKey(),
            'service_id' => $service->getKey(),
            'percentage' => '40.00',
        ]);

        $result = app(AddAttendanceItem::class)->execute([
            'attendance' => $attendance,
            'service' => $service,
            'quantity' => 1,
        ]);

        $serviceRule->update(['percentage' => '50.00']);

        expect($result['item']->commission_percentage_snapshot)->toBe('40.00')
            ->and($result['item']->commission_amount_snapshot)->toBe('40.00');

        $appointment = Appointment::query()->create([
            'customer_id' => $customer->getKey(),
            'barber_id' => $barber->getKey(),
            'service_id' => $service->getKey(),
            'starts_at' => now()->addHours(3),
            'ends_at' => now()->addHours(4),
            'status' => AppointmentStatus::Confirmed,
        ]);
        $fixedAttendance = Attendance::query()->create([
            'appointment_id' => $appointment->getKey(),
            'status' => AttendanceStatus::Open,
            'opened_at' => now(),
        ]);
        $client = $this->withSession(['current_tenant_id' => (string) $tenant->getKey()])
            ->actingAs($admin);
        $client->postJson(route('app.commissions.rules.store'), [
            'barber_id' => $barber->getKey(),
            'service_id' => $service->getKey(),
            'calculation_type' => CommissionCalculationType::FixedAmount->value,
            'fixed_amount' => '12.50',
        ])->assertCreated()
            ->assertJsonPath('data.fixed_amount', '12.50');
        $serviceRule->refresh();

        $fixedResult = app(AddAttendanceItem::class)->execute([
            'attendance' => $fixedAttendance,
            'service' => $service,
            'quantity' => 2,
        ]);

        expect($fixedResult['item']->commission_calculation_type_snapshot)
            ->toBe(CommissionCalculationType::FixedAmount)
            ->and($fixedResult['item']->commission_percentage_snapshot)->toBeNull()
            ->and($fixedResult['item']->commission_fixed_amount_snapshot)->toBe('12.50')
            ->and($fixedResult['item']->commission_amount_snapshot)->toBe('25.00');

        $fixedAttendance->update([
            'status' => AttendanceStatus::Closed,
            'closed_at' => now(),
        ]);
        $appointment->update(['status' => AppointmentStatus::Completed]);

        $periodId = $client->postJson(route('app.commissions.periods.close'), [
            'period_start' => now()->toDateString(),
            'period_end' => now()->toDateString(),
        ])->assertCreated()
            ->assertJsonPath('data.commissions', 1)
            ->json('data.period.id');

        $commission = Commission::query()
            ->where('attendance_item_id', $fixedResult['item']->getKey())
            ->firstOrFail();
        expect($commission->calculation_type_snapshot)->toBe(CommissionCalculationType::FixedAmount)
            ->and($commission->percentage_snapshot)->toBeNull()
            ->and($commission->fixed_amount_snapshot)->toBe('12.50')
            ->and($commission->amount)->toBe('25.00')
            ->and($commission->period_id)->toBe($periodId);
        $client->get(route('app.commissions.index'))
            ->assertOk()
            ->assertSee('R$ 12,50 por unidade');
    });

    it('fecha um período uma única vez e registra repasse idempotente pelo valor snapshot', function () {
        [$tenant, $admin] = tenantWithUser(MembershipRole::Admin);
        [$attendance, $barber, $service, $item] = commissionTestAttendance($tenant);

        $client = $this->withSession(['current_tenant_id' => (string) $tenant->getKey()])
            ->actingAs($admin);

        $periodId = $client->postJson(route('app.commissions.periods.close'), [
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-10',
        ])->assertCreated()
            ->assertJsonPath('data.commissions', 1)
            ->json('data.period.id');

        actingInTenant($tenant);
        $commission = Commission::query()->where('attendance_item_id', $item->getKey())->firstOrFail();
        expect($commission->amount)->toBe('18.00')
            ->and($commission->percentage_snapshot)->toBe('30.00')
            ->and($commission->barber_name_snapshot)->toBe($barber->name)
            ->and($commission->service_name_snapshot)->toBe($service->name);

        $client->postJson(route('app.commissions.periods.close'), [
            'period_start' => '2026-10-05',
            'period_end' => '2026-10-07',
        ])->assertUnprocessable()->assertJsonValidationErrors('period_start');

        $paymentResponse = $client->postJson(
            route('app.commissions.payments.store', $periodId),
            ['barber_id' => $barber->getKey()],
            ['Idempotency-Key' => 'commission-payment-001'],
        )->assertCreated()->assertJsonPath('data.amount', '18.00');
        $paymentId = $paymentResponse->json('data.id');

        $client->postJson(
            route('app.commissions.payments.store', $periodId),
            ['barber_id' => $barber->getKey()],
            ['Idempotency-Key' => 'commission-payment-001'],
        )->assertCreated()->assertJsonPath('data.id', $paymentId);

        actingInTenant($tenant);
        expect(CommissionPayment::query()->count())->toBe(1)
            ->and(Commission::query()->whereKey($commission->getKey())->value('commission_payment_id'))->toBe($paymentId)
            ->and($commission->period->fresh()->status)->toBe(CommissionPeriodStatus::Paid)
            ->and($attendance->fresh()->status)->toBe(AttendanceStatus::Closed);
    });

    it('reabre o modal de fechamento ao retornar erros de validação', function () {
        [$tenant, $admin] = tenantWithUser(MembershipRole::Admin);
        commissionTestAttendance($tenant);
        $client = $this->withSession(['current_tenant_id' => (string) $tenant->getKey()])
            ->actingAs($admin);

        $client->get(route('app.commissions.index'))
            ->assertOk()
            ->assertSee('data-bs-toggle="modal"', false)
            ->assertSee('data-bs-target="#closeCommissionPeriodModal"', false)
            ->assertSee('id="closeCommissionPeriodModal"', false)
            ->assertSee('Apurar período');

        $client->postJson(route('app.commissions.periods.close'), [
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-10',
        ])->assertCreated();

        $client->from(route('app.commissions.index'))->post(route('app.commissions.periods.close'), [
            'period_start' => '2026-10-05',
            'period_end' => '2026-10-12',
        ])->assertRedirect(route('app.commissions.index'))
            ->assertSessionHasErrors('period_start');
    });

    it('filtra comissões por período, barbeiro e situação do repasse', function () {
        [$tenant, $admin] = tenantWithUser(MembershipRole::Admin);
        [, $barberOne] = commissionTestAttendance($tenant, '2026-10-06 12:00:00');
        [, $barberTwo] = commissionTestAttendance($tenant, '2026-10-15 12:00:00', 'Barba');
        $client = $this->withSession(['current_tenant_id' => (string) $tenant->getKey()])
            ->actingAs($admin);

        $firstPeriodId = $client->postJson(route('app.commissions.periods.close'), [
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-10',
        ])->assertCreated()->json('data.period.id');
        $secondPeriodId = $client->postJson(route('app.commissions.periods.close'), [
            'period_start' => '2026-10-11',
            'period_end' => '2026-10-20',
        ])->assertCreated()->json('data.period.id');

        $client->get(route('app.commissions.index', [
            'period_id' => $secondPeriodId,
            'barber_id' => $barberTwo->getKey(),
            'status' => 'pending',
        ]))->assertOk()
            ->assertSee($barberTwo->name)
            ->assertSee('Pendente')
            ->assertSee('Total apurado na seleção');

        $client->get(route('app.commissions.index', [
            'period_id' => $secondPeriodId,
            'barber_id' => $barberOne->getKey(),
            'status' => 'pending',
        ]))->assertOk()
            ->assertSee('0 lançamento(s)')
            ->assertSee('Nenhum lançamento corresponde aos filtros selecionados.');

        $client->postJson(
            route('app.commissions.payments.store', $firstPeriodId),
            ['barber_id' => $barberOne->getKey()],
            ['Idempotency-Key' => 'commission-filter-paid'],
        )->assertCreated();

        $client->get(route('app.commissions.index', [
            'period_id' => $firstPeriodId,
            'barber_id' => $barberOne->getKey(),
            'status' => 'paid',
        ]))->assertOk()
            ->assertSee($barberOne->name)
            ->assertSee('Repassada');
    });

    it('isola as comissões entre tenants e exige permissão para gerenciá-las', function () {
        [$tenantA, $adminA] = tenantWithUser(MembershipRole::Admin);
        [$tenantB, $adminB] = tenantWithUser(MembershipRole::Admin);
        commissionTestAttendance($tenantA);
        $clientA = $this->withSession(['current_tenant_id' => (string) $tenantA->getKey()])
            ->actingAs($adminA);
        $periodId = $clientA->postJson(route('app.commissions.periods.close'), [
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-10',
        ])->assertCreated()->json('data.period.id');

        actingInTenant($tenantB);
        expect(Commission::query()->count())->toBe(0)
            ->and(CommissionPayment::query()->count())->toBe(0);

        $clientB = $this->withSession(['current_tenant_id' => (string) $tenantB->getKey()])
            ->actingAs($adminB);
        $clientB->get(route('app.commissions.index'))->assertOk();
        $clientB->postJson(route('app.commissions.payments.store', $periodId), [
            'barber_id' => 1,
        ], ['Idempotency-Key' => 'cross-tenant-payment'])->assertNotFound();

        [$receptionist] = addMemberTo($tenantB, MembershipRole::Receptionist);
        $this->withSession(['current_tenant_id' => (string) $tenantB->getKey()])
            ->actingAs($receptionist)
            ->postJson(route('app.commissions.periods.close'), [
                'period_start' => '2026-10-01',
                'period_end' => '2026-10-10',
            ])->assertForbidden();
    });
});
