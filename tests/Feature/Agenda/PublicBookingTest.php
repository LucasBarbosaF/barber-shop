<?php

use App\Application\Agenda\Actions\ListAvailableSlots;
use App\Domain\Tenant\Enums\MembershipRole;
use App\Domain\Tenant\Models\Tenant;
use App\Models\Appointment;
use App\Models\AppointmentNotification;
use App\Models\Barber;
use App\Models\BlockedPeriod;
use App\Models\BusinessHour;
use App\Models\Customer;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;

function createBookableBarberAndService(
    Tenant $tenant,
    ?User $barberUser = null,
    int $duration = 30,
): array {
    actingInTenant($tenant);
    if ($barberUser === null) {
        [$barberUser] = addMemberTo($tenant, MembershipRole::Barber);
        actingInTenant($tenant);
    }

    $barber = Barber::factory()->create([
        'tenant_id' => $tenant->getKey(),
        'name' => 'Barbeiro Agenda',
        'user_id' => $barberUser?->getKey(),
    ]);
    $service = Service::factory()->create([
        'tenant_id' => $tenant->getKey(),
        'duration_minutes' => $duration,
    ]);
    $barber->services()->attach($service->getKey(), ['tenant_id' => $tenant->getKey()]);
    $date = CarbonImmutable::now(config('app.timezone'))->addDay();
    BusinessHour::query()->create([
        'tenant_id' => $tenant->getKey(),
        'barber_id' => $barber->getKey(),
        'weekday' => $date->dayOfWeek,
        'opens_at' => '09:00',
        'closes_at' => '12:00',
    ]);
    actingWithoutTenant();

    return [$barber, $service, $date];
}

