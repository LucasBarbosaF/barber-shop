<?php

namespace App\Application\Attendance\Actions;

use App\Application\Shared\Actions\Action;
use App\Domain\Attendance\Enums\AttendanceStatus;
use App\Domain\Commissions\Enums\CommissionCalculationType;
use App\Models\Attendance;
use App\Models\AttendanceItem;
use App\Models\Barber;
use App\Models\CommissionRule;
use App\Models\Service;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AddAttendanceItem extends Action
{
    /**
     * @param  array{attendance: Attendance, service: Service, barber?: Barber, quantity: int}  $input
     * @return array{item: AttendanceItem}
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
                    'attendance' => 'Não é possível alterar um atendimento fechado.',
                ]);
            }

            $appointment = $attendance->appointment;
            $barber = $input['barber'] ?? Barber::query()
                ->whereKey($appointment->barber_id)
                ->lockForUpdate()
                ->firstOrFail();
            $service = Service::query()
                ->whereKey($input['service']->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $serviceRule = CommissionRule::query()
                ->where('barber_id', $barber->getKey())
                ->where('service_id', $service->getKey())
                ->lockForUpdate()
                ->first();
            $defaultRule = $serviceRule === null
                ? CommissionRule::query()
                    ->where('barber_id', $barber->getKey())
                    ->whereNull('service_id')
                    ->lockForUpdate()
                    ->first()
                : null;
            $rule = $serviceRule ?? $defaultRule;
            $calculationType = $rule->calculation_type ?? CommissionCalculationType::Percentage;
            $commissionPercentage = $rule->percentage ?? $barber->commission_percentage;
            $fixedAmount = $rule?->fixed_amount;

            $priceCents = self::toMinorUnits($service->price);
            $quantity = $input['quantity'];
            if ($calculationType === CommissionCalculationType::Percentage) {
                if ($commissionPercentage === null) {
                    throw ValidationException::withMessages([
                        'commission_percentage' => 'Configure uma regra de comissão para o barbeiro antes de adicionar serviços.',
                    ]);
                }

                $commissionCents = intdiv(
                    ($priceCents * $quantity * self::percentageToBasisPoints($commissionPercentage)) + 5000,
                    10000,
                );
            } else {
                if ($fixedAmount === null) {
                    throw ValidationException::withMessages([
                        'commission_percentage' => 'Configure uma regra de comissão para o barbeiro antes de adicionar serviços.',
                    ]);
                }

                $commissionCents = self::toMinorUnits($fixedAmount) * $quantity;
            }

            $item = AttendanceItem::query()->create([
                'attendance_id' => $attendance->getKey(),
                'service_id' => $service->getKey(),
                'quantity' => $quantity,
                'unit_price_snapshot' => $service->price,
                'commission_calculation_type_snapshot' => $calculationType,
                'commission_percentage_snapshot' => $calculationType === CommissionCalculationType::Percentage
                    ? $commissionPercentage
                    : null,
                'commission_fixed_amount_snapshot' => $calculationType === CommissionCalculationType::FixedAmount
                    ? $fixedAmount
                    : null,
                'commission_amount_snapshot' => self::fromMinorUnits($commissionCents),
            ]);

            return ['item' => $item];
        });
    }

    private static function toMinorUnits(string $amount): int
    {
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '00');

        return ((int) $whole * 100) + (int) str_pad(substr($fraction, 0, 2), 2, '0');
    }

    private static function percentageToBasisPoints(string $percentage): int
    {
        [$whole, $fraction] = array_pad(explode('.', $percentage, 2), 2, '00');

        return ((int) $whole * 100) + (int) str_pad(substr($fraction, 0, 2), 2, '0');
    }

    private static function fromMinorUnits(int $amount): string
    {
        return intdiv($amount, 100).'.'.str_pad((string) ($amount % 100), 2, '0', STR_PAD_LEFT);
    }
}
