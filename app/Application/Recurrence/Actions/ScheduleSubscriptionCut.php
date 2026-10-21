<?php

namespace App\Application\Recurrence\Actions;

use App\Application\Agenda\Actions\ListAvailableSlots;
use App\Application\Agenda\Actions\NotifyBarberOfAppointment;
use App\Application\Recurrence\RecurrenceQuota;
use App\Application\Shared\Actions\Action;
use App\Domain\Agenda\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Barber;
use App\Models\RecurrenceSubscription;
use App\Models\Service;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Agenda um corte de uma assinatura de recorrência.
 *
 * A ordem importa: o horário é revalidado contra a agenda (mesmo caminho do
 * agendamento público, com o barbeiro travado) e a cota é conferida dentro da
 * mesma transação — a UI pode esconder a semana cheia, o banco não mente.
 */
final class ScheduleSubscriptionCut extends Action
{
    public function __construct(
        private readonly ListAvailableSlots $listAvailableSlots,
        private readonly RecurrenceQuota $quota,
        private readonly NotifyBarberOfAppointment $notifyBarber,
    ) {}

    /**
     * @param  array{
     *     subscription: RecurrenceSubscription,
     *     barber_id: int,
     *     date: string,
     *     time: string,
     *     actor_id?: int|null,
     *     allow_unpaid?: bool,
     * }  $input
     * @return array{appointment: Appointment}
     */
    protected function handle(array $input): array
    {
        return DB::transaction(function () use ($input): array {
            $subscription = RecurrenceSubscription::query()
                ->whereKey($input['subscription']->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $barber = Barber::query()
                ->whereKey($input['barber_id'])
                ->where('is_active', true)
                ->whereNotNull('user_id')
                ->lockForUpdate()
                ->firstOrFail();

            $service = Service::query()
                ->whereKey($subscription->service_id)
                ->where('is_active', true)
                ->firstOrFail();

            if (! $barber->services()->whereKey($service->getKey())->exists()) {
                throw ValidationException::withMessages([
                    'barber_id' => 'Este barbeiro não realiza o serviço do seu plano.',
                ]);
            }

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

            try {
                $startsAt = CarbonImmutable::createFromFormat(
                    '!Y-m-d H:i',
                    $input['date'].' '.$input['time'],
                    config('app.timezone'),
                );
            } catch (InvalidFormatException) {
                throw ValidationException::withMessages(['time' => 'Selecione um horário válido.']);
            }

            $this->quota->assertCanSchedule(
                $subscription,
                $startsAt,
                (bool) ($input['allow_unpaid'] ?? false),
            );

            $appointment = Appointment::query()->create([
                'customer_id' => $subscription->customer_id,
                'barber_id' => $barber->getKey(),
                'service_id' => $service->getKey(),
                'recurrence_subscription_id' => $subscription->getKey(),
                'starts_at' => $startsAt,
                'ends_at' => $startsAt->addMinutes($service->duration_minutes),
                'status' => AppointmentStatus::Confirmed,
            ]);

            $this->notifyBarber->execute([
                'appointment' => $appointment,
                'barber' => $barber,
            ]);

            return ['appointment' => $appointment->load(['barber', 'service', 'customer'])];
        });
    }
}
