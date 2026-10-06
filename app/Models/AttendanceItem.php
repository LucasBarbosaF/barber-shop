<?php

namespace App\Models;

use App\Domain\Commissions\Enums\CommissionCalculationType;
use App\Infrastructure\Shared\Persistence\TenantScopedModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $attendance_id
 * @property int $service_id
 * @property int $quantity
 * @property string $unit_price_snapshot
 * @property CommissionCalculationType $commission_calculation_type_snapshot
 * @property string|null $commission_percentage_snapshot
 * @property string|null $commission_fixed_amount_snapshot
 * @property string $commission_amount_snapshot
 */
class AttendanceItem extends TenantScopedModel
{
    protected $table = 'attendance_items';

    protected $fillable = [
        'attendance_id',
        'service_id',
        'quantity',
        'unit_price_snapshot',
        'commission_calculation_type_snapshot',
        'commission_percentage_snapshot',
        'commission_fixed_amount_snapshot',
        'commission_amount_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price_snapshot' => 'decimal:2',
            'commission_calculation_type_snapshot' => CommissionCalculationType::class,
            'commission_percentage_snapshot' => 'decimal:2',
            'commission_fixed_amount_snapshot' => 'decimal:2',
            'commission_amount_snapshot' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<Attendance, $this>
     */
    public function attendance(): BelongsTo
    {
        return $this->belongsTo(Attendance::class);
    }

    /**
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }
}
