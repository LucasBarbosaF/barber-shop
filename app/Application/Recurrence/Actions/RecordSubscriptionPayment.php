<?php

namespace App\Application\Recurrence\Actions;

use App\Application\Shared\Actions\Action;
use App\Domain\Cash\Enums\CashRegisterStatus;
use App\Domain\Payments\Enums\PaymentMethod;
use App\Domain\Recurrence\Enums\PaymentStatus;
use App\Domain\Recurrence\Enums\SubscriptionStatus;
use App\Models\CashRegister;
use App\Models\RecurrenceSubscription;
use App\Models\RecurrenceSubscriptionPayment;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Registra o pagamento de uma mensalidade da assinatura (mês a mês).
 *
 * Sem gateway no MVP quem confirma o recebimento é a barbearia: a linha nasce
 * `paid` e, se houver caixa aberto, vira entrada na hora. O mesmo caminho
 * serve a ativação (mês 1) e a integração futura com o Asaas — que criará a
 * linha como `pending` e a confirmará por webhook em vez de chamar esta ação.
 *
 * Mês fora da janela de `duration_months` a partir do início é recusado: são
 * exatamente as mensalidades do contrato (R$ 120 × 6 = 6 pagamentos).
 */
final class RecordSubscriptionPayment extends Action
{
    public function __construct(private readonly PostSubscriptionCash $postCash) {}

    /**
     * @param  array{
     *     subscription: RecurrenceSubscription,
     *     period_month: string,
     *     amount?: string,
     *     method?: string|null,
     *     obs?: string|null,
     *     actor_id?: int|null,
     * }  $input
     * @return array{payment: RecurrenceSubscriptionPayment, cash_posted: bool}
     */
    protected function handle(array $input): array
    {
        return DB::transaction(function () use ($input): array {
            $subscription = RecurrenceSubscription::query()
                ->whereKey($input['subscription']->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($subscription->status !== SubscriptionStatus::Active) {
                throw ValidationException::withMessages([
                    'status' => 'Somente assinaturas ativas aceitam pagamentos.',
                ]);
            }

            $month = $this->parseMonth($input['period_month']);
            $first = $subscription->starts_at->toImmutable()->startOfMonth();
            $last = $first->addMonths($subscription->duration_months - 1);

            if ($month->lt($first) || $month->gt($last)) {
                throw ValidationException::withMessages([
                    'period_month' => 'Este mês está fora da vigência do contrato.',
                ]);
            }

            $amount = (string) ($input['amount'] ?? $subscription->price);
            if ((float) $amount <= 0) {
                throw ValidationException::withMessages(['amount' => 'Informe um valor recebido maior que zero.']);
            }

            $method = $input['method'] ?? null;
            if ($method !== null && ! in_array($method, array_column(PaymentMethod::cases(), 'value'), true)) {
                throw ValidationException::withMessages(['method' => 'Forma de pagamento inválida.']);
            }

            $payment = RecurrenceSubscriptionPayment::query()
                ->where('recurrence_subscription_id', $subscription->getKey())
                ->where('period_month', $month->toDateString())
                ->lockForUpdate()
                ->first();

            if ($payment !== null && $payment->status === PaymentStatus::Paid) {
                throw ValidationException::withMessages([
                    'period_month' => 'Já existe pagamento confirmado para este mês.',
                ]);
            }

            $attributes = [
                'amount' => $amount,
                'status' => PaymentStatus::Paid,
                'method' => $method,
                'obs' => $input['obs'] ?? null,
                'paid_at' => now(),
                'created_by' => $input['actor_id'] ?? null,
            ];

            if ($payment === null) {
                $payment = RecurrenceSubscriptionPayment::query()->create([
                    'recurrence_subscription_id' => $subscription->getKey(),
                    'period_month' => $month->toDateString(),
                    ...$attributes,
                ]);
            } else {
                $payment->update($attributes);
            }

            $registerOpen = CashRegister::query()
                ->where('status', CashRegisterStatus::Open)
                ->exists();

            $cashPosted = false;
            if ($registerOpen) {
                $this->postCash->execute([
                    'subscription' => $subscription,
                    'payment' => $payment,
                    'actor_id' => $input['actor_id'] ?? null,
                ]);
                $cashPosted = true;
            }

            return ['payment' => $payment->refresh(), 'cash_posted' => $cashPosted];
        });
    }

    private function parseMonth(string $value): CarbonImmutable
    {
        try {
            $month = CarbonImmutable::createFromFormat('!Y-m-d', $value, config('app.timezone'));
        } catch (InvalidFormatException) {
            $month = false;
        }

        if ($month === false || $month->format('Y-m-d') !== $value) {
            throw ValidationException::withMessages([
                'period_month' => 'Selecione um mês válido.',
            ]);
        }

        return $month->startOfMonth();
    }
}
