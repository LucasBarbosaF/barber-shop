<?php

namespace App\Application\Recurrence\Actions;

use App\Application\Shared\Actions\Action;
use App\Domain\Recurrence\Enums\SubscriptionStatus;
use App\Models\RecurrenceSubscription;

/**
 * Encerra assinaturas cuja vigência já passou.
 *
 * Roda diariamente pelo `schedule:run` (comando `recurrence:expire`). Sem ele,
 * uma assinatura ativa de dezembro continuaria "ativa" em janeiro e a cota
 * mensal liberaria cortes fora do contrato — a vigência só seria aplicada no
 * momento do agendamento, e a lista do painel mentiria.
 */
final class ExpireSubscriptions extends Action
{
    /**
     * @param  array<string, mixed>  $input
     * @return array{expired: int}
     */
    protected function handle(array $input): array
    {
        $expired = RecurrenceSubscription::query()
            ->where('status', SubscriptionStatus::Active)
            ->whereDate('ends_at', '<', today())
            ->update(['status' => SubscriptionStatus::Expired->value]);

        return ['expired' => $expired];
    }
}
