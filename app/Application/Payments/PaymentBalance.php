<?php

namespace App\Application\Payments;

use App\Models\Attendance;
use App\Models\Payment;

final class PaymentBalance
{
    public static function totalDue(Attendance $attendance): int
    {
        return $attendance->items()
            ->get(['unit_price_snapshot', 'quantity'])
            ->sum(fn ($item): int => self::toMinorUnits($item->unit_price_snapshot) * $item->quantity);
    }

    public static function totalPaid(Attendance $attendance): int
    {
        return $attendance->payments()
            ->with('refunds:id,tenant_id,payment_id,amount')
            ->get()
            ->sum(fn (Payment $payment): int => self::toMinorUnits($payment->amount)
                - $payment->refunds->sum(fn ($refund): int => self::toMinorUnits($refund->amount)));
    }

    public static function toMinorUnits(string|int $amount): int
    {
        $normalized = (string) $amount;
        [$whole, $fraction] = array_pad(explode('.', $normalized, 2), 2, '00');

        return ((int) $whole * 100) + (int) str_pad(substr($fraction, 0, 2), 2, '0');
    }

    public static function fromMinorUnits(int $amount): string
    {
        $sign = $amount < 0 ? '-' : '';
        $absolute = abs($amount);

        return $sign.intdiv($absolute, 100).'.'.str_pad((string) ($absolute % 100), 2, '0', STR_PAD_LEFT);
    }
}
