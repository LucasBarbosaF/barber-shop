<?php

namespace App\Infrastructure\WhatsApp\Jobs;

use App\Application\Contracts\WhatsAppGateway;
use App\Infrastructure\Shared\Jobs\Job;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Envia uma mensagem de texto pelo WhatsApp, fora da requisição do webhook.
 *
 * O webhook precisa responder 200 ao Meta em segundos; a Graph API pode demorar
 * ou falhar, e o retry é do job. Todo dado viaja no payload — token,
 * `phone_number_id`, destino e texto —, então o job não toca no banco e não
 * precisa de tenant nem de RLS para rodar na fila.
 */
class SendWhatsAppMessage extends Job implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $backoff = 10;

    public function __construct(
        public readonly string $to,
        public readonly string $text,
        public readonly string $phoneNumberId,
        public readonly string $accessToken,
    ) {}

    public function handle(WhatsAppGateway $gateway): void
    {
        $gateway->sendText($this->accessToken, $this->phoneNumberId, $this->to, $this->text);
    }
}
