<?php

namespace App\Domain\Cash\Enums;

enum CashTransactionType: string
{
    case CashIn = 'cash_in';
    case CashOut = 'cash_out';
    case Payment = 'payment';
    case Refund = 'refund';
}
