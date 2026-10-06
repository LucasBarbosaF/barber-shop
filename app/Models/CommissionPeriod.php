<?php

namespace App\Models;

use App\Domain\Commissions\Enums\CommissionPeriodStatus;
use App\Infrastructure\Shared\Persistence\TenantScopedModel;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $tenant_id
 * @property string $period_start
 * @property string $period_end
 * @property CommissionPeriodStatus $status
 * @property int|null $closed_by
 * @property Carbon $closed_at
 */
class CommissionPeriod extends TenantScopedModel
{
    protected $table = 'commission_periods';

    protected $fillable = ['period_start', 'period_end', 'status', 'closed_by', 'closed_at'];

    protected function casts(): array
    {
        return [
            'period_start' => 'immutable_date',
            'period_end' => 'immutable_date',
            'status' => CommissionPeriodStatus::class,
            'closed_at' => 'immutable_datetime',
        ];
    }

    /** @return HasMany<Commission, $this> */
    public function commissions(): HasMany
    {
        return $this->hasMany(Commission::class, 'period_id');
    }

    /** @return HasMany<CommissionPayment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(CommissionPayment::class, 'period_id');
    }
}
