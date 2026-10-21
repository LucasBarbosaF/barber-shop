<?php

namespace App\Application\WhatsApp\Actions;

use App\Application\Shared\Actions\Action;
use App\Models\Barber;

/**
 * Barbeiros que o bot oferece no primeiro menu.
 *
 * Os critérios são os mesmos do agendamento público: ativo, com conta de
 * usuário (é quem recebe a notificação), com expediente cadastrado e ao menos
 * um serviço ativo. Um barbeiro fora dessas condições não pode fechar
 * agendamento nenhum, então não entra na lista.
 */
final class ListBookableBarbers extends Action
{
    /**
     * @return array{barbers: array<int, array{id: int, name: string}>}
     */
    protected function handle(array $input): array
    {
        $barbers = Barber::query()
            ->where('is_active', true)
            ->whereNotNull('user_id')
            ->whereHas('businessHours')
            ->whereHas('services', fn ($query) => $query->where('services.is_active', true))
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Barber $barber): array => [
                'id' => (int) $barber->getKey(),
                'name' => $barber->name,
            ])
            ->all();

        return ['barbers' => $barbers];
    }
}
