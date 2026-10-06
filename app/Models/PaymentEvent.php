<?php

namespace App\Models;

use App\Domain\Payments\Enums\PaymentEventType;
use App\Infrastructure\Shared\Persistence\TenantScopedModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $payment_id
 * @property int|null $refund_id
 * @property PaymentEventType $type
 * @property string $amount
 * @property int|null $actor_id
 * @property string $idempotency_key
 */
class PaymentEvent extends TenantScopedModel
{
    protected $table = 'payment_events';

    public $timestamps = false;

    protected $fillable = ['payment_id', 'refund_id', 'type', 'amount', 'actor_id', 'idempotency_key'];

    protected function casts(): array
    {
        return [
            'type' => PaymentEventType::class,
            'amount' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /** @return BelongsTo<PaymentRefund, $this> */
    public function refund(): BelongsTo
    {
        return $this->belongsTo(PaymentRefund::class);
    }
}
