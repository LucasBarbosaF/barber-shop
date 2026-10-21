<?php

namespace App\Application\Agenda\Actions;

use App\Application\Shared\Actions\Action;
use App\Models\Appointment;
use App\Models\Barber;
use Illuminate\Support\Facades\DB;

/**
 * Avisa o barbeiro de um agendamento novo.
 *
 * Centralizado porque a regra tinha duas casas — o agendamento público e o corte
 * de recorrência —, e duplicar o insert em `appointment_notifications` criaria
 * dois lugares decidindo quem é notificado (e esqueceria um deles na primeira
 * mudança).
 */
final class NotifyBarberOfAppointment extends Action
{
    /**
     * @param  array{appointment: Appointment, barber: Barber}  $input
     * @return array<string, mixed>
     */
    protected function handle(array $input): array
    {
        if ($input['barber']->user_id === null) {
            return [];
        }

        $now = now();
        DB::table('appointment_notifications')->insert([
            'tenant_id' => $input['barber']->tenant_id,
            'user_id' => $input['barber']->user_id,
            'appointment_id' => $input['appointment']->getKey(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [];
    }
}
