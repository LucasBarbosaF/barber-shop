<?php

use App\Domain\Agenda\Enums\AppointmentStatus;
use App\Domain\Attendance\Enums\AttendanceStatus;
use App\Domain\Cash\Enums\CashRegisterStatus;
use App\Domain\Cash\Enums\CashTransactionType;
use App\Domain\Payments\Enums\PaymentEventType;
use App\Domain\Tenant\Enums\MembershipRole;
use App\Models\Appointment;
use App\Models\Attendance;
use App\Models\AttendanceItem;
use App\Models\Barber;
use App\Models\CashRegister;
use App\Models\CashTransaction;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\PaymentRefund;
use App\Models\Service;
use Illuminate\Database\QueryException;

function closedAttendanceForPayments($tenant, string $amount = '60.00'): Attendance
{
    actingInTenant($tenant);
    $barber = Barber::factory()->create(['tenant_id' => $tenant->getKey()]);
    $customer = Customer::factory()->create(['tenant_id' => $tenant->getKey()]);
    $service = Service::factory()->create([
        'tenant_id' => $tenant->getKey(),
        'price' => $amount,
    ]);
    $appointment = Appointment::query()->create([
        'customer_id' => $customer->getKey(),
        'barber_id' => $barber->getKey(),
        'service_id' => $service->getKey(),
        'starts_at' => now()->subHours(2),
        'ends_at' => now()->subHour(),
        'status' => AppointmentStatus::Completed,
    ]);
    $attendance = Attendance::query()->create([
        'appointment_id' => $appointment->getKey(),
        'status' => AttendanceStatus::Closed,
        'opened_at' => now()->subHours(2),
        'closed_at' => now()->subHour(),
    ]);
    AttendanceItem::query()->create([
        'attendance_id' => $attendance->getKey(),
        'service_id' => $service->getKey(),
        'quantity' => 1,
        'unit_price_snapshot' => $amount,
        'commission_percentage_snapshot' => '30.00',
        'commission_amount_snapshot' => '18.00',
    ]);
    actingWithoutTenant();

    return $attendance;
}

