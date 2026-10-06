<?php

namespace App\Models;

use App\Infrastructure\Shared\Persistence\TenantScopedModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $user_id
 * @property int $appointment_id
 * @property Carbon|null $read_at
 */
class AppointmentNotification extends TenantScopedModel
{
    protected $table = 'appointment_notifications';

    protected $fillable = ['user_id', 'appointment_id', 'read_at'];

    protected function casts(): array
    {
        return ['read_at' => 'immutable_datetime'];
    }

    /**
     * @return BelongsTo<Appointment, $this>
     */
    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }
}
