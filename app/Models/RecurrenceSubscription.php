<?php

namespace App\Models;

use App\Domain\Recurrence\Enums\PaymentStatus;
use App\Domain\Recurrence\Enums\SubscriptionStatus;
use App\Infrastructure\Shared\Persistence\TenantScopedModel;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * @property int $id
 * @property int $tenant_id
 * @property string $public_id
 * @property int $customer_id
 * @property int $plan_id
 * @property int|null $barber_id
 * @property int $service_id
 * @property string $plan_name
 * @property string $price
 * @property int $duration_months
 * @property int $sessions_per_month
 * @property int $max_sessions_per_week
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 * @property SubscriptionStatus $status
 * @property string|null $paid_amount
 * @property Carbon|null $paid_at
 * @property string|null $paid_method
 * @property int $total_sessions
 */
class RecurrenceSubscription extends TenantScopedModel
{
    protected $table = 'recurrence_subscriptions';

    protected $fillable = [
        'public_id',
        'customer_id',
        'plan_id',
        'barber_id',
        'service_id',
        'plan_name',
        'price',
        'duration_months',
        'sessions_per_month',
        'max_sessions_per_week',
        'starts_at',
        'ends_at',
        'status',
        'paid_amount',
        'paid_at',
        'paid_method',
        'paid_obs',
        'created_by',
        'activated_by',
        'cash_posted_at',
        'canceled_at',
        'cancelation_reason',
        'cancel_future_appointments',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'date',
            'ends_at' => 'date',
            'status' => SubscriptionStatus::class,
            'price' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'paid_at' => 'datetime',
            'cash_posted_at' => 'datetime',
            'canceled_at' => 'datetime',
            'cancel_future_appointments' => 'boolean',
            'duration_months' => 'integer',
            'sessions_per_month' => 'integer',
            'max_sessions_per_week' => 'integer',
        ];
    }

    /**
     * Cortes totais do contrato, pelo snapshot — nunca pelo plano atual, que
     * pode já ter sido reprecificado.
     */
    protected function totalSessions(): Attribute
    {
        return Attribute::get(fn (): int => $this->duration_months * $this->sessions_per_month);
    }

    public function isSchedulable(): bool
    {
        return in_array($this->status, $this->status->schedulable(), true);
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<RecurrencePlan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(RecurrencePlan::class);
    }

    /**
     * @return BelongsTo<Barber, $this>
     */
    public function barber(): BelongsTo
    {
        return $this->belongsTo(Barber::class);
    }

    /**
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /**
     * @return HasMany<Appointment, $this>
     */
    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    /**
     * @return HasMany<RecurrenceSubscriptionPayment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(RecurrenceSubscriptionPayment::class);
    }

    /**
     * Mensalidades do contrato, na ordem: exatamente `duration_months` meses
     * calendário a partir do mês inicial (R$ 120 × 6 = 6 mensalidades, do mês
     * do início ao mês `duration_months - 1`). Cada entrada traz o mês e a
     * mensalidade registrada — `null` quando ninguém registrou o pagamento.
     *
     * @return Collection<int, array{month: \Illuminate\Support\Carbon, payment: RecurrenceSubscriptionPayment|null}>
     */
    public function contractMonths(): Collection
    {
        $first = $this->starts_at->toImmutable()->startOfMonth();
        $payments = $this->payments()
            ->get()
            ->keyBy(fn (RecurrenceSubscriptionPayment $payment): string => $payment->period_month->format('Y-m'));

        return Collection::make(range(0, max(0, $this->duration_months - 1)))
            ->map(function (int $index) use ($first, $payments): array {
                $month = $first->addMonths($index);

                return [
                    'month' => $month,
                    'payment' => $payments->get($month->format('Y-m')),
                ];
            });
    }

    /**
     * O mês de `$date` tem pagamento confirmado? É o que libera o agendamento
     * em `RecurrenceQuota::assertCanSchedule` — mês fora das mensalidades do
     * contrato também conta como não pago.
     */
    public function hasPaidMonthFor(CarbonInterface $date): bool
    {
        return $this->payments()
            ->where('period_month', $date->toImmutable()->startOfMonth()->toDateString())
            ->where('status', PaymentStatus::Paid)
            ->exists();
    }
}
