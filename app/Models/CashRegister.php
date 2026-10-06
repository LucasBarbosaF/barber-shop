<?php

namespace App\Models;

use App\Domain\Cash\Enums\CashRegisterStatus;
use App\Infrastructure\Shared\Persistence\TenantScopedModel;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $tenant_id
 * @property CashRegisterStatus $status
 * @property string $opening_amount
 * @property string|null $closing_amount
 * @property string|null $expected_amount
 * @property string|null $difference_amount
 * @property Carbon $opened_at
 * @property Carbon|null $closed_at
 * @property int|null $opened_by
 * @property int|null $closed_by
 */
class CashRegister extends TenantScopedModel
{
    protected $table = 'cash_registers';

    protected $fillable = [
        'status',
        'opening_amount',
        'closing_amount',
        'expected_amount',
        'difference_amount',
        'opened_at',
        'closed_at',
        'opened_by',
        'closed_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => CashRegisterStatus::class,
            'opening_amount' => 'decimal:2',
            'closing_amount' => 'decimal:2',
            'expected_amount' => 'decimal:2',
            'difference_amount' => 'decimal:2',
            'opened_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
        ];
    }

    /** @return HasMany<CashTransaction, $this> */
    public function transactions(): HasMany
    {
        return $this->hasMany(CashTransaction::class);
    }
}
