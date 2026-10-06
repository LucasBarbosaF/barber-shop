<?php

namespace App\Domain\Attendance\Enums;

enum AttendanceStatus: string
{
    case Open = 'open';
    case Closed = 'closed';
}
