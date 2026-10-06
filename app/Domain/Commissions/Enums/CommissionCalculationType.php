<?php

namespace App\Domain\Commissions\Enums;

enum CommissionCalculationType: string
{
    case Percentage = 'percentage';
    case FixedAmount = 'fixed_amount';
}
