<?php

namespace App\Models;

use App\Infrastructure\Shared\Persistence\TenantScopedModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $payment_id
 * @property string $amount
 * @property string $reason
 * @property string $idempotency_key
 * @property int|null $refunded_by
 * @property Carbon $refunded_at
 */
class PaymentRefund extends TenantScopedModel
{
    protected $table = 'payment_refunds';

    protected $fillable = [
        'payment_id',
        'amount',
        'reason',
        'idempotency_key',
        'refunded_by',
        'refunded_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'refunded_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
