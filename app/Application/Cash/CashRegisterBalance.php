<?php

namespace App\Application\Cash;

use App\Application\Payments\PaymentBalance;
use App\Domain\Cash\Enums\CashTransactionType;
use App\Models\CashRegister;
use App\Models\CashTransaction;

final class CashRegisterBalance
{
    public static function expectedMinorUnits(CashRegister $register): int
    {
        return PaymentBalance::toMinorUnits($register->opening_amount)
            + $register->transactions()
                ->get(['type', 'amount'])
                ->sum(fn (CashTransaction $transaction): int => in_array(
                    $transaction->type,
                    [CashTransactionType::CashIn, CashTransactionType::Payment],
                    true,
                )
                    ? PaymentBalance::toMinorUnits($transaction->amount)
                    : -PaymentBalance::toMinorUnits($transaction->amount));
    }
}
