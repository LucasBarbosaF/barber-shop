<?php

namespace App\Application\Recurrence\Actions;

use App\Application\Shared\Actions\Action;
use App\Domain\Agenda\Enums\AppointmentStatus;
use App\Domain\Recurrence\Enums\SubscriptionStatus;
use App\Models\Appointment;
use App\Models\RecurrenceSubscription;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cancela uma assinatura e, opcionalmente, os cortes futuros vinculados.
 *
 * Cancelar a assinatura não mexe nos cortes já atendidos: o histórico é o que
 * sustenta comissão e relatório. Os cortes futuros ficam como estão por padrão
 * porque podem já ter sido remarcados pela agenda — o checkbox "cancelar também
 * os N cortes futuros" é a confirmação explícita de quem opera.
 */
final class CancelSubscription extends Action
{
    /**
     * @param  array{
     *     subscription: RecurrenceSubscription,
     *     reason?: string|null,
     *     cancel_future_appointments?: bool,
     * }  $input
     * @return array{subscription: RecurrenceSubscription, canceled_appointments: int}
     */
    protected function handle(array $input): array
    {
        return DB::transaction(function () use ($input): array {
            $subscription = RecurrenceSubscription::query()
                ->whereKey($input['subscription']->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $cancellable = [
                SubscriptionStatus::PendingPayment->value,
                SubscriptionStatus::Active->value,
            ];

            if (! in_array($subscription->status->value, $cancellable, true)) {
                throw ValidationException::withMessages([
                    'status' => 'Somente assinaturas pendentes de pagamento ou ativas podem ser canceladas.',
                ]);
            }

            $cancelFuture = (bool) ($input['cancel_future_appointments'] ?? false);

            $subscription->update([
                'status' => SubscriptionStatus::Canceled,
                'canceled_at' => now(),
                'cancelation_reason' => $input['reason'] ?? null,
                'cancel_future_appointments' => $cancelFuture,
            ]);

            $canceledAppointments = 0;
            if ($cancelFuture) {
                $canceledAppointments = Appointment::query()
                    ->where('recurrence_subscription_id', $subscription->getKey())
                    ->whereIn('status', AppointmentStatus::blockingValues())
                    ->where('starts_at', '>=', now())
                    ->update(['status' => AppointmentStatus::Canceled->value]);
            }

            return [
                'subscription' => $subscription->refresh(),
                'canceled_appointments' => $canceledAppointments,
            ];
        });
    }
}
