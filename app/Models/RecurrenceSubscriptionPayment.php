<?php

namespace App\Models;

use App\Domain\Recurrence\Enums\PaymentStatus;
use App\Infrastructure\Shared\Persistence\TenantScopedModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Mensalidade de uma assinatura de recorrência: um registro por mês de
 * contrato (dia 1), com status `paid`/`pending` — é o que libera (ou não)
 * o agendamento daquele mês.
 *
 * `gateway` + `gateway_payment_id` guardam a referência da cobrança
 * recorrente futura (Asaas); no fluxo manual ficam nulos.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $recurrence_subscription_id
 * @property Carbon $period_month
 * @property string $amount
 * @property PaymentStatus $status
 * @property string|null $method
 * @property string|null $obs
 * @property Carbon|null $paid_at
 * @property Carbon|null $cash_posted_at
 * @property string|null $gateway
 * @property string|null $gateway_payment_id
 */
class RecurrenceSubscriptionPayment extends TenantScopedModel
{
    protected $table = 'recurrence_subscription_payments';

    protected $fillable = [
        'recurrence_subscription_id',
        'period_month',
        'amount',
        'status',
        'method',
        'obs',
        'paid_at',
        'cash_posted_at',
        'gateway',
        'gateway_payment_id',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'period_month' => 'date',
            'amount' => 'decimal:2',
            'status' => PaymentStatus::class,
            'paid_at' => 'datetime',
            'cash_posted_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<RecurrenceSubscription, $this>
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(RecurrenceSubscription::class, 'recurrence_subscription_id');
    }
}
