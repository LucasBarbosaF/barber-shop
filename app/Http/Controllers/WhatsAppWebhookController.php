<?php

namespace App\Http\Controllers;

use App\Application\WhatsApp\Actions\HandleIncomingMessage;
use App\Application\WhatsApp\Support\WhatsAppPhone;
use App\Domain\Tenant\Models\Tenant;
use App\Infrastructure\WhatsApp\Jobs\SendWhatsAppMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Webhook do WhatsApp Cloud API.
 *
 * Duas rotas, o mesmo contrato do Meta: `GET` responde o desafio de
 * verificação do app, `POST` recebe as notificações. A assinatura é conferida
 * antes de tudo (`VerifyWhatsAppSignature`), e o tenant sai do payload
 * (`SetWhatsAppWebhookTenant`) — nenhum dos dois depende de sessão, que o
 * webhook não tem.
 *
 * O POST responde 200 rápido e despacha a resposta pela fila: o Meta corta a
 * notificação em ~20s e reenvia depois, e reenvio é justamente o que o
 * `last_message_id` da conversa absorve.
 */
class WhatsAppWebhookController extends Controller
{
    public function verify(Request $request): Response
    {
        /*
         * O Meta manda `hub.mode`, `hub.verify_token` e `hub.challenge`, mas o
         * PHP converte ponto em underline nos nomes de parâmetro de query
         * string — por isso os exemplos oficiais dele leem `hub_verify_token`.
         * Aceitar as duas formas cobre o PHP-FPM (underscore) e clientes que
         * preservam o ponto (Symfony puro, testes).
         */
        $mode = $this->queryParam($request, 'hub.mode');
        $token = $this->queryParam($request, 'hub.verify_token');
        $challenge = $this->queryParam($request, 'hub.challenge');
        $expected = (string) config('services.whatsapp.verify_token', '');

        abort_unless(
            $mode === 'subscribe' && $expected !== '' && hash_equals($expected, $token),
            403,
        );

        return response($challenge, 200)->header('Content-Type', 'text/plain');
    }

    public function receive(Request $request, HandleIncomingMessage $handleIncomingMessage): JsonResponse
    {
        $tenant = $this->tenant($request);
        $settings = $tenant->whatsapp_settings ?? [];
        $phoneNumberId = (string) ($settings['phone_number_id'] ?? '');
        $accessToken = (string) ($settings['access_token'] ?? config('services.whatsapp.access_token') ?? '');

        if ($phoneNumberId === '' || $accessToken === '') {
            Log::warning('whatsapp.canal_sem_token', ['barbearia' => $tenant->slug]);

            return response()->json(['status' => 'ok']);
        }

        foreach ($this->incomingMessages($request) as $message) {
            $result = $handleIncomingMessage->execute([
                'phone' => $message['from'],
                'text' => $message['text'],
                'message_id' => $message['id'],
                'tenant_name' => $tenant->name,
            ]);

            foreach ($result['replies'] as $reply) {
                SendWhatsAppMessage::dispatch(
                    WhatsAppPhone::international($message['from']) ?? $message['from'],
                    $reply,
                    $phoneNumberId,
                    $accessToken,
                );
            }
        }

        return response()->json(['status' => 'ok']);
    }

    /**
     * Só mensagens de texto (ou resposta de botão) viram entrada da conversa:
     * mídia, sticker e os `statuses` de entrega são confirmados com 200 e
     * ignorados — o bot fala texto, e o Meta precisa parar de reenviar.
     *
     * @return array<int, array{id: string, from: string, text: string}>
     */
    private function incomingMessages(Request $request): array
    {
        $messages = [];

        foreach ($request->json('entry', []) as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                if (($change['field'] ?? null) !== 'messages') {
                    continue;
                }

                foreach ($change['value']['messages'] ?? [] as $message) {
                    $text = $message['text']['body'] ?? $message['button']['text'] ?? null;

                    if ($text === null || ! isset($message['from'], $message['id'])) {
                        continue;
                    }

                    $messages[] = [
                        'id' => (string) $message['id'],
                        'from' => (string) $message['from'],
                        'text' => (string) $text,
                    ];
                }
            }
        }

        return $messages;
    }

    private function queryParam(Request $request, string $name): string
    {
        $query = $request->query;

        return (string) ($query->get(str_replace('.', '_', $name)) ?? $query->get($name) ?? '');
    }

    private function tenant(Request $request): Tenant
    {
        $tenant = $request->attributes->get('whatsapp_tenant');
        abort_unless($tenant instanceof Tenant, 404);

        return $tenant;
    }
}
