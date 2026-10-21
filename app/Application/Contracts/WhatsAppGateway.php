<?php

namespace App\Application\Contracts;

/**
 * Porta de saída para enviar mensagens de WhatsApp.
 *
 * A aplicação só conhece esta interface; quem fala com a Graph API do Meta é a
 * implementação de Infrastructure (`MetaCloudApiGateway`). Token e
 * `phone_number_id` entram como argumento porque são configuração da barbearia
 * (canal), não estado da aplicação.
 */
interface WhatsAppGateway
{
    /**
     * Envia uma mensagem de texto para um telefone internacional (ex.: 5511999999999).
     *
     * Lança exceção quando a API recusa — quem chama é um job com retry.
     */
    public function sendText(string $accessToken, string $phoneNumberId, string $to, string $text): void;
}
