<?php

namespace App\Application\Payments\Actions;

use App\Application\Cash\CashRegisterBalance;
use App\Application\Payments\IdempotencyKeyLock;
use App\Application\Payments\PaymentBalance;
use App\Application\Shared\Actions\Action;
use App\Domain\Cash\Enums\CashRegisterStatus;
use App\Domain\Cash\Enums\CashTransactionType;
use App\Domain\Payments\Enums\PaymentEventType;
use App\Domain\Payments\Enums\PaymentMethod;
use App\Models\CashRegister;
use App\Models\CashTransaction;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\PaymentRefund;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class RefundPayment extends Action
{
    /**
     * @param  array{payment: Payment, amount: string, reason: string, idempotency_key: string, actor_id: int|null}  $input
     * @return array{refund: PaymentRefund}
     */
    protected function handle(array $input): array
    {
        return DB::transaction(function () use ($input): array {
            IdempotencyKeyLock::acquire('refund', $input['idempotency_key']);
            $payment = Payment::query()
                ->whereKey($input['payment']->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $existing = PaymentRefund::query()
                ->where('idempotency_key', $input['idempotency_key'])
                ->first();

            if ($existing !== null) {
                if ((int) $existing->payment_id !== (int) $payment->getKey()
                    || PaymentBalance::toMinorUnits($existing->amount) !== PaymentBalance::toMinorUnits($input['amount'])
                    || $existing->reason !== $input['reason']) {
                    throw ValidationException::withMessages([
                        'idempotency_key' => 'Esta chave já foi usada com dados diferentes.',
                    ]);
                }

                return ['refund' => $existing];
            }

            $amount = PaymentBalance::toMinorUnits($input['amount']);
            $alreadyRefunded = $payment->refunds()
                ->get(['amount'])
                ->sum(fn (PaymentRefund $refund): int => PaymentBalance::toMinorUnits($refund->amount));

            if ($amount > PaymentBalance::toMinorUnits($payment->amount) - $alreadyRefunded) {
                throw ValidationException::withMessages([
                    'amount' => 'O estorno excede o valor ainda disponível deste pagamento.',
                ]);
            }

            $register = $payment->method === PaymentMethod::Cash
                ? CashRegister::query()
                    ->where('status', CashRegisterStatus::Open)
                    ->lockForUpdate()
                    ->first()
                : null;

            if ($payment->method === PaymentMethod::Cash && $register === null) {
                throw ValidationException::withMessages([
                    'cash_register' => 'Abra o caixa antes de estornar um pagamento em dinheiro.',
                ]);
            }

            if ($register !== null) {
                if (CashRegisterBalance::expectedMinorUnits($register) < $amount) {
                    throw ValidationException::withMessages([
                        'amount' => 'O caixa não possui saldo suficiente para devolver este valor.',
                    ]);
                }
            }

            $refund = PaymentRefund::query()->create([
                'payment_id' => $payment->getKey(),
                'amount' => PaymentBalance::fromMinorUnits($amount),
                'reason' => $input['reason'],
                'idempotency_key' => $input['idempotency_key'],
                'refunded_by' => $input['actor_id'],
                'refunded_at' => now(),
            ]);
            PaymentEvent::query()->create([
                'payment_id' => $payment->getKey(),
                'refund_id' => $refund->getKey(),
                'type' => PaymentEventType::PaymentRefunded,
                'amount' => $refund->amount,
                'actor_id' => $input['actor_id'],
                'idempotency_key' => $input['idempotency_key'],
                'created_at' => now(),
            ]);

            if ($register !== null) {
                CashTransaction::query()->create([
                    'cash_register_id' => $register->getKey(),
                    'refund_id' => $refund->getKey(),
                    'type' => CashTransactionType::Refund,
                    'amount' => $refund->amount,
                    'description' => "Estorno do pagamento #{$payment->getKey()}",
                    'created_by' => $input['actor_id'],
                ]);
            }

            return ['refund' => $refund->load('payment')];
        });
    }
}
