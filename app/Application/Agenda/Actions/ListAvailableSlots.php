<?php

namespace App\Application\Agenda\Actions;

use App\Application\Shared\Actions\Action;
use App\Domain\Agenda\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Barber;
use App\Models\BlockedPeriod;
use App\Models\BusinessHour;
use App\Models\Service;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

final class ListAvailableSlots extends Action
{
    /**
     * @param  array{barber_id: int, service_id: int, date: string}  $input
     * @return array{slots: array<int, string>}
     */
    protected function handle(array $input): array
    {
        $date = CarbonImmutable::createFromFormat('!Y-m-d', $input['date'], config('app.timezone'));

        if (! $date || $date->format('Y-m-d') !== $input['date']) {
            throw ValidationException::withMessages(['date' => 'Selecione uma data válida.']);
        }

        $barber = Barber::query()
            ->whereKey($input['barber_id'])
            ->where('is_active', true)
            ->whereNotNull('user_id')
            ->firstOrFail();
        $service = Service::query()
            ->whereKey($input['service_id'])
            ->where('is_active', true)
            ->firstOrFail();
        if (! $barber->services()->whereKey($service->getKey())->exists()) {
            throw ValidationException::withMessages([
                'service_id' => 'Este barbeiro não realiza o serviço selecionado.',
            ]);
        }

        $businessHours = BusinessHour::query()
            ->where('barber_id', $barber->getKey())
            ->where('weekday', $date->dayOfWeek)
            ->first();

        if ($businessHours === null) {
            return ['slots' => []];
        }

        $opensAt = $date->setTimeFromTimeString($businessHours->opens_at);
        $closesAt = $date->setTimeFromTimeString($businessHours->closes_at);
        $duration = $service->duration_minutes;
        $appointmentPeriods = Appointment::query()
            ->where('barber_id', $barber->getKey())
            ->whereIn('status', AppointmentStatus::blockingValues())
            ->where('starts_at', '<', $closesAt)
            ->where('ends_at', '>', $opensAt)
            ->get(['starts_at', 'ends_at'])
            ->map(fn (Appointment $appointment): array => [
                $appointment->starts_at->getTimestamp(),
                $appointment->ends_at->getTimestamp(),
            ]);
        $blockedPeriods = BlockedPeriod::query()
            ->where('barber_id', $barber->getKey())
            ->where('starts_at', '<', $closesAt)
            ->where('ends_at', '>', $opensAt)
            ->get(['starts_at', 'ends_at'])
            ->map(fn (BlockedPeriod $period): array => [
                $period->starts_at->getTimestamp(),
                $period->ends_at->getTimestamp(),
            ]);

        $now = CarbonImmutable::now(config('app.timezone'));
        $firstMinute = (int) ceil($opensAt->minute / 15) * 15;
        $slot = $opensAt->setMinute(0)->addMinutes($firstMinute);
        $slots = [];

        while ($slot->addMinutes($duration)->lessThanOrEqualTo($closesAt)) {
            $slotEnd = $slot->addMinutes($duration);
            $hasAppointment = $appointmentPeriods->contains(
                fn (array $period): bool => $period[0] < $slotEnd->getTimestamp()
                    && $period[1] > $slot->getTimestamp(),
            );
            $isBlocked = $blockedPeriods->contains(
                fn (array $period): bool => $period[0] < $slotEnd->getTimestamp()
                    && $period[1] > $slot->getTimestamp(),
            );

            if ($slot->greaterThan($now) && ! $hasAppointment && ! $isBlocked) {
                $slots[] = $slot->format('H:i');
            }

            $slot = $slot->addMinutes(15);
        }

        return ['slots' => $slots];
    }
}
