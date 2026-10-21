<?php

use App\Domain\Tenant\Enums\MembershipRole;
use App\Domain\Tenant\Models\Tenant;
use App\Models\Appointment;
use App\Models\AppointmentNotification;
use App\Models\Barber;
use App\Models\BusinessHour;
use App\Models\Customer;
use App\Models\Service;
use App\Models\WhatsAppConversation;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

/**
 * Fixtures e helpers do fluxo de agendamento por WhatsApp.
 *
 * Cada arquivo de teste é autossuficiente: os helpers não vêm de outro arquivo
 * de teste, porque `pest WhatsAppBookingFlowTest.php` carrega só este.
 */
function whatsappBookableBarber(Tenant $tenant, string $barberName = 'Barbeiro Zap'): array
{
    [$barberUser] = addMemberTo($tenant, MembershipRole::Barber);
    actingInTenant($tenant);

    $barber = Barber::factory()->create([
        'tenant_id' => $tenant->getKey(),
        'name' => $barberName,
        'user_id' => $barberUser->getKey(),
    ]);
    $service = Service::factory()->create([
        'tenant_id' => $tenant->getKey(),
        'name' => 'Corte',
        'duration_minutes' => 30,
        'price' => '30.00',
    ]);
    $barber->services()->attach($service->getKey(), ['tenant_id' => $tenant->getKey()]);

    // Expediente de amanhã: o fluxo escolhe "2. Amanhã", e o slot de hoje já
    // passou quando o teste roda à noite.
    $tomorrow = CarbonImmutable::now(config('app.timezone'))->addDay();
    BusinessHour::query()->create([
        'tenant_id' => $tenant->getKey(),
        'barber_id' => $barber->getKey(),
        'weekday' => $tomorrow->dayOfWeek,
        'opens_at' => '09:00',
        'closes_at' => '12:00',
    ]);

    actingWithoutTenant();

    return [$barber, $service, $tomorrow, $barberUser];
}

function flowConnectNumber(Tenant $tenant, string $phoneNumberId = '777'): Tenant
{
    $tenant->forceFill(['whatsapp_settings' => [
        'phone_number_id' => $phoneNumberId,
        'access_token' => 'token-da-barbearia',
    ]])->save();

    return $tenant;
}

function flowWebhookPayload(string $phoneNumberId, string $id, string $from, string $text): array
{
    return [
        'object' => 'whatsapp_business_account',
        'entry' => [[
            'id' => 'WABA-1',
            'changes' => [[
                'field' => 'messages',
                'value' => [
                    'metadata' => ['phone_number_id' => $phoneNumberId],
                    'messages' => [[
                        'id' => $id,
                        'from' => $from,
                        'timestamp' => (string) time(),
                        'type' => 'text',
                        'text' => ['body' => $text],
                    ]],
                ],
            ]],
        ]],
    ];
}

function flowSendText(string $text, string $id, string $from = '5511999999999', string $phoneNumberId = '777'): TestResponse
{
    $payload = flowWebhookPayload($phoneNumberId, $id, $from, $text);
    $content = json_encode($payload, JSON_THROW_ON_ERROR);

    return test()->call('POST', '/api/webhooks/whatsapp', [], [], [], [
        'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $content, (string) config('services.whatsapp.app_secret')),
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
    ], $content);
}

function flowReplyBody(ClientRequest $request): string
{
    return (string) data_get($request->data(), 'text.body');
}

