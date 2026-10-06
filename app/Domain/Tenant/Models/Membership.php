<?php

namespace App\Domain\Tenant\Models;

use App\Domain\Tenant\Enums\MembershipRole;
use App\Models\User;
use Database\Factories\MembershipFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Vínculo usuário ↔ barbearia.
 *
 * O papel (role) pertence à barbearia, nunca globalmente: o mesmo usuário
 * pode ser admin numa barbearia e barbeiro em outra.
 * Unique: (user_id, tenant_id).
 *
 * @property MembershipRole $role
 * @property bool $is_active
 */
#[Fillable(['user_id', 'tenant_id', 'role', 'is_active'])]
class Membership extends Model
{
    /** @use HasFactory<MembershipFactory> */
    use HasFactory;

    /**
     * Os modelos de domínio não ficam em App\Models, então o Laravel não
     * consegue adivinhar o nome da factory sozinho.
     */
    protected static function newFactory(): MembershipFactory
    {
        return MembershipFactory::new();
    }

    protected function casts(): array
    {
        return [
            'role' => MembershipRole::class,
            'is_active' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function isAdmin(): bool
    {
        return $this->role === MembershipRole::Admin;
    }

    public function isActive(): bool
    {
        return $this->is_active;
    }
}
