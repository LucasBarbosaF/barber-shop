<?php

namespace App\Models;

use App\Infrastructure\Shared\Persistence\TenantScopedModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $period_id
 * @property int $barber_id
 * @property string $amount
 * @property string $idempotency_key
 * @property int|null $paid_by
 * @property Carbon $paid_at
 */
class CommissionPayment extends TenantScopedModel
{
    protected $table = 'commission_payments';

    protected $fillable = ['period_id', 'barber_id', 'amount', 'idempotency_key', 'paid_by', 'paid_at'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'paid_at' => 'immutable_datetime',
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

    /** @return HasMany<Commission, $this> */
    public function commissions(): HasMany
    {
        return $this->hasMany(Commission::class, 'commission_payment_id');
    }
}
