<?php

namespace App\Domain\Payments\Enums;

enum PaymentEventType: string
{
    case PaymentReceived = 'payment_received';
    case PaymentRefunded = 'payment_refunded';
}
