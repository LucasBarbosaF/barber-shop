<?php

namespace App\Models;

use App\Domain\Attendance\Enums\AttendanceStatus;
use App\Infrastructure\Shared\Persistence\TenantScopedModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $appointment_id
 * @property AttendanceStatus $status
 * @property Carbon $opened_at
 * @property Carbon|null $closed_at
 */
class Attendance extends TenantScopedModel
{
    protected $table = 'attendances';

    protected $fillable = ['appointment_id', 'status', 'opened_at', 'closed_at'];

    protected function casts(): array
    {
        return [
            'status' => AttendanceStatus::class,
            'opened_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Appointment, $this>
     */
    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    /**
     * @return HasMany<AttendanceItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(AttendanceItem::class);
    }

    /**
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
}