describe('pagamentos e caixa', function () {
    it('registra pagamentos divididos e estornos idempotentes preservando auditoria e saldo', function () {
        [$tenant, $admin] = tenantWithUser(MembershipRole::Admin);
        $attendance = closedAttendanceForPayments($tenant);
        $client = $this->withSession(['current_tenant_id' => (string) $tenant->getKey()])
            ->actingAs($admin);

        $cashRegisterId = $client->postJson(route('app.cash.open'), ['opening_amount' => '10.00'])
            ->assertCreated()
            ->assertJsonPath('data.status', CashRegisterStatus::Open->value)
            ->json('data.id');

        $firstPaymentResponse = $client->postJson(
            route('app.payments.store', $attendance),
            ['amount' => '40.00', 'method' => 'cash'],
            ['Idempotency-Key' => 'payment-key-001'],
        )->assertCreated()->assertJsonPath('data.amount', '40.00');
        $firstPaymentId = $firstPaymentResponse->json('data.id');

        $client->postJson(
            route('app.payments.store', $attendance),
            ['amount' => '40.00', 'method' => 'cash'],
            ['Idempotency-Key' => 'payment-key-001'],
        )->assertCreated()->assertJsonPath('data.id', $firstPaymentId);

        $client->postJson(
            route('app.payments.store', $attendance),
            ['amount' => '39.00', 'method' => 'cash'],
            ['Idempotency-Key' => 'payment-key-001'],
        )->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');

        $client->postJson(
            route('app.payments.store', $attendance),
            ['amount' => '20.00', 'method' => 'pix'],
            ['Idempotency-Key' => 'payment-key-002'],
        )->assertCreated();

        $client->get(route('app.payments.index'))
            ->assertOk()
            ->assertSee('Vendas')
            ->assertSee('Cliente');
        $client->get(route('app.cash.index'))
            ->assertOk()
            ->assertSee('Caixa')
            ->assertSee('Pagamento do atendimento');

        $client->postJson(
            route('app.payments.store', $attendance),
            ['amount' => '0.01', 'method' => 'card'],
            ['Idempotency-Key' => 'payment-key-003'],
        )->assertUnprocessable()->assertJsonValidationErrors('amount');

        $refundResponse = $client->postJson(
            route('app.payments.refunds.store', $firstPaymentId),
            ['amount' => '10.00', 'reason' => 'Cobrança parcial indevida'],
            ['Idempotency-Key' => 'refund-key-001'],
        )->assertCreated();
        $refundId = $refundResponse->json('data.id');

        $client->postJson(
            route('app.payments.refunds.store', $firstPaymentId),
            ['amount' => '10.00', 'reason' => 'Cobrança parcial indevida'],
            ['Idempotency-Key' => 'refund-key-001'],
        )->assertCreated()->assertJsonPath('data.id', $refundId);

        $client->postJson(
            route('app.payments.refunds.store', $firstPaymentId),
            ['amount' => '31.00', 'reason' => 'Valor excedido'],
            ['Idempotency-Key' => 'refund-key-002'],
        )->assertUnprocessable()->assertJsonValidationErrors('amount');

        $client->postJson(
            route('app.payments.refunds.store', $firstPaymentId),
            ['amount' => '30.00', 'reason' => 'Estorno do restante'],
            ['Idempotency-Key' => 'refund-key-003'],
        )->assertCreated();

        $client->postJson(route('app.cash.transactions.store', $cashRegisterId), [
            'type' => 'cash_out',
            'amount' => '3.00',
            'description' => 'Retirada para troco',
        ])->assertCreated();

        $client->patchJson(route('app.cash.close', $cashRegisterId), ['closing_amount' => '6.50'])
            ->assertOk()
            ->assertJsonPath('data.expected_amount', '7.00')
            ->assertJsonPath('data.difference_amount', '-0.50');

        actingInTenant($tenant);
        expect(Payment::query()->where('attendance_id', $attendance->getKey())->count())->toBe(2)
            ->and(PaymentRefund::query()->count())->toBe(2)
            ->and(PaymentEvent::query()->where('type', PaymentEventType::PaymentReceived)->count())->toBe(2)
            ->and(PaymentEvent::query()->where('type', PaymentEventType::PaymentRefunded)->count())->toBe(2)
            ->and(CashTransaction::query()->where('type', CashTransactionType::Payment)->count())->toBe(1)
            ->and(CashTransaction::query()->where('type', CashTransactionType::Refund)->count())->toBe(2)
            ->and(CashRegister::query()->findOrFail($cashRegisterId)->status)->toBe(CashRegisterStatus::Closed);
    });

    it('exige caixa aberto para recebimentos e impede caixa negativo ou abertura duplicada', function () {
        [$tenant, $admin] = tenantWithUser(MembershipRole::Admin);
        $attendance = closedAttendanceForPayments($tenant);
        $client = $this->withSession(['current_tenant_id' => (string) $tenant->getKey()])
            ->actingAs($admin);

        $client->postJson(
            route('app.payments.store', $attendance),
            ['amount' => '10.00', 'method' => 'cash'],
            ['Idempotency-Key' => 'cash-payment-no-register'],
        )->assertUnprocessable()->assertJsonValidationErrors('cash_register');

        $registerId = $client->postJson(route('app.cash.open'), ['opening_amount' => '5.00'])
            ->assertCreated()
            ->json('data.id');
        $client->postJson(route('app.cash.open'), ['opening_amount' => '0.00'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('cash_register');

        $client->postJson(route('app.cash.transactions.store', $registerId), [
            'type' => 'cash_out',
            'amount' => '5.01',
            'description' => 'Retirada acima do saldo',
        ])->assertUnprocessable()->assertJsonValidationErrors('amount');
    });

    it('permite abrir caixa pela tela html com saldo inicial', function () {
        [$tenant, $admin] = tenantWithUser(MembershipRole::Admin);
        $client = $this->withSession(['current_tenant_id' => (string) $tenant->getKey()])
            ->actingAs($admin);

        $client->get(route('app.cash.index'))
            ->assertOk()
            ->assertSee('Abrir caixa')
            ->assertSee('opening_amount')
            ->assertSee('Use o formulário acima para abrir o primeiro caixa.');

        $client->post(route('app.cash.open'), ['opening_amount' => '125.50'])
            ->assertRedirect(route('app.cash.index'))
            ->assertSessionHas('status', 'Caixa aberto com sucesso.');

        $client->get(route('app.cash.index'))
            ->assertOk()
            ->assertSee('Caixa aberto em')
            ->assertSee('R$ 125,50')
            ->assertSee('Registrar movimentação')
            ->assertSee('Fechar caixa')
            ->assertDontSee('Use o formulário acima para abrir o primeiro caixa.');

        $cashRegister = CashRegister::query()->where('status', CashRegisterStatus::Open)->firstOrFail();
        $client->post(route('app.cash.transactions.store', $cashRegister), [
            'type' => 'cash_in',
            'amount' => '20.00',
            'description' => 'Suprimento de troco',
        ])->assertRedirect(route('app.cash.index'))
            ->assertSessionHas('status', 'Movimentação registrada com sucesso.');

        $client->post(route('app.cash.transactions.store', $cashRegister), [
            'type' => 'cash_out',
            'amount' => '5.00',
            'description' => 'Retirada para depósito',
        ])->assertRedirect(route('app.cash.index'));

        $client->get(route('app.cash.index'))
            ->assertOk()
            ->assertSee('R$ 140,50')
            ->assertSee('Suprimento de troco')
            ->assertSee('Retirada para depósito');

        $client->patch(route('app.cash.close', $cashRegister), ['closing_amount' => '140.00'])
            ->assertRedirect(route('app.cash.index'))
            ->assertSessionHas('status', 'Caixa fechado com sucesso.');

        $client->get(route('app.cash.index'))
            ->assertOk()
            ->assertSee('Fechado')
            ->assertSee('R$ 140,50')
            ->assertSee('R$ 140,00')
            ->assertSee('R$ -0,50')
            ->assertDontSee('Fechar caixa');
    });

    it('isola caixa e pagamentos por tenant e protege acessos pelas permissões', function () {
        [$tenantA, $adminA] = tenantWithUser(MembershipRole::Admin);
        [$tenantB] = tenantWithUser(MembershipRole::Admin);
        $attendanceB = closedAttendanceForPayments($tenantB);
        actingInTenant($tenantB);
        $registerB = CashRegister::query()->create([
            'status' => CashRegisterStatus::Open,
            'opening_amount' => '0.00',
            'opened_at' => now(),
        ]);
        actingInTenant($tenantA);

        expect(CashRegister::query()->whereKey($registerB->getKey())->exists())->toBeFalse();

        expect(failedWrite(fn () => Payment::query()->create([
            'tenant_id' => $tenantA->getKey(),
            'attendance_id' => $attendanceB->getKey(),
            'amount' => '10.00',
            'method' => 'pix',
            'idempotency_key' => 'cross-tenant',
        ])))->toBeInstanceOf(QueryException::class);

        [$receptionist] = addMemberTo($tenantA, MembershipRole::Receptionist);
        actingWithoutTenant();
        $client = $this->withSession(['current_tenant_id' => (string) $tenantA->getKey()])
            ->actingAs($adminA);
        $client->getJson(route('app.cash.index'))->assertOk();

        $this->withSession(['current_tenant_id' => (string) $tenantA->getKey()])
            ->actingAs($receptionist)
            ->getJson(route('app.cash.index'))
            ->assertForbidden();

        $this->withSession(['current_tenant_id' => (string) $tenantA->getKey()])
            ->actingAs($receptionist)
            ->get(route('app.payments.index'))
            ->assertForbidden();

        $this->withSession(['current_tenant_id' => (string) $tenantA->getKey()])
            ->actingAs($receptionist)
            ->postJson(route('app.cash.open'), ['opening_amount' => '0.00'])
            ->assertForbidden();
    });
});
