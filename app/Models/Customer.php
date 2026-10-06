<?php

namespace App\Models;

use App\Infrastructure\Shared\Persistence\TenantScopedModel;
use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * @property int $id
 * @property int $tenant_id
 * @property string $name
 * @property string $phone
 * @property string|null $notes
 */
class Customer extends TenantScopedModel
{
    /** @use HasFactory<CustomerFactory> */
    use HasFactory;

    protected $table = 'customers';

    protected $fillable = ['name', 'phone', 'notes'];

    protected static function newFactory(): CustomerFactory
    {
        return CustomerFactory::new();
    }
}
