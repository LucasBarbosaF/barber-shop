<?php

namespace App\Domain\Commissions\Enums;

enum CommissionPeriodStatus: string
{
    case Closed = 'closed';
    case Paid = 'paid';
}
