<?php

namespace App\Application\Cash\Actions;

use App\Application\Cash\CashRegisterBalance;
use App\Application\Payments\PaymentBalance;
use App\Application\Shared\Actions\Action;
use App\Domain\Cash\Enums\CashRegisterStatus;
use App\Models\CashRegister;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CloseCashRegister extends Action
{
    /**
     * @param  array{cash_register: CashRegister, closing_amount: string, actor_id: int|null}  $input
     * @return array{cash_register: CashRegister}
     */
    protected function handle(array $input): array
    {
        return DB::transaction(function () use ($input): array {
            $register = CashRegister::query()
                ->whereKey($input['cash_register']->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($register->status !== CashRegisterStatus::Open) {
                throw ValidationException::withMessages([
                    'cash_register' => 'Este caixa já foi fechado.',
                ]);
            }

            $expectedAmount = CashRegisterBalance::expectedMinorUnits($register);
            if ($expectedAmount < 0) {
                throw ValidationException::withMessages([
                    'cash_register' => 'O saldo calculado do caixa não pode ser negativo.',
                ]);
            }
            $closingAmount = PaymentBalance::toMinorUnits($input['closing_amount']);

            $register->update([
                'status' => CashRegisterStatus::Closed,
                'closing_amount' => PaymentBalance::fromMinorUnits($closingAmount),
                'expected_amount' => PaymentBalance::fromMinorUnits($expectedAmount),
                'difference_amount' => PaymentBalance::fromMinorUnits($closingAmount - $expectedAmount),
                'closed_at' => now(),
                'closed_by' => $input['actor_id'],
            ]);

            return ['cash_register' => $register->refresh()->load('transactions')];
        });
    }
}
