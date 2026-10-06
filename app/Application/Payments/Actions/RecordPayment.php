<?php

namespace App\Application\Payments\Actions;

use App\Application\Payments\IdempotencyKeyLock;
use App\Application\Payments\PaymentBalance;
use App\Application\Shared\Actions\Action;
use App\Domain\Attendance\Enums\AttendanceStatus;
use App\Domain\Cash\Enums\CashRegisterStatus;
use App\Domain\Cash\Enums\CashTransactionType;
use App\Domain\Payments\Enums\PaymentEventType;
use App\Domain\Payments\Enums\PaymentMethod;
use App\Models\Attendance;
use App\Models\CashRegister;
use App\Models\CashTransaction;
use App\Models\Payment;
use App\Models\PaymentEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class RecordPayment extends Action
{
    /**
     * @param  array{attendance: Attendance, amount: string, method: PaymentMethod, idempotency_key: string, actor_id: int|null}  $input
     * @return array{payment: Payment}
     */
    protected function handle(array $input): array
    {
        return DB::transaction(function () use ($input): array {
            IdempotencyKeyLock::acquire('payment', $input['idempotency_key']);
            $attendance = Attendance::query()
                ->whereKey($input['attendance']->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $existing = Payment::query()
                ->where('idempotency_key', $input['idempotency_key'])
                ->first();

            if ($existing !== null) {
                if ((int) $existing->attendance_id !== (int) $attendance->getKey()
                    || PaymentBalance::toMinorUnits($existing->amount) !== PaymentBalance::toMinorUnits($input['amount'])
                    || $existing->method !== $input['method']) {
                    throw ValidationException::withMessages([
                        'idempotency_key' => 'Esta chave já foi usada com dados diferentes.',
                    ]);
                }

                return ['payment' => $existing->load('events')];
            }

            if ($attendance->status !== AttendanceStatus::Closed) {
                throw ValidationException::withMessages([
                    'attendance' => 'Feche o atendimento antes de registrar o pagamento.',
                ]);
            }

            $amount = PaymentBalance::toMinorUnits($input['amount']);
            $balance = PaymentBalance::totalDue($attendance) - PaymentBalance::totalPaid($attendance);

            if ($amount > $balance) {
                throw ValidationException::withMessages([
                    'amount' => 'O pagamento excede o saldo do atendimento.',
                ]);
            }

            $register = $input['method'] === PaymentMethod::Cash
                ? CashRegister::query()
                    ->where('status', CashRegisterStatus::Open)
                    ->lockForUpdate()
                    ->first()
                : null;

            if ($input['method'] === PaymentMethod::Cash && $register === null) {
                throw ValidationException::withMessages([
                    'cash_register' => 'Abra o caixa antes de registrar um pagamento em dinheiro.',
                ]);
            }

            $payment = Payment::query()->create([
                'attendance_id' => $attendance->getKey(),
                'amount' => PaymentBalance::fromMinorUnits($amount),
                'method' => $input['method'],
                'idempotency_key' => $input['idempotency_key'],
                'created_by' => $input['actor_id'],
            ]);
            PaymentEvent::query()->create([
                'payment_id' => $payment->getKey(),
                'type' => PaymentEventType::PaymentReceived,
                'amount' => $payment->amount,
                'actor_id' => $input['actor_id'],
                'idempotency_key' => $input['idempotency_key'],
                'created_at' => now(),
            ]);

            if ($register !== null) {
                CashTransaction::query()->create([
                    'cash_register_id' => $register->getKey(),
                    'payment_id' => $payment->getKey(),
                    'type' => CashTransactionType::Payment,
                    'amount' => $payment->amount,
                    'description' => "Pagamento do atendimento #{$attendance->getKey()}",
                    'created_by' => $input['actor_id'],
                ]);
            }

            return ['payment' => $payment->load('events')];
        });
    }
}
