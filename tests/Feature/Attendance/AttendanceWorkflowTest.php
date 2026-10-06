<?php

use App\Domain\Agenda\Enums\AppointmentStatus;
use App\Domain\Attendance\Enums\AttendanceStatus;
use App\Domain\Tenant\Enums\MembershipRole;
use App\Domain\Tenant\Models\Tenant;
use App\Models\Appointment;
use App\Models\Attendance;
use App\Models\AttendanceItem;
use App\Models\Barber;
use App\Models\Customer;
use App\Models\Service;
use Illuminate\Database\QueryException;

function appointmentForAttendance(
    Tenant $tenant,
    string $commission = '37.50',
    string $price = '55.00',
): array {
    actingInTenant($tenant);
    $barber = Barber::factory()->create([
        'tenant_id' => $tenant->getKey(),
        'commission_percentage' => $commission,
    ]);
    $customer = Customer::factory()->create(['tenant_id' => $tenant->getKey()]);
    $service = Service::factory()->create([
        'tenant_id' => $tenant->getKey(),
        'price' => $price,
    ]);
    $appointment = Appointment::query()->create([
        'customer_id' => $customer->getKey(),
        'barber_id' => $barber->getKey(),
        'service_id' => $service->getKey(),
        'starts_at' => now()->addHour(),
        'ends_at' => now()->addHours(2),
        'status' => AppointmentStatus::Confirmed,
    ]);
    actingWithoutTenant();

    return [$appointment, $barber, $customer, $service];
}

