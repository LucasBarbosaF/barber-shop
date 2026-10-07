<?php

use App\Domain\Agenda\Enums\AppointmentStatus;
use App\Domain\Tenant\Enums\MembershipRole;
use App\Domain\Tenant\Models\Tenant;
use App\Models\Appointment;
use App\Models\Barber;
use App\Models\Customer;
use App\Models\Service;

function appointmentForStatusChange(
    Tenant $tenant,
    AppointmentStatus $status = AppointmentStatus::Pending,
    ?int $barberUserId = null,
): Appointment {
    actingInTenant($tenant);
    $barber = Barber::factory()->create([
        'tenant_id' => $tenant->getKey(),
        'user_id' => $barberUserId,
    ]);
    $customer = Customer::factory()->create(['tenant_id' => $tenant->getKey()]);
    $service = Service::factory()->create(['tenant_id' => $tenant->getKey()]);
    $appointment = Appointment::query()->create([
        'customer_id' => $customer->getKey(),
        'barber_id' => $barber->getKey(),
        'service_id' => $service->getKey(),
        'starts_at' => now()->addHour(),
        'ends_at' => now()->addHours(2),
        'status' => $status,
    ]);
    actingWithoutTenant();

    return $appointment;
}

describe('status do agendamento na agenda', function () {
    it('muda o status pela rota e avisa com o toast do painel', function () {
        [$tenant, $admin] = tenantWithUser(MembershipRole::Admin);
        $appointment = appointmentForStatusChange($tenant);

        $this->withSession(['current_tenant_id' => (string) $tenant->getKey()])
            ->actingAs($admin)
            ->from(route('app.agenda.index'))
            ->patch(route('app.agenda.appointments.status', $appointment), [
                'status' => AppointmentStatus::Confirmed->value,
            ])
            ->assertRedirect(route('app.agenda.index'))
            ->assertSessionHas(
                'status',
                'Status do agendamento alterado para "Confirmado".',
            );

        actingInTenant($tenant);
        expect(Appointment::query()->findOrFail($appointment->getKey())->status)
            ->toBe(AppointmentStatus::Confirmed);
        actingWithoutTenant();
    });

    it('recusa transição fora da matriz e não toca no banco', function () {
        [$tenant, $admin] = tenantWithUser(MembershipRole::Admin);
        $appointment = appointmentForStatusChange($tenant, AppointmentStatus::Canceled);

        $this->withSession(['current_tenant_id' => (string) $tenant->getKey()])
            ->actingAs($admin)
            ->from(route('app.agenda.index'))
            ->patch(route('app.agenda.appointments.status', $appointment), [
                'status' => AppointmentStatus::Arrived->value,
            ])
            ->assertSessionHasErrors('status');

        actingInTenant($tenant);
        expect(Appointment::query()->findOrFail($appointment->getKey())->status)
            ->toBe(AppointmentStatus::Canceled);
        actingWithoutTenant();
    });

    it('não deixa a agenda mudar para em atendimento nem concluído', function () {
        [$tenant, $admin] = tenantWithUser(MembershipRole::Admin);
        $appointment = appointmentForStatusChange($tenant, AppointmentStatus::Confirmed);

        expect(AppointmentStatus::InService->targets())->toBe([])
            ->and(AppointmentStatus::Completed->targets())->toBe([]);

        $client = $this->withSession(['current_tenant_id' => (string) $tenant->getKey()])
            ->actingAs($admin)
            ->from(route('app.agenda.index'));

        $client->patch(route('app.agenda.appointments.status', $appointment), [
            'status' => AppointmentStatus::InService->value,
        ])->assertSessionHasErrors('status');

        $client->patch(route('app.agenda.appointments.status', $appointment), [
            'status' => AppointmentStatus::Completed->value,
        ])->assertSessionHasErrors('status');

        actingInTenant($tenant);
        expect(Appointment::query()->findOrFail($appointment->getKey())->status)
            ->toBe(AppointmentStatus::Confirmed);
        actingWithoutTenant();
    });

    it('devolve json para quem pede com expectsJson', function () {
        [$tenant, $admin] = tenantWithUser(MembershipRole::Admin);
        $appointment = appointmentForStatusChange($tenant);

        $this->withSession(['current_tenant_id' => (string) $tenant->getKey()])
            ->actingAs($admin)
            ->patchJson(route('app.agenda.appointments.status', $appointment), [
                'status' => AppointmentStatus::NoShow->value,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', AppointmentStatus::NoShow->value);

        $this->withSession(['current_tenant_id' => (string) $tenant->getKey()])
            ->actingAs($admin)
            ->patchJson(route('app.agenda.appointments.status', $appointment), [
                'status' => AppointmentStatus::Arrived->value,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');
    });

    it('deixa o barbeiro mudar só o status dos próprios agendamentos', function () {
        [$tenant] = tenantWithUser(MembershipRole::Admin);
        [$barberUser] = addMemberTo($tenant, MembershipRole::Barber);
        $own = appointmentForStatusChange($tenant, AppointmentStatus::Pending, $barberUser->getKey());
        $fromOtherBarber = appointmentForStatusChange($tenant, AppointmentStatus::Pending);

        $client = $this->withSession(['current_tenant_id' => (string) $tenant->getKey()])
            ->actingAs($barberUser)
            ->from(route('app.agenda.index'));

        $client->patch(route('app.agenda.appointments.status', $own), [
            'status' => AppointmentStatus::Confirmed->value,
        ])->assertRedirect(route('app.agenda.index'));

        $client->patch(route('app.agenda.appointments.status', $fromOtherBarber), [
            'status' => AppointmentStatus::Canceled->value,
        ])->assertForbidden();

        actingInTenant($tenant);
        expect(Appointment::query()->findOrFail($own->getKey())->status)
            ->toBe(AppointmentStatus::Confirmed)
            ->and(Appointment::query()->findOrFail($fromOtherBarber->getKey())->status)
            ->toBe(AppointmentStatus::Pending);
        actingWithoutTenant();
    });

    it('exige a permissão schedule.manage e mantém o isolamento entre tenants', function () {
        [$tenant, $financeiro] = tenantWithUser(MembershipRole::Financeiro);
        $appointment = appointmentForStatusChange($tenant);

        $this->withSession(['current_tenant_id' => (string) $tenant->getKey()])
            ->actingAs($financeiro)
            ->patchJson(route('app.agenda.appointments.status', $appointment), [
                'status' => AppointmentStatus::Confirmed->value,
            ])
            ->assertForbidden();

        [$otherTenant, $otherAdmin] = tenantWithUser(MembershipRole::Admin);
        $this->withSession(['current_tenant_id' => (string) $otherTenant->getKey()])
            ->actingAs($otherAdmin)
            ->patchJson(route('app.agenda.appointments.status', $appointment), [
                'status' => AppointmentStatus::Confirmed->value,
            ])
            ->assertNotFound();
    });

    it('oferece o menu de status na lista da agenda', function () {
        [$tenant, $admin] = tenantWithUser(MembershipRole::Admin);
        appointmentForStatusChange($tenant, AppointmentStatus::Pending);

        $this->withSession(['current_tenant_id' => (string) $tenant->getKey()])
            ->actingAs($admin)
            ->get(route('app.agenda.index', ['view' => 'list', 'start_date' => now()->toDateString()]))
            ->assertOk()
            ->assertSee('Mudar status')
            ->assertSee('Confirmado')
            ->assertSee('Cancelado')
            ->assertSee('Não compareceu')
            ->assertSee('name="status"', escape: false);
    });

    it('esconde o menu quando o status não tem transição (em atendimento/concluído)', function () {
        [$tenant, $admin] = tenantWithUser(MembershipRole::Admin);
        appointmentForStatusChange($tenant, AppointmentStatus::Completed);

        $this->withSession(['current_tenant_id' => (string) $tenant->getKey()])
            ->actingAs($admin)
            ->get(route('app.agenda.index', ['view' => 'list', 'start_date' => now()->toDateString()]))
            ->assertOk()
            ->assertSee('Concluído')
            ->assertDontSee('Mudar status');
    });
});
