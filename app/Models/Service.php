<?php

namespace App\Models;

use App\Infrastructure\Shared\Persistence\TenantScopedModel;
use Database\Factories\ServiceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * @property int $id
 * @property int $tenant_id
 * @property string $name
 * @property string|null $description
 * @property int $duration_minutes
 * @property string $price
 * @property bool $is_active
 */
class Service extends TenantScopedModel
{
    /** @use HasFactory<ServiceFactory> */
    use HasFactory;

    protected $table = 'services';

    protected $fillable = ['name', 'description', 'duration_minutes', 'price', 'is_active'];

    protected function casts(): array
    {
        return [
            'duration_minutes' => 'integer',
            'price' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    protected static function newFactory(): ServiceFactory
    {
        return ServiceFactory::new();
    }
}
