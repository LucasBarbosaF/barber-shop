<?php

namespace App\Infrastructure\WhatsApp;

use App\Application\Contracts\WhatsAppGateway;
use Illuminate\Support\Facades\Http;

/**
 * Adaptador do WhatsApp Cloud API (Meta Graph API).
 *
 * Endereço, versão e formato do payload são detalhes do provedor — a
 * aplicação só enxerga a porta `WhatsAppGateway`. Falha de HTTP lança exceção
 * de propósito: quem envia é o job `SendWhatsAppMessage`, que tem retry, e um
 * silenciar aqui seria uma resposta que o cliente nunca recebe.
 */
final class MetaCloudApiGateway implements WhatsAppGateway
{
    public function sendText(string $accessToken, string $phoneNumberId, string $to, string $text): void
    {
        $version = (string) config('services.whatsapp.graph_version', 'v21.0');

        Http::withToken($accessToken)
            ->acceptJson()
            ->post("https://graph.facebook.com/{$version}/{$phoneNumberId}/messages", [
                'messaging_product' => 'whatsapp',
                'to' => $to,
                'type' => 'text',
                'text' => ['preview_url' => false, 'body' => $text],
            ])
            ->throw();
    }
}
