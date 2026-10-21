<?php

namespace App\Application\Recurrence\Actions;

use App\Application\Shared\Actions\Action;
use App\Domain\Cash\Enums\CashRegisterStatus;
use App\Domain\Cash\Enums\CashTransactionType;
use App\Domain\Recurrence\Enums\PaymentStatus;
use App\Models\CashRegister;
use App\Models\RecurrenceSubscription;
use App\Models\RecurrenceSubscriptionPayment;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Lança as mensalidades pagas da assinatura como entradas no caixa — uma
 * entrada por mês, porque o cliente pode pagar outubro e não pagar novembro:
 * um lançamento único de "a assinatura" mentiria o fechamento.
 *
 * Serve os dois momentos: a confirmação do pagamento (quando já há caixa
 * aberto) e o botão "Lançar no caixa" do detalhe (quando a confirmação
 * aconteceu com o caixa fechado). Idempotente pelo estado: mensalidade já
 * lançada não gera duplicata.
 */
final class PostSubscriptionCash extends Action
{
    /**
     * @param  array{
     *     subscription: RecurrenceSubscription,
     *     payment?: RecurrenceSubscriptionPayment,
     *     actor_id?: int|null,
     * }  $input
     * @return array{subscription: RecurrenceSubscription}
     */
    protected function handle(array $input): array
    {
        return DB::transaction(function () use ($input): array {
            $subscription = RecurrenceSubscription::query()
                ->whereKey($input['subscription']->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $payments = $subscription->payments()
                ->where('status', PaymentStatus::Paid)
                ->whereNull('cash_posted_at')
                ->when(
                    isset($input['payment']),
                    fn ($query) => $query->whereKey($input['payment']->getKey()),
                )
                ->lockForUpdate()
                ->get();

            if ($payments->isEmpty()) {
                if ($subscription->paid_at === null) {
                    throw ValidationException::withMessages([
                        'cash' => 'Confirme o pagamento antes de lançar no caixa.',
                    ]);
                }

                throw ValidationException::withMessages([
                    'cash' => 'O pagamento desta assinatura já foi lançado no caixa.',
                ]);
            }

            $register = CashRegister::query()
                ->where('status', CashRegisterStatus::Open)
                ->lockForUpdate()
                ->first();

            if ($register === null) {
                throw ValidationException::withMessages([
                    'cash_register' => 'Abra o caixa antes de lançar o pagamento da assinatura.',
                ]);
            }

            $customerName = $subscription->customer()->value('name') ?? 'cliente';

            foreach ($payments as $payment) {
                $register->transactions()->create([
                    'type' => CashTransactionType::CashIn,
                    'amount' => $payment->amount,
                    'description' => sprintf(
                        'Assinatura de recorrência %s — %s — %s',
                        $subscription->plan_name,
                        $customerName,
                        $payment->period_month->format('m/Y'),
                    ),
                    'created_by' => $input['actor_id'] ?? null,
                ]);

                $payment->update(['cash_posted_at' => now()]);
            }

            $hasPending = $subscription->payments()
                ->where('status', PaymentStatus::Paid)
                ->whereNull('cash_posted_at')
                ->exists();

            if (! $hasPending) {
                $subscription->update(['cash_posted_at' => now()]);
            }

            return ['subscription' => $subscription->refresh()];
        });
    }
}