describe('atendimento', function () {
    it('abre a partir do agendamento e preserva snapshots de preço e comissão', function () {
        [$tenant, $admin] = tenantWithUser(MembershipRole::Admin);
        [$appointment, $barber, , $service] = appointmentForAttendance($tenant);
        $client = $this->withSession(['current_tenant_id' => (string) $tenant->getKey()])
            ->actingAs($admin);

        $opened = $client->postJson(route('app.attendances.open', $appointment))
            ->assertCreated()
            ->assertJsonPath('data.status', AttendanceStatus::Open->value)
            ->assertJsonPath('data.items.0.unit_price_snapshot', '55.00')
            ->assertJsonPath('data.items.0.commission_percentage_snapshot', '37.50')
            ->assertJsonPath('data.items.0.commission_amount_snapshot', '20.63');

        $attendanceId = $opened->json('data.id');
        $client->postJson(route('app.attendances.open', $appointment))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('appointment');

        actingInTenant($tenant);
        $service->update(['price' => '70.00']);
        $barber->update(['commission_percentage' => '50.00']);
        actingWithoutTenant();

        $client->getJson(route('app.attendances.show', $attendanceId))
            ->assertOk()
            ->assertJsonPath('data.items.0.unit_price_snapshot', '55.00')
            ->assertJsonPath('data.items.0.commission_percentage_snapshot', '37.50')
            ->assertJsonPath('data.items.0.commission_amount_snapshot', '20.63');

        actingInTenant($tenant);
        expect(Appointment::query()->findOrFail($appointment->getKey())->status)
            ->toBe(AppointmentStatus::InService);
        actingWithoutTenant();

        $client->patchJson(route('app.attendances.close', $attendanceId))
            ->assertOk()
            ->assertJsonPath('data.status', AttendanceStatus::Closed->value);

        $client->postJson(route('app.attendances.items.store', $attendanceId), [
            'service_id' => $service->getKey(),
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('attendance');

        actingInTenant($tenant);
        expect(Appointment::query()->findOrFail($appointment->getKey())->status)
            ->toBe(AppointmentStatus::Completed)
            ->and(AttendanceItem::query()->where('attendance_id', $attendanceId)->count())->toBe(1);
    });

    it('adiciona serviço com quantidade e snapshot próprio ao atendimento aberto', function () {
        [$tenant, $admin] = tenantWithUser(MembershipRole::Admin);
        [$appointment] = appointmentForAttendance($tenant, '20.00', '30.85');
        actingInTenant($tenant);
        $secondService = Service::factory()->create([
            'tenant_id' => $tenant->getKey(),
            'name' => 'Barba adicional',
            'price' => '30.85',
        ]);
        actingWithoutTenant();

        $client = $this->withSession(['current_tenant_id' => (string) $tenant->getKey()])
            ->actingAs($admin);
        $attendanceId = $client->postJson(route('app.attendances.open', $appointment))
            ->assertCreated()
            ->json('data.id');

        $client->postJson(route('app.attendances.items.store', $attendanceId), [
            'service_id' => $secondService->getKey(),
            'quantity' => 2,
        ])
            ->assertCreated()
            ->assertJsonPath('data.unit_price_snapshot', '30.85')
            ->assertJsonPath('data.commission_percentage_snapshot', '20.00')
            ->assertJsonPath('data.commission_amount_snapshot', '12.34');

        actingInTenant($tenant);
        expect(AttendanceItem::query()->where('attendance_id', $attendanceId)->count())->toBe(2);
    });

    it('rejeita abrir atendimento sem percentual de comissão configurado', function () {
        [$tenant, $admin] = tenantWithUser(MembershipRole::Admin);
        [$appointment, $barber] = appointmentForAttendance($tenant);
        actingInTenant($tenant);
        $barber->update(['commission_percentage' => null]);
        actingWithoutTenant();

        $this->withSession(['current_tenant_id' => (string) $tenant->getKey()])
            ->actingAs($admin)
            ->postJson(route('app.attendances.open', $appointment))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('commission_percentage');

        actingInTenant($tenant);
        expect(Attendance::query()->count())->toBe(0);
    });

    it('bloqueia relacionamentos e linhas de atendimento entre tenants', function () {
        [$tenantA, $adminA] = tenantWithUser(MembershipRole::Admin);
        [$tenantB] = tenantWithUser(MembershipRole::Admin);
        [$appointmentA, , , $serviceA] = appointmentForAttendance($tenantA);
        [$appointmentB] = appointmentForAttendance($tenantB);

        actingInTenant($tenantB);
        $attendanceB = Attendance::query()->create([
            'tenant_id' => $tenantB->getKey(),
            'appointment_id' => $appointmentB->getKey(),
            'status' => AttendanceStatus::Open,
            'opened_at' => now(),
        ]);
        $otherService = Service::factory()->create(['tenant_id' => $tenantB->getKey()]);

        actingInTenant($tenantA);
        $attendance = Attendance::query()->create([
            'tenant_id' => $tenantA->getKey(),
            'appointment_id' => $appointmentA->getKey(),
            'status' => AttendanceStatus::Open,
            'opened_at' => now(),
        ]);

        expect(failedWrite(fn () => AttendanceItem::query()->create([
            'tenant_id' => $tenantA->getKey(),
            'attendance_id' => $attendance->getKey(),
            'service_id' => $otherService->getKey(),
            'quantity' => 1,
            'unit_price_snapshot' => '10.00',
            'commission_percentage_snapshot' => '10.00',
            'commission_amount_snapshot' => '1.00',
        ])))->toBeInstanceOf(QueryException::class);

        actingWithoutTenant();
        $client = $this->withSession(['current_tenant_id' => (string) $tenantA->getKey()])
            ->actingAs($adminA);
        $client->getJson(route('app.attendances.show', $attendanceB->getKey()))->assertNotFound();

        actingInTenant($tenantA);
        expect($serviceA->tenant_id)->toBe($tenantA->getKey());
    });

    it('oferece a tela HTML para iniciar, compor, fechar e receber o atendimento', function () {
        [$tenant, $admin] = tenantWithUser(MembershipRole::Admin);
        [$appointment, , , $service] = appointmentForAttendance($tenant, '35.00', '80.00');
        $client = $this->withSession(['current_tenant_id' => (string) $tenant->getKey()])
            ->actingAs($admin);

        $client->get(route('app.agenda.index'))
            ->assertOk()
            ->assertSee('Iniciar atendimento');

        $attendanceId = $client->post(route('app.attendances.open', $appointment))
            ->assertRedirect()
            ->assertSessionHas('status', 'Atendimento iniciado.')
            ->headers->get('Location');
        $attendanceId = (int) basename((string) parse_url($attendanceId, PHP_URL_PATH));

        $client->get(route('app.attendances.show', $attendanceId))
            ->assertOk()
            ->assertSee('Serviços realizados')
            ->assertSee($service->name)
            ->assertSee('Fechar atendimento');

        $client->post(route('app.attendances.items.store', $attendanceId), [
            'service_id' => $service->getKey(),
            'quantity' => 2,
        ])->assertRedirect(route('app.attendances.show', $attendanceId));

        $client->patch(route('app.attendances.close', $attendanceId))
            ->assertRedirect(route('app.attendances.show', $attendanceId))
            ->assertSessionHas('status', 'Atendimento fechado.');

        $client->get(route('app.attendances.show', $attendanceId))
            ->assertOk()
            ->assertSee('Registrar recebimento')
            ->assertSee('R$ 240,00');

        $client->post(route('app.payments.store', $attendanceId), [
            'amount' => '80.00',
            'method' => 'pix',
            'idempotency_key' => 'attendance-screen-payment-001',
        ])->assertRedirect(route('app.attendances.show', $attendanceId))
            ->assertSessionHas('status', 'Pagamento registrado.');

        $client->get(route('app.attendances.show', $attendanceId))
            ->assertOk()
            ->assertSee('PIX')
            ->assertSee('R$ 160,00');
    });
});
