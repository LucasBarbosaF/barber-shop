<?php

namespace App\Application\Recurrence;

use App\Domain\Agenda\Enums\AppointmentStatus;
use App\Domain\Recurrence\Enums\SubscriptionStatus;
use App\Models\Appointment;
use App\Models\RecurrenceSubscription;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * As três regras de cota da recorrência, num único ponto de decisão.
 *
 * 1. **Cota mensal** (`sessions_per_month`): 2 cortes por mês calendário, sem
 *    acúmulo — o que não usar em outubro perde.
 * 2. **Teto semanal** (`max_sessions_per_week`, máximo 2): nenhuma semana
 *    concentra mais do que o dobro da cota mensal.
 * 3. **Teto total** (`duration_months × sessions_per_month`): sem ele, uma
 *    assinatura que começa dia 20 ganharia mês cheio + mês parcial e
 *    entregaria 14 cortes num plano de 12.
 *
 * Contagem: agendamentos vinculados com `status != canceled`. Cancelar devolve
 * a cota (remarcação não gasta corte); `no_show` consome — o horário foi
 * ocupado e o cliente não apareceu.
 *
 * A cota é revalidada **dentro da transação** que cria o agendamento
 * (`ScheduleSubscriptionCut`). Este objeto existe para o painel e o site
 * exibirem o saldo da mesma fonte que decide a negação.
 */
final class RecurrenceQuota
{
    public function usedInMonth(RecurrenceSubscription $subscription, CarbonImmutable $date): int
    {
        return $this->countBetween($subscription, $date->startOfMonth(), $date->startOfMonth()->addMonth());
    }

    public function usedInWeek(RecurrenceSubscription $subscription, CarbonImmutable $date): int
    {
        return $this->countBetween($subscription, $date->startOfWeek(), $date->startOfWeek()->addWeek());
    }

    public function usedInTerm(RecurrenceSubscription $subscription): int
    {
        $startsAt = $subscription->starts_at->toImmutable()->startOfDay();

        return $this->countBetween($subscription, $startsAt, $subscription->ends_at->toImmutable()->addDay());
    }

    public function remainingInMonth(RecurrenceSubscription $subscription, CarbonImmutable $date): int
    {
        return max(0, $subscription->sessions_per_month - $this->usedInMonth($subscription, $date));
    }

    public function remainingInTerm(RecurrenceSubscription $subscription): int
    {
        return max(0, $subscription->total_sessions - $this->usedInTerm($subscription));
    }

    /**
     * Lança `ValidationException` quando a data viola qualquer uma das três cotas.
     *
     * `$allowUnpaid` existe para o corte de abertura, que nasce junto com a
     * assinatura ainda `pending_payment` — é o único corte que o cliente pode
     * marcar sem ter pago, e as cotas continuam valendo para ele.
     */
    public function assertCanSchedule(
        RecurrenceSubscription $subscription,
        CarbonImmutable $date,
        bool $allowUnpaid = false,
    ): void {
        if ($subscription->status === SubscriptionStatus::PendingPayment) {
            if (! $allowUnpaid) {
                throw ValidationException::withMessages([
                    'subscription' => 'Aguarde a barbearia confirmar o pagamento do plano para agendar novos cortes.',
                ]);
            }
        } elseif (! $subscription->isSchedulable()) {
            throw ValidationException::withMessages([
                'subscription' => 'Esta assinatura não está mais ativa.',
            ]);
        }

        $day = $date->startOfDay();

        if ($day->lt($subscription->starts_at->toImmutable()->startOfDay())
            || $day->gt($subscription->ends_at->toImmutable()->startOfDay())) {
            throw ValidationException::withMessages([
                'date' => sprintf(
                    'Seu plano vale de %s até %s.',
                    $subscription->starts_at->format('d/m/Y'),
                    $subscription->ends_at->format('d/m/Y'),
                ),
            ]);
        }

        /*
         * Mês sem mensalidade paga não agenda — o cliente só tem acesso aos
         * meses que já foram cobrados. O corte de abertura (allowUnpaid) passa
         * direto: ele nasce junto com a assinatura, antes do primeiro
         * pagamento.
         */
        if (! $allowUnpaid && ! $subscription->hasPaidMonthFor($day)) {
            throw ValidationException::withMessages([
                'date' => sprintf(
                    'O pagamento de %s ainda não foi confirmado. Registre o pagamento para liberar os cortes deste mês.',
                    $day->locale('pt_BR')->isoFormat('MMMM [de] YYYY'),
                ),
            ]);
        }

        if ($this->usedInTerm($subscription) >= $subscription->total_sessions) {
            throw ValidationException::withMessages([
                'date' => sprintf('Os %d cortes do seu plano já foram usados.', $subscription->total_sessions),
            ]);
        }

        if ($this->usedInMonth($subscription, $day) >= $subscription->sessions_per_month) {
            throw ValidationException::withMessages([
                'date' => sprintf(
                    'Você já usou os %d cortes de %s. O próximo mês libera novos cortes.',
                    $subscription->sessions_per_month,
                    $day->locale('pt_BR')->isoFormat('MMMM'),
                ),
            ]);
        }

        if ($this->usedInWeek($subscription, $day) >= $subscription->max_sessions_per_week) {
            throw ValidationException::withMessages([
                'date' => sprintf(
                    'Máximo de %d cortes por semana. Escolha uma data da próxima semana.',
                    $subscription->max_sessions_per_week,
                ),
            ]);
        }
    }

    /**
     * Agendamentos que ocupam cota: todos menos os cancelados.
     */
    private function countBetween(RecurrenceSubscription $subscription, CarbonImmutable $start, CarbonImmutable $end): int
    {
        return Appointment::query()
            ->where('recurrence_subscription_id', $subscription->getKey())
            ->where('status', '!=', AppointmentStatus::Canceled->value)
            ->where('starts_at', '>=', $start)
            ->where('starts_at', '<', $end)
            ->count();
    }
}
