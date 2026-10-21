<?php

use App\Domain\Tenant\Enums\MembershipRole;
use App\Domain\Tenant\Models\Tenant;
use App\Models\WhatsAppConversation;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

/**
 * Camada de transporte do webhook: desafio de verificação, assinatura HMAC,
 * resolução do tenant pelo phone_number_id, idempotência e status.
 *
 * O fluxo de agendamento em si (menu numerado → Appointment) está em
 * WhatsAppBookingFlowTest.
 */
function whatsappWebhookBody(string $phoneNumberId, array $messages = [], array $statuses = []): array
{
    return [
        'object' => 'whatsapp_business_account',
        'entry' => [[
            'id' => 'WABA-1',
            'changes' => [[
                'field' => 'messages',
                'value' => [
                    'metadata' => ['phone_number_id' => $phoneNumberId],
                    'messages' => $messages,
                    'statuses' => $statuses,
                ],
            ]],
        ]],
    ];
}

function whatsappInboundMessage(string $id, string $from, string $text): array
{
    return [
        'id' => $id,
        'from' => $from,
        'timestamp' => (string) time(),
        'type' => 'text',
        'text' => ['body' => $text],
    ];
}

/**
 * POST assinado no formato que o Meta entrega: corpo bruto + HMAC do app secret.
 */
function whatsappSignedPost(array $payload, ?string $secret = null): TestResponse
{
    $content = json_encode($payload, JSON_THROW_ON_ERROR);
    $secret ??= (string) config('services.whatsapp.app_secret');

    return test()->call('POST', '/api/webhooks/whatsapp', [], [], [], [
        'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $content, $secret),
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
    ], $content);
}

function connectWhatsappNumber(Tenant $tenant, string $phoneNumberId, ?string $accessToken = 'token-da-barbearia'): Tenant
{
    $settings = ['phone_number_id' => $phoneNumberId];

    if ($accessToken !== null) {
        $settings['access_token'] = $accessToken;
    }

    $tenant->forceFill(['whatsapp_settings' => $settings])->save();

    return $tenant;
}

describe('webhook do whatsapp', function (): void {
    beforeEach(function (): void {
        config([
            'services.whatsapp.app_secret' => 'webhook-secret',
            'services.whatsapp.verify_token' => 'verify-token',
            'services.whatsapp.access_token' => 'global-token',
        ]);
    });

    it('responde ao desafio de verificação do Meta', function (): void {
        $this->get('/api/webhooks/whatsapp?'.http_build_query([
            'hub.mode' => 'subscribe',
            'hub.verify_token' => 'verify-token',
            'hub.challenge' => '123456',
        ]))
            ->assertOk()
            ->assertSee('123456');

        $this->get('/api/webhooks/whatsapp?'.http_build_query([
            'hub_mode' => 'subscribe',
            'hub_verify_token' => 'verify-token',
            'hub_challenge' => '123456',
        ]))
            ->assertOk()
            ->assertSee('123456');

        $this->get('/api/webhooks/whatsapp?'.http_build_query([
            'hub.mode' => 'subscribe',
            'hub.verify_token' => 'errado',
            'hub.challenge' => '123456',
        ]))->assertForbidden();

        $this->get('/api/webhooks/whatsapp?'.http_build_query([
            'hub.mode' => 'unsubscribe',
            'hub.verify_token' => 'verify-token',
            'hub.challenge' => '123456',
        ]))->assertForbidden();
    });

    it('recusa notificação sem assinatura ou com assinatura errada', function (): void {
        [$tenant] = tenantWithUser(MembershipRole::Admin);
        connectWhatsappNumber($tenant, '111');

        $payload = whatsappWebhookBody('111', [whatsappInboundMessage('wamid.1', '5511999999999', 'oi')]);

        $this->postJson('/api/webhooks/whatsapp', $payload)->assertUnauthorized();

        whatsappSignedPost($payload, 'outro-segredo')->assertUnauthorized();

        actingInTenant($tenant);
        expect(WhatsAppConversation::query()->count())->toBe(0);
        actingWithoutTenant();
    });

    it('responde 404 quando o número não está vinculado a nenhuma barbearia', function (): void {
        [$tenant] = tenantWithUser(MembershipRole::Admin);
        connectWhatsappNumber($tenant, '111');

        whatsappSignedPost(whatsappWebhookBody('999', [whatsappInboundMessage('wamid.1', '5511999999999', 'oi')]))
            ->assertNotFound();
    });

    it('ignora os statuses de entrega e confirma com 200', function (): void {
        Http::fake();
        [$tenant] = tenantWithUser(MembershipRole::Admin);
        connectWhatsappNumber($tenant, '111');

        whatsappSignedPost(whatsappWebhookBody('111', [], [[
            'id' => 'wamid.status',
            'status' => 'delivered',
            'recipient_id' => '5511999999999',
        ]]))->assertOk();

        actingInTenant($tenant);
        expect(WhatsAppConversation::query()->count())->toBe(0);
        actingWithoutTenant();
        Http::assertNothingSent();
    });

    it('não repete a resposta para a mesma mensagem reenviada', function (): void {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.enviada']]])]);

        [$tenant] = tenantWithUser(MembershipRole::Admin);
        connectWhatsappNumber($tenant, '111');

        $payload = whatsappWebhookBody('111', [whatsappInboundMessage('wamid.1', '5511999999999', 'oi')]);

        whatsappSignedPost($payload)->assertOk();
        whatsappSignedPost($payload)->assertOk();

        Http::assertSentCount(1);
    });

    it('não processa nem responde quando a barbearia não tem token configurado', function (): void {
        Http::fake();
        config(['services.whatsapp.access_token' => null]);

        [$tenant] = tenantWithUser(MembershipRole::Admin);
        connectWhatsappNumber($tenant, '111', accessToken: null);

        whatsappSignedPost(whatsappWebhookBody('111', [whatsappInboundMessage('wamid.1', '5511999999999', 'oi')]))
            ->assertOk();

        actingInTenant($tenant);
        expect(WhatsAppConversation::query()->count())->toBe(0);
        actingWithoutTenant();
        Http::assertNothingSent();
    });

    it('usa o access token global quando a barbearia não tem o próprio', function (): void {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.enviada']]])]);

        [$tenant] = tenantWithUser(MembershipRole::Admin);
        connectWhatsappNumber($tenant, '111', accessToken: null);

        whatsappSignedPost(whatsappWebhookBody('111', [whatsappInboundMessage('wamid.1', '5511999999999', 'oi')]))
            ->assertOk();

        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer global-token'));
    });
});
