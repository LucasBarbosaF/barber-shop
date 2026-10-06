<?php

namespace App\Models;

use App\Domain\Payments\Enums\PaymentMethod;
use App\Infrastructure\Shared\Persistence\TenantScopedModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $attendance_id
 * @property string $amount
 * @property PaymentMethod $method
 * @property string $idempotency_key
 * @property int|null $created_by
 */
class Payment extends TenantScopedModel
{
    protected $table = 'payments';

    protected $fillable = ['attendance_id', 'amount', 'method', 'idempotency_key', 'created_by'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'method' => PaymentMethod::class,
        ];
    }

    /** @return BelongsTo<Attendance, $this> */
    public function attendance(): BelongsTo
    {
        return $this->belongsTo(Attendance::class);
    }

    /** @return HasMany<PaymentRefund, $this> */
    public function refunds(): HasMany
    {
        return $this->hasMany(PaymentRefund::class);
    }

    /** @return HasMany<PaymentEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(PaymentEvent::class);
    }
}
