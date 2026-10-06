<?php

namespace App\Application\Commissions\Actions;

use App\Application\Shared\Actions\Action;
use App\Domain\Commissions\Enums\CommissionPeriodStatus;
use App\Models\Commission;
use App\Models\CommissionPayment;
use App\Models\CommissionPeriod;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PayCommissions extends Action
{
    /**
     * @param  array{period: CommissionPeriod, barber_id: int, idempotency_key: string, actor_id: int|null}  $input
     * @return array{payment: CommissionPayment}
     */
    protected function handle(array $input): array
    {
        return DB::transaction(function () use ($input): array {
            DB::selectOne(
                'SELECT pg_advisory_xact_lock(hashtext(?))',
                ['commission-payment:'.$input['idempotency_key']],
            );

            $period = CommissionPeriod::query()
                ->whereKey($input['period']->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $existingPayment = CommissionPayment::query()
                ->where('idempotency_key', $input['idempotency_key'])
                ->first();

            if ($existingPayment !== null) {
                if (
                    (int) $existingPayment->period_id !== (int) $period->getKey()
                    || (int) $existingPayment->barber_id !== $input['barber_id']
                ) {
                    throw ValidationException::withMessages([
                        'idempotency_key' => 'Esta chave já foi usada em outro repasse.',
                    ]);
                }

                return ['payment' => $existingPayment];
            }

            $commissions = Commission::query()
                ->where('period_id', $period->getKey())
                ->where('barber_id', $input['barber_id'])
                ->whereNull('commission_payment_id')
                ->where('amount', '>', 0)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($commissions->isEmpty()) {
                throw ValidationException::withMessages([
                    'barber_id' => 'Não há comissões pendentes para repassar neste período.',
                ]);
            }

            $amountInCents = $commissions->sum(
                static function (Commission $commission): int {
                    [$whole, $fraction] = array_pad(explode('.', $commission->amount, 2), 2, '00');

                    return ((int) $whole * 100) + (int) str_pad(substr($fraction, 0, 2), 2, '0');
                },
            );

            $payment = CommissionPayment::query()->create([
                'period_id' => $period->getKey(),
                'barber_id' => $input['barber_id'],
                'amount' => intdiv($amountInCents, 100).'.'.str_pad((string) ($amountInCents % 100), 2, '0', STR_PAD_LEFT),
                'idempotency_key' => $input['idempotency_key'],
                'paid_by' => $input['actor_id'],
                'paid_at' => now(),
            ]);

            Commission::query()
                ->whereIn('id', $commissions->modelKeys())
                ->update(['commission_payment_id' => $payment->getKey()]);

            if (! Commission::query()
                ->where('period_id', $period->getKey())
                ->where('amount', '>', 0)
                ->whereNull('commission_payment_id')
                ->exists()) {
                $period->update(['status' => CommissionPeriodStatus::Paid]);
            }

            return ['payment' => $payment->load('commissions')];
        });
    }
}
