<?php

namespace App\Models;

use App\Domain\Cash\Enums\CashTransactionType;
use App\Infrastructure\Shared\Persistence\TenantScopedModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $cash_register_id
 * @property int|null $payment_id
 * @property int|null $refund_id
 * @property CashTransactionType $type
 * @property string $amount
 * @property string $description
 * @property int|null $created_by
 */
class CashTransaction extends TenantScopedModel
{
    protected $table = 'cash_transactions';

    protected $fillable = [
        'cash_register_id',
        'payment_id',
        'refund_id',
        'type',
        'amount',
        'description',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'type' => CashTransactionType::class,
            'amount' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<CashRegister, $this> */
    public function cashRegister(): BelongsTo
    {
        return $this->belongsTo(CashRegister::class);
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
