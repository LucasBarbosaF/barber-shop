<?php

use App\Domain\Agenda\Enums\AppointmentStatus;
use App\Domain\Attendance\Enums\AttendanceStatus;
use App\Domain\Tenant\Enums\MembershipRole;
use App\Models\Appointment;
use App\Models\Attendance;
use App\Models\Barber;
use App\Models\Customer;
use App\Models\Service;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

describe('configurações da barbearia', function () {
    it('atualiza dados de identidade, exibe logo e permite removê-lo', function (): void {
        [$tenant, $admin] = tenantWithUser(MembershipRole::Admin);
        Storage::fake('public');
        $client = $this->withSession(['current_tenant_id' => (string) $tenant->getKey()])
            ->actingAs($admin);

        $logoContent = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADUlEQVR4nGP4z8AAAAMBAQDJ/pLvAAAAAElFTkSuQmCC',
            true,
        );
        expect($logoContent)->not->toBeFalse();
        $logo = UploadedFile::fake()->createWithContent('marca.png', $logoContent);
        $client->put(route('app.settings.barbershop.update'), [
            'name' => 'Barbearia Identidade',
            'document' => '12.345.678/0001-90',
            'email' => 'contato@identidade.test',
            'phone' => '(11) 3333-4444',
            'logo' => $logo,
        ])->assertRedirect(route('app.settings.barbershop'))
            ->assertSessionHas('status', 'Dados da barbearia atualizados.');

        $tenant->refresh();
        expect($tenant->name)->toBe('Barbearia Identidade')
            ->and($tenant->email)->toBe('contato@identidade.test')
            ->and($tenant->logo_path)->not->toBeNull();
        Storage::disk('public')->assertExists($tenant->logo_path);

        $client->get(route('app.settings.barbershop'))
            ->assertOk()
            ->assertSee('Barbearia Identidade')
            ->assertSee('storage/'.$tenant->logo_path);
        $client->get(route('app.dashboard'))
            ->assertOk()
            ->assertSee('Barbearia Identidade')
            ->assertSee('storage/'.$tenant->logo_path);
        $this->get(route('booking.show', $tenant->slug))
            ->assertOk()
            ->assertSee('Barbearia Identidade')
            ->assertSee('alt="Logo de Barbearia Identidade"', false)
            ->assertSee('storage/'.$tenant->logo_path);

        $previousLogo = $tenant->logo_path;
        $client->put(route('app.settings.barbershop.update'), [
            'name' => 'Barbearia Identidade',
            'remove_logo' => '1',
        ])->assertRedirect(route('app.settings.barbershop'));
        expect($tenant->fresh()->logo_path)->toBeNull();
        Storage::disk('public')->assertMissing($previousLogo);

        $client->get(route('app.dashboard'))
            ->assertOk()
            ->assertSee('fa-scissors', false)
            ->assertSee('Barbearia Identidade');
    });

    it('apresenta permissões e expediente existente por barbeiro', function (): void {
        [$tenant, $admin] = tenantWithUser(MembershipRole::Admin);
        actingInTenant($tenant);
        Barber::factory()->create(['tenant_id' => $tenant->getKey(), 'name' => 'Barbeiro Expediente']);
        $client = $this->withSession(['current_tenant_id' => (string) $tenant->getKey()])
            ->actingAs($admin);

        $client->get(route('app.settings.permissions'))
            ->assertOk()
            ->assertSee('Administrador')
            ->assertSee('Ver relatórios')
            ->assertSee('não podem ser alteradas');
        $client->get(route('app.settings.hours'))
            ->assertOk()
            ->assertSee('Barbeiro Expediente')
            ->assertSee('Editar horários');
    });

    it('salva formas de pagamento e impede o uso de uma forma desativada', function (): void {
        [$tenant, $admin] = tenantWithUser(MembershipRole::Admin);
        actingInTenant($tenant);
        $barber = Barber::factory()->create(['tenant_id' => $tenant->getKey()]);
        $customer = Customer::factory()->create(['tenant_id' => $tenant->getKey()]);
        $service = Service::factory()->create(['tenant_id' => $tenant->getKey()]);
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
        $client = $this->withSession(['current_tenant_id' => (string) $tenant->getKey()])
            ->actingAs($admin);

        $client->put(route('app.settings.payment-methods.update'), [
            'payment_methods' => ['pix', 'card'],
        ])->assertRedirect(route('app.settings.payment-methods'));
        expect($tenant->fresh()->enabledPaymentMethodValues())->toBe(['pix', 'card']);

        $client->from(route('app.attendances.show', $attendance))
            ->post(route('app.payments.store', $attendance), [
                'amount' => '10.00',
                'method' => 'cash',
                'idempotency_key' => 'disabled-cash-method',
            ])->assertRedirect(route('app.attendances.show', $attendance))
            ->assertSessionHasErrors('method');

        $client->put(route('app.settings.payment-methods.update'), [])
            ->assertSessionHasErrors('payment_methods');
        $client->get(route('app.settings.payment-methods'))
            ->assertOk()
            ->assertSee('Mantenha ao menos uma forma ativa');
    });

    it('esconde e protege configurações de quem não possui a permissão', function (): void {
        [$tenant, $receptionist] = tenantWithUser(MembershipRole::Receptionist);
        $client = $this->withSession(['current_tenant_id' => (string) $tenant->getKey()])
            ->actingAs($receptionist);

        $client->get(route('app.dashboard'))
            ->assertOk()
            ->assertDontSee('Minha Barbearia');
        $client->get(route('app.settings.barbershop'))->assertForbidden();
    });
});
