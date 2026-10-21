<?php

namespace App\Models;

use App\Infrastructure\Shared\Persistence\TenantScopedModel;

/**
 * Estado da conversa do bot de WhatsApp dentro de uma barbearia.
 *
 * Uma linha por telefone: o passo atual do menu numerado e as escolhas já
 * feitas (barbeiro, serviço, dia, horário, nome). `last_message_id` é o `wamid`
 * da última mensagem processada, usado para ignorar reentregas do webhook.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $phone
 * @property string $step
 * @property array<string, mixed>|null $context
 * @property string|null $last_message_id
 */
class WhatsAppConversation extends TenantScopedModel
{
    protected $table = 'whatsapp_conversations';

    protected $fillable = ['phone', 'step', 'context', 'last_message_id'];

    protected function casts(): array
    {
        return [
            'context' => 'array',
        ];
    }
}
