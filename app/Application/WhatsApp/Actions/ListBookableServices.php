<?php

namespace App\Application\WhatsApp\Actions;

use App\Application\Shared\Actions\Action;
use App\Models\Barber;

/**
 * Serviços que um barbeiro escolhido no menu realiza.
 *
 * Espelha o segundo passo do agendamento público: só serviços ativos, e só os
 * vinculados ao barbeiro — fora do vínculo, `ListAvailableSlots` recusaria com
 * "este barbeiro não realiza o serviço", tarde demais para quem está no bot.
 */
final class ListBookableServices extends Action
{
    /**
     * @param  array{barber_id: int}  $input
     * @return array{services: array<int, array{id: int, name: string, duration_minutes: int, price: string|null}>}
     */
    protected function handle(array $input): array
    {
        $barber = Barber::query()
            ->whereKey($input['barber_id'])
            ->where('is_active', true)
            ->whereNotNull('user_id')
            ->firstOrFail();

        $services = $barber->services()
            ->where('services.is_active', true)
            ->orderBy('services.name')
            ->get()
            ->map(fn ($service): array => [
                'id' => (int) $service->getKey(),
                'name' => $service->name,
                'duration_minutes' => (int) $service->duration_minutes,
                'price' => $service->price,
            ])
            ->all();

        return ['services' => $services];
    }
}
