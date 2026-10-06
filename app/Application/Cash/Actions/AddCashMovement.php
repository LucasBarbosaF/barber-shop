<?php

namespace App\Application\Cash\Actions;

use App\Application\Cash\CashRegisterBalance;
use App\Application\Payments\PaymentBalance;
use App\Application\Shared\Actions\Action;
use App\Domain\Cash\Enums\CashRegisterStatus;
use App\Domain\Cash\Enums\CashTransactionType;
use App\Models\CashRegister;
use App\Models\CashTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AddCashMovement extends Action
{
    /**
     * @param  array{cash_register: CashRegister, type: CashTransactionType, amount: string, description: string, actor_id: int|null}  $input
     * @return array{cash_transaction: CashTransaction}
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
                    'cash_register' => 'Não é possível movimentar um caixa fechado.',
                ]);
            }

            $amount = PaymentBalance::toMinorUnits($input['amount']);
            if ($input['type'] === CashTransactionType::CashOut
                && CashRegisterBalance::expectedMinorUnits($register) < $amount) {
                throw ValidationException::withMessages([
                    'amount' => 'O caixa não possui saldo suficiente para esta retirada.',
                ]);
            }

            return ['cash_transaction' => $register->transactions()->create([
                'type' => $input['type'],
                'amount' => PaymentBalance::fromMinorUnits($amount),
                'description' => $input['description'],
                'created_by' => $input['actor_id'],
            ])];
        });
    }
}
