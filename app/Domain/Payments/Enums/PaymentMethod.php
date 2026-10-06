<?php

namespace App\Domain\Payments\Enums;

enum PaymentMethod: string
{
    case Cash = 'cash';
    case Pix = 'pix';
    case Card = 'card';
}
