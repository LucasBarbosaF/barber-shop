<?php

namespace App\Console\Commands;

use App\Domain\Tenant\Models\Tenant;
use Illuminate\Console\Command;

/**
 * Registra o número de WhatsApp de uma barbearia para o bot de agendamento.
 *
 * O `phone_number_id` é o id do número dentro da conta business do Meta (não é
 * o número de telefone) e chega no payload de cada notificação — é a chave com
 * que o webhook acha a barbearia. O token é opcional: sem ele o envio usa o
 * `WHATSAPP_ACCESS_TOKEN` global.
 */
class WhatsAppChannelCommand extends Command
{
    protected $signature = 'whatsapp:channel
        {slug : Slug da barbearia (ex.: barbearia-sanca)}
        {phone_number_id : phone_number_id do Meta para este número}
        {--token= : Access token específico desta barbearia}';

    protected $description = 'Vincula um número de WhatsApp (phone_number_id) a uma barbearia';

    public function handle(): int
    {
        $tenant = Tenant::query()
            ->where('slug', $this->argument('slug'))
            ->firstOrFail();

        $settings = $tenant->whatsapp_settings ?? [];
        $settings['phone_number_id'] = (string) $this->argument('phone_number_id');

        if ($this->option('token')) {
            $settings['access_token'] = (string) $this->option('token');
        }

        $tenant->forceFill(['whatsapp_settings' => $settings])->save();

        $this->info(sprintf(
            'Número %s vinculado a %s (%s).',
            $settings['phone_number_id'],
            $tenant->name,
            $tenant->slug,
        ));

        return self::SUCCESS;
    }
}