describe('agendamento por whatsapp', function (): void {
    beforeEach(function (): void {
        config([
            'services.whatsapp.app_secret' => 'webhook-secret',
            'services.whatsapp.verify_token' => 'verify-token',
            'services.whatsapp.access_token' => 'global-token',
        ]);
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.enviada']]])]);
    });

    it('percorre o menu numerado e grava o agendamento confirmado', function (): void {
        [$tenant] = tenantWithUser(MembershipRole::Admin);
        flowConnectNumber($tenant);
        [$barber, $service, $tomorrow, $barberUser] = whatsappBookableBarber($tenant);

        flowSendText('oi', 'wamid.1')->assertOk();
        Http::assertSent(fn (ClientRequest $request): bool => str_contains(flowReplyBody($request), 'Escolha o barbeiro')
            && str_contains(flowReplyBody($request), 'Barbeiro Zap')
            && $request['to'] === '5511999999999');

        flowSendText('1', 'wamid.2');
        Http::assertSent(fn (ClientRequest $request): bool => str_contains(flowReplyBody($request), 'Qual serviço')
            && str_contains(flowReplyBody($request), 'R$ 30,00'));

        flowSendText('1', 'wamid.3');
        Http::assertSent(fn (ClientRequest $request): bool => str_contains(flowReplyBody($request), 'Qual dia você prefere?'));

        flowSendText('2', 'wamid.4');
        Http::assertSent(fn (ClientRequest $request): bool => str_contains(flowReplyBody($request), 'Horários livres em')
            && str_contains(flowReplyBody($request), $tomorrow->format('d/m')));

        flowSendText('1', 'wamid.5');
        Http::assertSent(fn (ClientRequest $request): bool => flowReplyBody($request) === 'Qual é o seu nome?');

        flowSendText('Maria', 'wamid.6');
        Http::assertSent(fn (ClientRequest $request): bool => str_contains(flowReplyBody($request), 'Confirma o agendamento?')
            && str_contains(flowReplyBody($request), 'Cliente: Maria')
            && str_contains(flowReplyBody($request), 'Barbeiro Zap'));

        flowSendText('1', 'wamid.7');
        Http::assertSent(fn (ClientRequest $request): bool => str_contains(flowReplyBody($request), 'Agendado!'));

        actingInTenant($tenant);
        // A policy de SELECT de appointment_notifications é a única que cobra
        // também o usuário: notificação é lida por quem vai ser notificado.
        actingAsUser($barberUser);

        $customer = Customer::query()->where('phone', '11999999999')->firstOrFail();
        $appointment = Appointment::query()->where('customer_id', $customer->getKey())->firstOrFail();

        expect($customer->name)->toBe('Maria')
            ->and($appointment->barber_id)->toBe($barber->getKey())
            ->and($appointment->service_id)->toBe($service->getKey())
            ->and($appointment->status->value)->toBe('confirmed')
            ->and($appointment->starts_at->toDateString())->toBe($tomorrow->toDateString())
            ->and(AppointmentNotification::query()->where('user_id', $barberUser->getKey())->count())->toBe(1);

        actingWithoutTenant();

        // A conversa volta ao menu inicial: a próxima mensagem recomeça.
        actingInTenant($tenant);
        expect(WhatsAppConversation::query()
            ->where('phone', '5511999999999')
            ->firstOrFail()
            ->step)->toBe('choosing_barber');
        actingWithoutTenant();
    });

    it('não pergunta o nome quando o telefone já é cliente e usa o nome cadastrado', function (): void {
        [$tenant] = tenantWithUser(MembershipRole::Admin);
        flowConnectNumber($tenant);
        [$barber, $service, $tomorrow] = whatsappBookableBarber($tenant);

        actingInTenant($tenant);
        Customer::factory()->create([
            'tenant_id' => $tenant->getKey(),
            'name' => 'Cliente Antigo',
            'phone' => '11999999999',
        ]);
        actingWithoutTenant();

        flowSendText('oi', 'wamid.1');
        flowSendText('1', 'wamid.2');
        flowSendText('1', 'wamid.3');
        flowSendText('2', 'wamid.4');
        flowSendText('1', 'wamid.5');

        Http::assertSent(fn (ClientRequest $request): bool => str_contains(flowReplyBody($request), 'Confirma o agendamento?')
            && str_contains(flowReplyBody($request), 'Cliente: Cliente Antigo'));
        Http::assertNotSent(fn (ClientRequest $request): bool => flowReplyBody($request) === 'Qual é o seu nome?');

        flowSendText('1', 'wamid.6');
        Http::assertSent(fn (ClientRequest $request): bool => str_contains(flowReplyBody($request), 'Agendado!'));

        actingInTenant($tenant);
        expect(Appointment::query()->count())->toBe(1)
            ->and(Customer::query()->where('phone', '11999999999')->count())->toBe(1)
            ->and(Customer::query()->where('phone', '11999999999')->firstOrFail()->name)->toBe('Cliente Antigo');
        actingWithoutTenant();
    });

    it('repete o menu quando a opção não existe e reinicia com menu', function (): void {
        [$tenant] = tenantWithUser(MembershipRole::Admin);
        flowConnectNumber($tenant);
        whatsappBookableBarber($tenant);

        flowSendText('oi', 'wamid.1');
        flowSendText('9', 'wamid.2');
        Http::assertSent(fn (ClientRequest $request): bool => str_contains(flowReplyBody($request), 'Escolha o barbeiro'));

        flowSendText('1', 'wamid.3');
        flowSendText('menu', 'wamid.4');
        Http::assertSent(fn (ClientRequest $request): bool => str_contains(flowReplyBody($request), 'Escolha o barbeiro'));

        actingInTenant($tenant);
        expect(WhatsAppConversation::query()->where('phone', '5511999999999')->firstOrFail()->step)
            ->toBe('choosing_barber');
        actingWithoutTenant();
    });

    it('pagina os horários e o zero abre a próxima leva', function (): void {
        [$tenant] = tenantWithUser(MembershipRole::Admin);
        flowConnectNumber($tenant);
        whatsappBookableBarber($tenant);

        flowSendText('oi', 'wamid.1');
        flowSendText('1', 'wamid.2');
        flowSendText('1', 'wamid.3');
        flowSendText('2', 'wamid.4');

        // 09:00–12:00 com serviço de 30 min: 11 slots, e a primeira página
        // cabe 8 — então a leva seguinte existe e o "0" tem o que mostrar.
        Http::assertSent(fn (ClientRequest $request): bool => str_contains(flowReplyBody($request), '0. Mais horários'));

        flowSendText('0', 'wamid.5');
        Http::assertSent(fn (ClientRequest $request): bool => str_contains(flowReplyBody($request), 'Horários livres em')
            && ! str_contains(flowReplyBody($request), '0. Mais horários')
            && str_contains(flowReplyBody($request), '9. 11:30') === false);

        flowSendText('1', 'wamid.6');
        Http::assertSent(fn (ClientRequest $request): bool => flowReplyBody($request) === 'Qual é o seu nome?');
    });

    it('avisa quando o horário foi ocupado no meio do fluxo e reexibe a lista', function (): void {
        [$tenant] = tenantWithUser(MembershipRole::Admin);
        flowConnectNumber($tenant);
        [$barber, $service, $tomorrow] = whatsappBookableBarber($tenant);

        actingInTenant($tenant);
        Customer::factory()->create([
            'tenant_id' => $tenant->getKey(),
            'name' => 'Cliente Antigo',
            'phone' => '11999999999',
        ]);
        actingWithoutTenant();

        flowSendText('oi', 'wamid.1');
        flowSendText('1', 'wamid.2');
        flowSendText('1', 'wamid.3');
        flowSendText('2', 'wamid.4');
        flowSendText('1', 'wamid.5'); // primeiro slot: 09:00

        // Outro canal ocupa 09:00–09:30 entre a listagem e a confirmação.
        actingInTenant($tenant);
        $busyCustomer = Customer::factory()->create([
            'tenant_id' => $tenant->getKey(),
            'phone' => '11987654321',
        ]);
        Appointment::query()->create([
            'customer_id' => $busyCustomer->getKey(),
            'barber_id' => $barber->getKey(),
            'service_id' => $service->getKey(),
            'starts_at' => $tomorrow->setTime(9, 0),
            'ends_at' => $tomorrow->setTime(9, 30),
            'status' => 'confirmed',
        ]);
        actingWithoutTenant();

        flowSendText('1', 'wamid.6');

        Http::assertSent(fn (ClientRequest $request): bool => str_contains(flowReplyBody($request), 'acabou de ficar indisponível')
            && str_contains(flowReplyBody($request), 'Horários livres em')
            && ! str_contains(flowReplyBody($request), '1. 09:00'));

        actingInTenant($tenant);
        expect(Appointment::query()->count())->toBe(1)
            ->and(Customer::query()->where('phone', '11999999999')->count())->toBe(1);
        actingWithoutTenant();
    });

    it('cada número atende só a própria barbearia', function (): void {
        [$tenantA] = tenantWithUser(MembershipRole::Admin);
        [$tenantB] = tenantWithUser(MembershipRole::Admin);
        flowConnectNumber($tenantA, '777');
        flowConnectNumber($tenantB, '888');
        whatsappBookableBarber($tenantA, 'Lucas A');
        whatsappBookableBarber($tenantB, 'João B');

        flowSendText('oi', 'wamid.a1', '5511999999999', '777');
        Http::assertSent(fn (ClientRequest $request): bool => str_contains(flowReplyBody($request), 'Lucas A'));
        Http::assertNotSent(fn (ClientRequest $request): bool => str_contains(flowReplyBody($request), 'João B'));

        flowSendText('oi', 'wamid.b1', '5511888888888', '888');
        Http::assertSent(fn (ClientRequest $request): bool => str_contains(flowReplyBody($request), 'João B'));

        actingInTenant($tenantA);
        expect(WhatsAppConversation::query()->count())->toBe(1)
            ->and(WhatsAppConversation::query()->firstOrFail()->phone)->toBe('5511999999999')
            ->and(Appointment::query()->count())->toBe(0);
        actingWithoutTenant();

        actingInTenant($tenantB);
        expect(WhatsAppConversation::query()->count())->toBe(1)
            ->and(WhatsAppConversation::query()->firstOrFail()->phone)->toBe('5511888888888');
        actingWithoutTenant();
    });
});
