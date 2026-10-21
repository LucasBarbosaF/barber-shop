<?php

namespace App\Models;

use App\Infrastructure\Shared\Persistence\TenantScopedModel;
use Database\Factories\RecurrencePlanFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $tenant_id
 * @property string $name
 * @property string|null $description
 * @property int $service_id
 * @property int $duration_months
 * @property int $sessions_per_month
 * @property int $max_sessions_per_week
 * @property string $price
 * @property bool $is_active
 */
class RecurrencePlan extends TenantScopedModel
{
    /** @use HasFactory<RecurrencePlanFactory> */
    use HasFactory;

    protected $table = 'recurrence_plans';

    protected $fillable = [
        'name',
        'description',
        'service_id',
        'duration_months',
        'sessions_per_month',
        'max_sessions_per_week',
        'price',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'duration_months' => 'integer',
            'sessions_per_month' => 'integer',
            'max_sessions_per_week' => 'integer',
            'price' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Cortes do pacote: vigência em meses × cota mensal (6 × 2 = 12).
     *
     * Não é coluna porque o valor é derivado; o que a assinatura grava é o
     * snapshot destes três campos, então mudar o plano depois não reescreve
     * contrato assinado.
     */
    protected function totalSessions(): Attribute
    {
        return Attribute::get(fn (): int => $this->duration_months * $this->sessions_per_month);
    }

    /**
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    protected static function newFactory(): RecurrencePlanFactory
    {
        return RecurrencePlanFactory::new();
    }
}