describe('agendamento público', function () {
    it('lista os horários disponíveis conforme expediente e duração do serviço', function () {
        [$tenant] = tenantWithUser(MembershipRole::Admin);
        [$barber, $service, $date] = createBookableBarberAndService($tenant);

        $this->get(route('booking.show', $tenant->slug))
            ->assertOk()
            ->assertSee('Agende seu horário online')
            ->assertSee('Barbeiro Agenda');

        $this->get(route('booking.availability', $tenant->slug).'?'.http_build_query([
            'barber_id' => $barber->getKey(),
            'service_id' => $service->getKey(),
            'date' => $date->toDateString(),
        ]))
            ->assertOk()
            ->assertJsonPath('data.0', '09:00')
            ->assertJsonPath('data.1', '09:15');
    });

    it('filtra a agenda por intervalo de dias e agrupa os atendimentos por data', function () {
        [$tenant, $admin] = tenantWithUser(MembershipRole::Admin);
        [$barber, $service, $date] = createBookableBarberAndService($tenant);

        actingInTenant($tenant);
        foreach ([
            [$date, 'Cliente Primeiro Dia'],
            [$date->addDay(), 'Cliente Segundo Dia'],
            [$date->addDays(2), 'Cliente Fora do Período'],
        ] as [$appointmentDate, $customerName]) {
            $customer = Customer::factory()->create([
                'tenant_id' => $tenant->getKey(),
                'name' => $customerName,
            ]);
            Appointment::query()->create([
                'customer_id' => $customer->getKey(),
                'barber_id' => $barber->getKey(),
                'service_id' => $service->getKey(),
                'starts_at' => $appointmentDate->setTime(10, 0),
                'ends_at' => $appointmentDate->setTime(10, 30),
                'status' => 'confirmed',
            ]);
        }
        actingWithoutTenant();

        $this->withSession(['current_tenant_id' => (string) $tenant->getKey()])
            ->actingAs($admin)
            ->get(route('app.agenda.index', [
                'start_date' => $date->toDateString(),
                'end_date' => $date->addDay()->toDateString(),
            ]))
            ->assertOk()
            ->assertSee('Cliente Primeiro Dia')
            ->assertSee('Cliente Segundo Dia')
            ->assertDontSee('Cliente Fora do Período')
            ->assertSee('Agendamentos');
    });

    it('valida que a data final da agenda não seja anterior à inicial', function () {
        [$tenant, $admin] = tenantWithUser(MembershipRole::Admin);
        $today = CarbonImmutable::now(config('app.timezone'))->toDateString();

        $this->withSession(['current_tenant_id' => (string) $tenant->getKey()])
            ->actingAs($admin)
            ->get(route('app.agenda.index', [
                'start_date' => CarbonImmutable::parse($today)->addDay()->toDateString(),
                'end_date' => $today,
            ]))
            ->assertSessionHasErrors('end_date');
    });

    it('combina filtros de serviço, status e busca por nome ou telefone do cliente', function () {
        [$tenant, $admin] = tenantWithUser(MembershipRole::Admin);
        [$barber, $service, $date] = createBookableBarberAndService($tenant);

        actingInTenant($tenant);
        $matchingCustomer = Customer::factory()->create([
            'tenant_id' => $tenant->getKey(),
            'name' => 'Mariana Filtro',
            'phone' => '11987654321',
        ]);
        $otherCustomer = Customer::factory()->create([
            'tenant_id' => $tenant->getKey(),
            'name' => 'Cliente Outro',
            'phone' => '11981234567',
        ]);

        foreach ([
            [$matchingCustomer, 'confirmed'],
            [$otherCustomer, 'pending'],
        ] as [$customer, $status]) {
            Appointment::query()->create([
                'customer_id' => $customer->getKey(),
                'barber_id' => $barber->getKey(),
                'service_id' => $service->getKey(),
                'starts_at' => $date->setTime($status === 'confirmed' ? 10 : 11, 0),
                'ends_at' => $date->setTime($status === 'confirmed' ? 10 : 11, 30),
                'status' => $status,
            ]);
        }
        actingWithoutTenant();

        $this->withSession(['current_tenant_id' => (string) $tenant->getKey()])
            ->actingAs($admin)
            ->get(route('app.agenda.index', [
                'start_date' => $date->toDateString(),
                'end_date' => $date->toDateString(),
                'service_id' => $service->getKey(),
                'status' => 'confirmed',
                'search' => '(11) 9876',
            ]))
            ->assertOk()
            ->assertSee('Mariana Filtro')
            ->assertDontSee('Cliente Outro')
            ->assertSee('Mais filtros')
            ->assertSee('<details class="agenda-extra-filters mt-3"  open >', escape: false)
            ->assertSee('name="service_id" value="'.$service->getKey().'"', escape: false)
            ->assertSee('name="status" value="confirmed"', escape: false)
            ->assertSee('value="(11) 9876"', escape: false);
    });

    it('cria automaticamente o cliente pelo telefone e notifica a conta do barbeiro', function () {
        [$tenant, $admin] = tenantWithUser(MembershipRole::Admin);
        [$barberUser] = addMemberTo($tenant, MembershipRole::Barber);
        [$barber, $service, $date] = createBookableBarberAndService($tenant, $barberUser);

        $this->post(route('booking.store', $tenant->slug), [
            'barber_id' => $barber->getKey(),
            'service_id' => $service->getKey(),
            'date' => $date->toDateString(),
            'time' => '09:00',
            'customer_name' => 'Cliente Público',
            'customer_phone' => '(11) 99210-8613',
        ])
            ->assertRedirect(route('booking.show', $tenant->slug))
            ->assertSessionHas('booking_status');

        actingInTenant($tenant);
        actingAsUser($barberUser);
        $customer = Customer::query()->where('phone', '11992108613')->firstOrFail();
        $appointment = Appointment::query()
            ->where('customer_id', $customer->getKey())
            ->firstOrFail();

        expect($customer->name)->toBe('Cliente Público')
            ->and($appointment->barber_id)->toBe($barber->getKey())
            ->and($appointment->service_id)->toBe($service->getKey())
            ->and($appointment->starts_at->format('H:i'))->toBe('09:00')
            ->and(AppointmentNotification::query()->where('user_id', $barberUser->getKey())->count())->toBe(1);

        actingWithoutTenant();
        $notifications = $this->actingAs($barberUser)
            ->withSession(['current_tenant_id' => (string) $tenant->getKey()])
            ->getJson(route('app.appointment-notifications.index'))
            ->assertOk()
            ->assertJsonPath('data.0.message', 'Cliente Público agendou '.$service->name.' com Barbeiro Agenda às '.$date->format('d/m').' 09:00.');

        $notificationId = $notifications->json('data.0.id');
        $this->actingAs($barberUser)
            ->withSession(['current_tenant_id' => (string) $tenant->getKey()])
            ->patchJson(route('app.appointment-notifications.read', $notificationId))
            ->assertNoContent();

        $this->actingAs($barberUser)
            ->withSession(['current_tenant_id' => (string) $tenant->getKey()])
            ->getJson(route('app.appointment-notifications.index'))
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->actingAs($barberUser)
            ->withSession(['current_tenant_id' => (string) $tenant->getKey()])
            ->get(route('app.agenda.index', ['date' => $date->toDateString()]))
            ->assertOk()
            ->assertSee('Cliente Público')
            ->assertSee('09:00–09:30');
    });

    it('não oferece slots já ocupados nem aceita reserva concorrente do mesmo horário', function () {
        [$tenant] = tenantWithUser(MembershipRole::Admin);
        [$barber, $service, $date] = createBookableBarberAndService($tenant);
        actingInTenant($tenant);
        $customer = Customer::factory()->create([
            'tenant_id' => $tenant->getKey(),
            'phone' => '11998887766',
        ]);
        Appointment::query()->create([
            'customer_id' => $customer->getKey(),
            'barber_id' => $barber->getKey(),
            'service_id' => $service->getKey(),
            'starts_at' => $date->setTime(9, 0),
            'ends_at' => $date->setTime(9, 30),
            'status' => 'confirmed',
        ]);
        BlockedPeriod::query()->create([
            'tenant_id' => $tenant->getKey(),
            'barber_id' => $barber->getKey(),
            'starts_at' => $date->setTime(10, 0),
            'ends_at' => $date->setTime(10, 30),
            'reason' => 'Pausa',
        ]);
        expect(Appointment::query()->count())->toBe(1)
            ->and(Appointment::query()->firstOrFail()->starts_at->getTimestamp())
            ->toBe($date->setTime(9, 0)->getTimestamp());
        expect(app(ListAvailableSlots::class)->execute([
            'barber_id' => (int) $barber->getKey(),
            'service_id' => (int) $service->getKey(),
            'date' => $date->toDateString(),
        ])['slots'])->not->toContain('09:00');
        actingWithoutTenant();

        $availabilityUrl = route('booking.availability', $tenant->slug).'?'.http_build_query([
            'barber_id' => $barber->getKey(),
            'service_id' => $service->getKey(),
            'date' => $date->toDateString(),
        ]);
        $availability = $this->getJson($availabilityUrl)->assertOk();
        expect($availability->json('data'))->not->toContain('09:00');
        expect($availability->json('data'))->not->toContain('10:00')
            ->and($availability->json('data'))->toContain('10:30');

        $this->from(route('booking.show', $tenant->slug))
            ->post(route('booking.store', $tenant->slug), [
                'barber_id' => $barber->getKey(),
                'service_id' => $service->getKey(),
                'date' => $date->toDateString(),
                'time' => '09:00',
                'customer_name' => 'Outro Cliente',
                'customer_phone' => '11990000000',
            ])
            ->assertSessionHasErrors('time');
    });

    it('não permite consultar barbeiro de outro tenant pelo slug público', function () {
        [$tenantA] = tenantWithUser(MembershipRole::Admin);
        [$tenantB] = tenantWithUser(MembershipRole::Admin);
        [$barberB, $serviceB, $dateB] = createBookableBarberAndService($tenantB);

        $this->getJson(route('booking.availability', $tenantA->slug).'?'.http_build_query([
            'barber_id' => $barberB->getKey(),
            'service_id' => $serviceB->getKey(),
            'date' => $dateB->toDateString(),
        ]))->assertNotFound();
    });

    it('exibe e aceita somente serviços vinculados ao barbeiro escolhido', function () {
        [$tenant] = tenantWithUser(MembershipRole::Admin);
        [$barber, $service, $date] = createBookableBarberAndService($tenant);
        actingInTenant($tenant);
        $unlinkedService = Service::factory()->create([
            'tenant_id' => $tenant->getKey(),
            'name' => 'Barba não realizada',
        ]);
        actingWithoutTenant();

        $this->get(route('booking.show', $tenant->slug))
            ->assertOk()
            ->assertSee('data-service-ids="'.$service->getKey().'"', escape: false)
            ->assertSee($service->name)
            ->assertDontSee($unlinkedService->name);

        $this->getJson(route('booking.availability', $tenant->slug).'?'.http_build_query([
            'barber_id' => $barber->getKey(),
            'service_id' => $unlinkedService->getKey(),
            'date' => $date->toDateString(),
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('service_id');

        $this->from(route('booking.show', $tenant->slug))
            ->post(route('booking.store', $tenant->slug), [
                'barber_id' => $barber->getKey(),
                'service_id' => $unlinkedService->getKey(),
                'date' => $date->toDateString(),
                'time' => '09:00',
                'customer_name' => 'Cliente Serviço Inválido',
                'customer_phone' => '11990000001',
            ])
            ->assertSessionHasErrors('service_id');
    });
});
