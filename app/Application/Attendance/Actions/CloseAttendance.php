<?php

namespace App\Application\Attendance\Actions;

use App\Application\Shared\Actions\Action;
use App\Domain\Agenda\Enums\AppointmentStatus;
use App\Domain\Attendance\Enums\AttendanceStatus;
use App\Models\Attendance;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CloseAttendance extends Action
{
    /**
     * @param  array{attendance: Attendance}  $input
     * @return array{attendance: Attendance}
     */
    protected function handle(array $input): array
    {
        return DB::transaction(function () use ($input): array {
            $attendance = Attendance::query()
                ->whereKey($input['attendance']->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($attendance->status !== AttendanceStatus::Open) {
                throw ValidationException::withMessages([
                    'attendance' => 'Este atendimento já foi fechado.',
                ]);
            }

            if (! $attendance->items()->exists()) {
                throw ValidationException::withMessages([
                    'attendance' => 'Adicione ao menos um serviço antes de fechar o atendimento.',
                ]);
            }

            $attendance->update([
                'status' => AttendanceStatus::Closed,
                'closed_at' => now(),
            ]);
            $attendance->appointment()->update(['status' => AppointmentStatus::Completed]);

            return ['attendance' => $attendance->refresh()];
        });
    }
}
