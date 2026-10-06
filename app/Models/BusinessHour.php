<?php

namespace App\Models;

use App\Infrastructure\Shared\Persistence\TenantScopedModel;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $barber_id
 * @property int $weekday
 * @property string $opens_at
 * @property string $closes_at
 */
class BusinessHour extends TenantScopedModel
{
    protected $table = 'business_hours';

    protected $fillable = ['barber_id', 'weekday', 'opens_at', 'closes_at'];
}
