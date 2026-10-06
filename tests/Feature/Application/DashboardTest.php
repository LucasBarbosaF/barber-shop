<?php

use App\Domain\Agenda\Enums\AppointmentStatus;
use App\Domain\Attendance\Enums\AttendanceStatus;
use App\Domain\Payments\Enums\PaymentEventType;
use App\Domain\Tenant\Enums\MembershipRole;
use App\Models\Appointment;
use App\Models\Attendance;
use App\Models\Barber;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\PaymentRefund;
use App\Models\Service;
use Illuminate\Support\Carbon;

describe('dashboard da barbearia', function () {
    it('apresenta indicadores calculados a partir dos dados reais do tenant', function () {
        [$tenant, $admin] = tenantWithUser(MembershipRole::Admin);
        actingInTenant($tenant);

        $barber = Barber::factory()->create(['tenant_id' => $tenant->getKey()]);
        $customer = Customer::factory()->create([
            'tenant_id' => $tenant->getKey(),
            'created_at' => Carbon::now(config('app.timezone')),
        ]);
        $service = Service::factory()->create(['tenant_id' => $tenant->getKey()]);
        $appointment = Appointment::query()->create([
            'customer_id' => $customer->getKey(),
            'barber_id' => $barber->getKey(),
            'service_id' => $service->getKey(),
            'starts_at' => Carbon::now(config('app.timezone'))->addHour(),
            'ends_at' => Carbon::now(config('app.timezone'))->addHours(2),
            'status' => AppointmentStatus::Confirmed,
        ]);
        $attendance = Attendance::query()->create([
            'appointment_id' => $appointment->getKey(),
            'status' => AttendanceStatus::Open,
            'opened_at' => Carbon::now(config('app.timezone')),
        ]);
        $payment = Payment::query()->create([
            'attendance_id' => $attendance->getKey(),
            'amount' => '75.00',
            'method' => 'cash',
            'idempotency_key' => 'dashboard-payment',
        ]);
        PaymentEvent::query()->create([
            'payment_id' => $payment->getKey(),
            'type' => PaymentEventType::PaymentReceived,
            'amount' => '75.00',
            'idempotency_key' => 'dashboard-payment-event',
        ]);
        $refund = PaymentRefund::query()->create([
            'payment_id' => $payment->getKey(),
            'amount' => '15.00',
            'reason' => 'Ajuste para teste do dashboard',
            'idempotency_key' => 'dashboard-refund',
            'refunded_at' => Carbon::now(config('app.timezone')),
        ]);
        PaymentEvent::query()->create([
            'payment_id' => $payment->getKey(),
            'refund_id' => $refund->getKey(),
            'type' => PaymentEventType::PaymentRefunded,
            'amount' => '15.00',
            'idempotency_key' => 'dashboard-refund-event',
        ]);
        actingWithoutTenant();

        $this->actingAs($admin)
            ->get(route('app.dashboard'))
            ->assertOk()
            ->assertSee('Agendamentos hoje')
            ->assertSee('Atendimentos em aberto')
            ->assertSee('Novos clientes hoje')
            ->assertSee('Recebido hoje')
            ->assertSee('R$ 60,00')
            ->assertSee($customer->name)
            ->assertSee('Próximos 7 dias');
    });

    it('não mostra indicadores financeiros a perfis sem permissão e mantém a agenda do barbeiro restrita', function () {
        [$tenant, $barberUser] = tenantWithUser(MembershipRole::Barber);
        actingInTenant($tenant);
        $barber = Barber::factory()->create([
            'tenant_id' => $tenant->getKey(),
            'user_id' => $barberUser->getKey(),
        ]);
        $otherBarber = Barber::factory()->create(['tenant_id' => $tenant->getKey()]);
        $service = Service::factory()->create(['tenant_id' => $tenant->getKey()]);

        foreach ([
            [$barber, 'Agendamento do barbeiro'],
            [$otherBarber, 'Agendamento de outro barbeiro'],
        ] as [$appointmentBarber, $customerName]) {
            $customer = Customer::factory()->create([
                'tenant_id' => $tenant->getKey(),
                'name' => $customerName,
            ]);
            Appointment::query()->create([
                'customer_id' => $customer->getKey(),
                'barber_id' => $appointmentBarber->getKey(),
                'service_id' => $service->getKey(),
                'starts_at' => Carbon::now(config('app.timezone'))->addHours(2),
                'ends_at' => Carbon::now(config('app.timezone'))->addHours(3),
                'status' => AppointmentStatus::Confirmed,
            ]);
        }
        actingWithoutTenant();

        $this->actingAs($barberUser)
            ->get(route('app.dashboard'))
            ->assertOk()
            ->assertSee('Agendamento do barbeiro')
            ->assertDontSee('Agendamento de outro barbeiro')
            ->assertDontSee('Recebido hoje');
    });
});
