<?php

namespace App\Domain\Cash\Enums;

enum CashRegisterStatus: string
{
    case Open = 'open';
    case Closed = 'closed';
}
