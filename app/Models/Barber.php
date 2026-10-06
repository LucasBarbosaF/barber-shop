<?php

namespace App\Models;

use App\Infrastructure\Shared\Persistence\TenantScopedModel;
use Database\Factories\BarberFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $tenant_id
 * @property string $name
 * @property string $phone
 * @property string|null $email
 * @property string|null $bio
 * @property bool $is_active
 * @property int|null $user_id
 * @property string|null $commission_percentage
 */
class Barber extends TenantScopedModel
{
    /** @use HasFactory<BarberFactory> */
    use HasFactory;

    protected $table = 'barbers';

    protected $fillable = [
        'name',
        'phone',
        'email',
        'bio',
        'is_active',
        'user_id',
        'commission_percentage',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'commission_percentage' => 'decimal:2',
        ];
    }

    protected static function newFactory(): BarberFactory
    {
        return BarberFactory::new();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function businessHours(): HasMany
    {
        return $this->hasMany(BusinessHour::class);
    }

    /** @return HasMany<CommissionRule, $this> */
    public function commissionRules(): HasMany
    {
        return $this->hasMany(CommissionRule::class);
    }

    public function services(): BelongsToMany
    {
        return $this->belongsToMany(Service::class, 'barber_services')
            ->withPivot('tenant_id')
            ->withTimestamps();
    }
}
