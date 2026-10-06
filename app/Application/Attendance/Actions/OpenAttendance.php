<?php

namespace App\Application\Attendance\Actions;

use App\Application\Shared\Actions\Action;
use App\Domain\Agenda\Enums\AppointmentStatus;
use App\Domain\Attendance\Enums\AttendanceStatus;
use App\Models\Appointment;
use App\Models\Attendance;
use App\Models\Barber;
use App\Models\Service;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class OpenAttendance extends Action
{
    /**
     * @param  array{appointment: Appointment}  $input
     * @return array{attendance: Attendance}
     */
    protected function handle(array $input): array
    {
        return DB::transaction(function () use ($input): array {
            $appointment = Appointment::query()
                ->whereKey($input['appointment']->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (Attendance::query()->where('appointment_id', $appointment->getKey())->exists()) {
                throw ValidationException::withMessages([
                    'appointment' => 'Este agendamento já possui um atendimento.',
                ]);
            }

            if ($appointment->status !== AppointmentStatus::Confirmed
                && $appointment->status !== AppointmentStatus::Arrived) {
                throw ValidationException::withMessages([
                    'appointment' => 'Somente agendamentos confirmados ou com cliente presente podem iniciar atendimento.',
                ]);
            }

            $barber = Barber::query()
                ->whereKey($appointment->barber_id)
                ->lockForUpdate()
                ->firstOrFail();
            $service = Service::query()
                ->whereKey($appointment->service_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($barber->commission_percentage === null) {
                throw ValidationException::withMessages([
                    'commission_percentage' => 'Configure a comissão do barbeiro antes de iniciar o atendimento.',
                ]);
            }

            $attendance = Attendance::query()->create([
                'appointment_id' => $appointment->getKey(),
                'status' => AttendanceStatus::Open,
                'opened_at' => now(),
            ]);

            app(AddAttendanceItem::class)->execute([
                'attendance' => $attendance,
                'service' => $service,
                'barber' => $barber,
                'quantity' => 1,
            ]);

            $appointment->update(['status' => AppointmentStatus::InService]);

            return ['attendance' => $attendance->load('items.service', 'appointment.customer', 'appointment.barber')];
        });
    }
}
