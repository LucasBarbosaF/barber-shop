<?php

namespace App\Models;

use App\Domain\Commissions\Enums\CommissionCalculationType;
use App\Infrastructure\Shared\Persistence\TenantScopedModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $barber_id
 * @property int|null $service_id
 * @property CommissionCalculationType $calculation_type
 * @property string|null $percentage
 * @property string|null $fixed_amount
 */
class CommissionRule extends TenantScopedModel
{
    protected $table = 'commission_rules';

    protected $fillable = ['barber_id', 'service_id', 'calculation_type', 'percentage', 'fixed_amount'];

    protected function casts(): array
    {
        return [
            'calculation_type' => CommissionCalculationType::class,
            'percentage' => 'decimal:2',
            'fixed_amount' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<Barber, $this> */
    public function barber(): BelongsTo
    {
        return $this->belongsTo(Barber::class);
    }

    /** @return BelongsTo<Service, $this> */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }
}
