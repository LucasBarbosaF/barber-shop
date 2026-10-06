<?php

namespace App\Domain\Agenda\Enums;

enum AppointmentStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Arrived = 'arrived';
    case InService = 'in_service';
    case Completed = 'completed';
    case Canceled = 'canceled';
    case NoShow = 'no_show';

    /**
     * @return array<int, string>
     */
    public static function blockingValues(): array
    {
        return [
            self::Pending->value,
            self::Confirmed->value,
            self::Arrived->value,
            self::InService->value,
        ];
    }
}
