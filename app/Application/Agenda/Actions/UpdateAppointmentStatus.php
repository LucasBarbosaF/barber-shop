<?php

namespace App\Application\Agenda\Actions;

use App\Application\Shared\Actions\Action;
use App\Domain\Agenda\Enums\AppointmentStatus;
use App\Models\Appointment;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class UpdateAppointmentStatus extends Action
{
    /**
     * Muda o status de um agendamento dentro da matriz de transições do
     * enum. `in_service` e `completed` não são alvos possíveis: eles nascem
     * do fluxo de atendimento (ficha aberta/fechada), nunca da agenda.
     *
     * @param  array{appointment: Appointment, status: AppointmentStatus}  $input
     * @return array{appointment: Appointment}
     */
    protected function handle(array $input): array
    {
        return DB::transaction(function () use ($input): array {
            $appointment = Appointment::query()
                ->whereKey($input['appointment']->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $target = $input['status'];
            $allowed = $appointment->status->targets();

            if (! in_array($target, $allowed, true)) {
                throw ValidationException::withMessages([
                    'status' => $allowed === []
                        ? 'Este agendamento está em um status que só muda pela tela de atendimento.'
                        : sprintf(
                            'Não é possível mudar de "%s" para "%s".',
                            $appointment->status->label(),
                            $target->label(),
                        ),
                ]);
            }

            $appointment->update(['status' => $target]);

            return ['appointment' => $appointment];
        });
    }
}
