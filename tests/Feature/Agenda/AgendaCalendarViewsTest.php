<?php

use App\Domain\Tenant\Enums\MembershipRole;
use App\Models\Appointment;
use App\Models\Barber;
use App\Models\Customer;
use App\Models\Service;
use Carbon\CarbonImmutable;

describe('visões de calendário da agenda', function () {
    it('monta a visão de semana com grade de horários e payload de eventos', function () {
        [$tenant, $admin] = tenantWithUser(MembershipRole::Admin);
        $date = CarbonImmutable::now(config('app.timezone'))->startOfWeek()->addDays(2);

        $this->withSession(['current_tenant_id' => (string) $tenant->getKey()])
            ->actingAs($admin)
            ->get(route('app.agenda.index', ['view' => 'week', 'date' => $date->toDateString()]))
            ->assertOk()
            ->assertSee('data-agenda-calendar')
            ->assertSee('data-agenda-timegrid')
            ->assertSee('data-agenda-events')
            ->assertSee('data-now=')
            ->assertSee('Agendamentos');
    });

    it('monta a visão de mês com as células do calendário e o evento no payload', function () {
        [$tenant, $admin] = tenantWithUser(MembershipRole::Admin);
        $date = CarbonImmutable::now(config('app.timezone'))->startOfMonth()->addDay();

        actingInTenant($tenant);
        $barber = Barber::factory()->create(['tenant_id' => $tenant->getKey()]);
        $service = Service::factory()->create(['tenant_id' => $tenant->getKey()]);
        $customer = Customer::factory()->create([
            'tenant_id' => $tenant->getKey(),
            'name' => 'Cliente Visão Mês',
        ]);
        Appointment::query()->create([
            'customer_id' => $customer->getKey(),
            'barber_id' => $barber->getKey(),
            'service_id' => $service->getKey(),
            'starts_at' => $date->setTime(10, 0),
            'ends_at' => $date->setTime(10, 30),
            'status' => 'confirmed',
        ]);
        actingWithoutTenant();

        $this->withSession(['current_tenant_id' => (string) $tenant->getKey()])
            ->actingAs($admin)
            ->get(route('app.agenda.index', ['view' => 'month', 'date' => $date->toDateString()]))
            ->assertOk()
            ->assertSee('data-agenda-month-body')
            ->assertSee('Cliente Visão Mês')
            ->assertSee('10:00–10:30')
            ->assertSee('Agendamentos');
    });
});
