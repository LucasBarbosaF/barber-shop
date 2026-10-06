<?php

namespace App\Models;

use App\Domain\Commissions\Enums\CommissionCalculationType;
use App\Infrastructure\Shared\Persistence\TenantScopedModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $period_id
 * @property int $attendance_item_id
 * @property int $barber_id
 * @property int $service_id
 * @property int|null $commission_payment_id
 * @property string $barber_name_snapshot
 * @property string $service_name_snapshot
 * @property CommissionCalculationType $calculation_type_snapshot
 * @property string|null $percentage_snapshot
 * @property string|null $fixed_amount_snapshot
 * @property string $amount
 */
class Commission extends TenantScopedModel
{
    protected $table = 'commissions';

    protected $fillable = [
        'period_id',
        'attendance_item_id',
        'barber_id',
        'service_id',
        'commission_payment_id',
        'barber_name_snapshot',
        'service_name_snapshot',
        'calculation_type_snapshot',
        'percentage_snapshot',
        'fixed_amount_snapshot',
        'amount',
    ];

    protected function casts(): array
    {
        return [
            'calculation_type_snapshot' => CommissionCalculationType::class,
            'percentage_snapshot' => 'decimal:2',
            'fixed_amount_snapshot' => 'decimal:2',
            'amount' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<CommissionPeriod, $this> */
    public function period(): BelongsTo
    {
        return $this->belongsTo(CommissionPeriod::class, 'period_id');
    }

    /** @return BelongsTo<Barber, $this> */
    public function barber(): BelongsTo
    {
        return $this->belongsTo(Barber::class);
    }

    /** @return BelongsTo<Service, $this> */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /** @return BelongsTo<AttendanceItem, $this> */
    public function attendanceItem(): BelongsTo
    {
        return $this->belongsTo(AttendanceItem::class);
    }

    /** @return BelongsTo<CommissionPayment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(CommissionPayment::class, 'commission_payment_id');
    }
}
