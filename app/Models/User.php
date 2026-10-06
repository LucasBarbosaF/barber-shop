<?php

namespace App\Models;

use App\Domain\Tenant\Enums\MembershipRole;
use App\Domain\Tenant\Models\Membership;
use App\Domain\Tenant\Models\Tenant;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'is_superadmin', 'must_change_password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_superadmin' => 'boolean',
            'must_change_password' => 'boolean',
        ];
    }

    /**
     * Barbearias (tenants) as quais o usuário pertence.
     *
     * @return HasMany<Membership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    public function barberProfiles(): HasMany
    {
        return $this->hasMany(Barber::class);
    }

    /**
     * Barbearias ativas do usuário.
     *
     * @return BelongsToMany<Tenant, $this>
     */
    public function tenants(): BelongsToMany
    {
        return $this->belongsToMany(Tenant::class, 'memberships')
            ->withPivot(['role', 'is_active'])
            ->withTimestamps();
    }

    public function isSuperadmin(): bool
    {
        return $this->is_superadmin;
    }

    public function mustChangePassword(): bool
    {
        return $this->must_change_password;
    }

    /**
     * @param  array<int, MembershipRole>  $roles
     */
    public function hasRoleInAnyTenant(array $roles): bool
    {
        return $this->memberships()
            ->where('is_active', true)
            ->whereIn('role', array_map(
                static fn (MembershipRole $role): string => $role->value,
                $roles,
            ))
            ->exists();
    }
}
