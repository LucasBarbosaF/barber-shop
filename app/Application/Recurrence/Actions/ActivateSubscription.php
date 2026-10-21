<?php

namespace App\Application\Recurrence\Actions;

use App\Application\Shared\Actions\Action;
use App\Domain\Payments\Enums\PaymentMethod;
use App\Domain\Recurrence\Enums\SubscriptionStatus;
use App\Models\RecurrenceSubscription;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Confirma o recebimento do primeiro mês e ativa a assinatura.
 *
 * Sem gateway no MVP o pagamento acontece na barbearia (dinheiro, Pix ou
 * cartão) e a recepção registra aqui. A confirmação grava a mensalidade do
 * mês inicial via `RecordSubscriptionPayment` — é ela que libera os
 * agendamentos daquele mês — e mantém os campos legados `paid_*` da
 * assinatura para o resumo do contrato.
 *
 * Se houver caixa aberto, o valor entra como movimentação de entrada na
 * mesma transação; se não houver, o lançamento fica pendente e a tela
 * oferece "Lançar no caixa" — registrar dinheiro sem caixa aberto seria
 * inventar um movimento que o fechamento não encontra.
 */
final class ActivateSubscription extends Action
{
    public function __construct(private readonly RecordSubscriptionPayment $recordPayment) {}

    /**
     * @param  array{
     *     subscription: RecurrenceSubscription,
     *     amount?: string,
     *     method?: string|null,
     *     obs?: string|null,
     *     actor_id?: int|null,
     * }  $input
     * @return array{subscription: RecurrenceSubscription, cash_posted: bool}
     */
    protected function handle(array $input): array
    {
        return DB::transaction(function () use ($input): array {
            $subscription = RecurrenceSubscription::query()
                ->whereKey($input['subscription']->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($subscription->status !== SubscriptionStatus::PendingPayment) {
                throw ValidationException::withMessages([
                    'status' => 'Somente assinaturas aguardando pagamento podem ser ativadas.',
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

            $subscription->update([
                'status' => SubscriptionStatus::Active,
                'paid_amount' => $amount,
                'paid_at' => now(),
                'paid_method' => $method,
                'paid_obs' => $input['obs'] ?? null,
                'activated_by' => $input['actor_id'] ?? null,
            ]);

            $payment = $this->recordPayment->execute([
                'subscription' => $subscription,
                'period_month' => $subscription->starts_at->toImmutable()->startOfMonth()->toDateString(),
                'amount' => $amount,
                'method' => $method,
                'obs' => $input['obs'] ?? null,
                'actor_id' => $input['actor_id'] ?? null,
            ])['payment'];

            return [
                'subscription' => $subscription->refresh(),
                'cash_posted' => $payment->cash_posted_at !== null,
            ];
        });
    }
}
