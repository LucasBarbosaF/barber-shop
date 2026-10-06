<?php

namespace App\Models;

use App\Infrastructure\Shared\Persistence\TenantScopedModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $barber_id
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 * @property string|null $reason
 */
class BlockedPeriod extends TenantScopedModel
{
    protected $table = 'blocked_periods';

    protected $fillable = ['barber_id', 'starts_at', 'ends_at', 'reason'];

    protected function casts(): array
    {
        return [
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Barber, $this>
     */
    public function barber(): BelongsTo
    {
        return $this->belongsTo(Barber::class);
    }
}
