<?php

namespace App\Application\Commissions\Actions;

use App\Application\Shared\Actions\Action;
use App\Application\Shared\Tenancy\TenantContext;
use App\Domain\Attendance\Enums\AttendanceStatus;
use App\Models\AttendanceItem;
use App\Models\Commission;
use App\Models\CommissionPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CloseCommissionPeriod extends Action
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    /**
     * @param  array{period_start: string, period_end: string, actor_id: int|null}  $input
     * @return array{period: CommissionPeriod, commissions: int}
     */
    protected function handle(array $input): array
    {
        $tenantId = $this->tenantContext->id();
        abort_if($tenantId === null, 403);

        return DB::transaction(function () use ($input, $tenantId): array {
            DB::selectOne('SELECT pg_advisory_xact_lock(hashtext(?))', ['commission-period:'.$tenantId]);

            $periodStart = CarbonImmutable::parse($input['period_start'])->toDateString();
            $periodEnd = CarbonImmutable::parse($input['period_end'])->toDateString();

            $overlappingPeriod = CommissionPeriod::query()
                ->whereDate('period_start', '<=', $periodEnd)
                ->whereDate('period_end', '>=', $periodStart)
                ->exists();

            if ($overlappingPeriod) {
                throw ValidationException::withMessages([
                    'period_start' => 'O período informado se sobrepõe a um período já fechado.',
                ]);
            }

            $period = CommissionPeriod::query()->create([
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'closed_by' => $input['actor_id'],
                'closed_at' => now(),
            ]);

            $items = AttendanceItem::query()
                ->with(['attendance.appointment.barber', 'service'])
                ->whereHas('attendance', function ($query) use ($periodStart, $periodEnd): void {
                    $query->where('status', AttendanceStatus::Closed)
                        ->whereBetween('closed_at', [
                            CarbonImmutable::parse($periodStart)->startOfDay(),
                            CarbonImmutable::parse($periodEnd)->endOfDay(),
                        ]);
                })
                ->orderBy('id')
                ->get();

            foreach ($items as $item) {
                $barber = $item->attendance->appointment->barber;

                Commission::query()->create([
                    'period_id' => $period->getKey(),
                    'attendance_item_id' => $item->getKey(),
                    'barber_id' => $barber->getKey(),
                    'service_id' => $item->service_id,
                    'barber_name_snapshot' => $barber->name,
                    'service_name_snapshot' => $item->service->name,
                    'calculation_type_snapshot' => $item->commission_calculation_type_snapshot,
                    'percentage_snapshot' => $item->commission_percentage_snapshot,
                    'fixed_amount_snapshot' => $item->commission_fixed_amount_snapshot,
                    'amount' => $item->commission_amount_snapshot,
                ]);
            }

            return ['period' => $period->loadCount('commissions'), 'commissions' => $items->count()];
        });
    }
}
