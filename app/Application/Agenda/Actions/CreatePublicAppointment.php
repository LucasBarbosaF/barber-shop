<?php

namespace App\Application\Agenda\Actions;

use App\Application\Shared\Actions\Action;
use App\Domain\Agenda\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Barber;
use App\Models\Customer;
use App\Models\Service;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CreatePublicAppointment extends Action
{
    public function __construct(private readonly ListAvailableSlots $listAvailableSlots) {}

    /**
     * @param  array{barber_id: int, service_id: int, date: string, time: string, customer_name: string, customer_phone: string}  $input
     * @return array{appointment: Appointment}
     */
    protected function handle(array $input): array
    {
        return DB::transaction(function () use ($input): array {
            $barber = Barber::query()
                ->whereKey($input['barber_id'])
                ->where('is_active', true)
                ->whereNotNull('user_id')
                ->lockForUpdate()
                ->firstOrFail();
            $service = Service::query()
                ->whereKey($input['service_id'])
                ->where('is_active', true)
                ->firstOrFail();

            $slots = $this->listAvailableSlots->execute([
                'barber_id' => (int) $barber->getKey(),
                'service_id' => (int) $service->getKey(),
                'date' => $input['date'],
            ])['slots'];

            if (! in_array($input['time'], $slots, true)) {
                throw ValidationException::withMessages([
                    'time' => 'Este horário acabou de ficar indisponível. Escolha outro horário.',
                ]);
            }

            $startsAt = CarbonImmutable::createFromFormat(
                '!Y-m-d H:i',
                $input['date'].' '.$input['time'],
                config('app.timezone'),
            );

            if (! $startsAt) {
                throw ValidationException::withMessages(['time' => 'Selecione um horário válido.']);
            }

            $phone = preg_replace('/\D+/', '', $input['customer_phone']);

            if ($phone === null || ! in_array(strlen($phone), [10, 11], true)) {
                throw ValidationException::withMessages(['customer_phone' => 'Informe um telefone com DDD válido.']);
            }

            $customer = Customer::query()->firstOrCreate(
                ['phone' => $phone],
                ['name' => trim($input['customer_name'])],
            );

            $appointment = Appointment::query()->create([
                'customer_id' => $customer->getKey(),
                'barber_id' => $barber->getKey(),
                'service_id' => $service->getKey(),
                'starts_at' => $startsAt,
                'ends_at' => $startsAt->addMinutes($service->duration_minutes),
                'status' => AppointmentStatus::Confirmed,
            ]);

            if ($barber->user_id !== null) {
                $now = now();
                DB::table('appointment_notifications')->insert([
                    'tenant_id' => $barber->tenant_id,
                    'user_id' => $barber->user_id,
                    'appointment_id' => $appointment->getKey(),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            return ['appointment' => $appointment->load(['barber', 'service', 'customer'])];
        });
    }
}
