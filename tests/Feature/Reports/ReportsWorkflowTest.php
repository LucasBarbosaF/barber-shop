<?php

use App\Domain\Agenda\Enums\AppointmentStatus;
use App\Domain\Attendance\Enums\AttendanceStatus;
use App\Domain\Commissions\Enums\CommissionCalculationType;
use App\Domain\Tenant\Enums\MembershipRole;
use App\Models\Appointment;
use App\Models\Attendance;
use App\Models\AttendanceItem;
use App\Models\Barber;
use App\Models\Commission;
use App\Models\CommissionPeriod;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Service;

describe('relatórios', function () {
    it('exibe relatórios reais e exporta os quatro tipos em CSV e PDF', function (): void {
        [$tenant, $admin] = tenantWithUser(MembershipRole::Admin);
        actingInTenant($tenant);

        $barber = Barber::factory()->create([
            'tenant_id' => $tenant->getKey(),
            'name' => 'Barbeiro Relatório',
        ]);
        $customer = Customer::factory()->create([
            'tenant_id' => $tenant->getKey(),
            'name' => 'Cliente Relatório',
        ]);
        $service = Service::factory()->create([
            'tenant_id' => $tenant->getKey(),
            'name' => 'Serviço Relatório',
            'price' => '80.00',
        ]);
        $appointment = Appointment::query()->create([
            'customer_id' => $customer->getKey(),
            'barber_id' => $barber->getKey(),
            'service_id' => $service->getKey(),
            'starts_at' => '2026-10-06 09:00:00',
            'ends_at' => '2026-10-06 10:00:00',
            'status' => AppointmentStatus::Completed,
        ]);
        $attendance = Attendance::query()->create([
            'appointment_id' => $appointment->getKey(),
            'status' => AttendanceStatus::Closed,
            'opened_at' => '2026-10-06 09:00:00',
            'closed_at' => '2026-10-06 10:00:00',
        ]);
        AttendanceItem::query()->create([
            'attendance_id' => $attendance->getKey(),
            'service_id' => $service->getKey(),
            'quantity' => 1,
            'unit_price_snapshot' => '80.00',
            'commission_percentage_snapshot' => '25.00',
            'commission_amount_snapshot' => '20.00',
        ]);
        Payment::query()->create([
            'attendance_id' => $attendance->getKey(),
            'amount' => '80.00',
            'method' => 'pix',
            'idempotency_key' => 'reports-payment-001',
            'created_at' => '2026-10-06 10:10:00',
            'updated_at' => '2026-10-06 10:10:00',
        ]);
        $period = CommissionPeriod::query()->create([
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-10',
            'closed_at' => '2026-10-10 12:00:00',
        ]);
        Commission::query()->create([
            'period_id' => $period->getKey(),
            'attendance_item_id' => AttendanceItem::query()->firstOrFail()->getKey(),
            'barber_id' => $barber->getKey(),
            'service_id' => $service->getKey(),
            'barber_name_snapshot' => $barber->name,
            'service_name_snapshot' => $service->name,
            'calculation_type_snapshot' => CommissionCalculationType::Percentage,
            'percentage_snapshot' => '25.00',
            'amount' => '20.00',
        ]);

        $client = $this->withSession(['current_tenant_id' => (string) $tenant->getKey()])
            ->actingAs($admin);
        $csvRows = [
            'faturamento' => 'Pix;1;80,00',
            'clientes' => 'Cliente Relatório',
            'barbeiros' => 'Barbeiro Relatório',
            'comissoes' => 'Barbeiro Relatório',
        ];
        foreach ($csvRows as $report => $csvRow) {
            $client->get(route('app.reports.show', [
                'report' => $report,
                'from' => '2026-10-01',
                'to' => '2026-10-31',
            ]))->assertOk()
                ->assertSee('Dados de')
                ->assertSee('2026-10-01');

            $csvResponse = $client->get(route('app.reports.export', [
                'report' => $report,
                'format' => 'csv',
                'from' => '2026-10-01',
                'to' => '2026-10-31',
            ]))->assertOk()
                ->assertHeader('content-type', 'text/csv; charset=UTF-8')
                ->assertDownload($report.'-2026-10-01-2026-10-31.csv');
            expect($csvResponse->streamedContent())->toContain($csvRow);

            $client->get(route('app.reports.export', [
                'report' => $report,
                'format' => 'pdf',
                'from' => '2026-10-01',
                'to' => '2026-10-31',
            ]))->assertOk()
                ->assertHeader('content-type', 'application/pdf')
                ->assertDownload($report.'-2026-10-01-2026-10-31.pdf')
                ->assertSee('%PDF-1.4', false);
        }

        $client->get(route('app.reports.show', [
            'report' => 'faturamento',
            'from' => '2026-10-01',
            'to' => '2026-10-31',
        ]))->assertOk()
            ->assertSee('R$ 80,00')
            ->assertSee('Pix');
        $client->get(route('app.reports.show', [
            'report' => 'clientes',
            'from' => '2026-10-01',
            'to' => '2026-10-31',
        ]))->assertOk()->assertSee('Cliente Relatório');
        $client->get(route('app.reports.show', [
            'report' => 'barbeiros',
            'from' => '2026-10-01',
            'to' => '2026-10-31',
        ]))->assertOk()->assertSee('Barbeiro Relatório');
        $client->get(route('app.reports.show', [
            'report' => 'comissoes',
            'from' => '2026-10-01',
            'to' => '2026-10-31',
        ]))->assertOk()->assertSee('Pendente');
    });

    it('protege os relatórios por permissão e restringe o período inválido', function (): void {
        [$tenant, $receptionist] = tenantWithUser(MembershipRole::Receptionist);
        $client = $this->withSession(['current_tenant_id' => (string) $tenant->getKey()])
            ->actingAs($receptionist);

        $client->get(route('app.reports.show', 'faturamento'))
            ->assertForbidden();

        [$authorizedTenant, $authorizedAdmin] = tenantWithUser(MembershipRole::Admin);
        $authorizedClient = $this->withSession(['current_tenant_id' => (string) $authorizedTenant->getKey()])
            ->actingAs($authorizedAdmin);
        $authorizedClient->get(route('app.reports.show', [
            'report' => 'faturamento',
            'from' => '2026-10-31',
            'to' => '2026-10-01',
        ]))->assertSessionHasErrors('to');
    });
});
