<?php

namespace App\Domain\Tenant\Models;

use App\Domain\Payments\Enums\PaymentMethod;
use App\Domain\Tenant\Enums\MembershipRole;
use App\Models\User;
use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Barbearia do sistema SaaS.
 *
 * Todo dado de negócio é escopado por tenant_id. O tenant é criado
 * exclusivamente pelo superadmin — não há registro público.
 *
 * @property string $name
 * @property string $slug
 * @property string|null $document
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $logo_path
 * @property array<int, string>|null $payment_methods
 */
#[Fillable(['name', 'slug', 'document', 'email', 'phone', 'logo_path', 'payment_methods', 'is_active', 'trial_ends_at'])]
class Tenant extends Model
{
    /** @use HasFactory<TenantFactory> */
    use HasFactory;

    /**
     * Os modelos de domínio não ficam em App\Models, então o Laravel não
     * consegue adivinhar o nome da factory sozinho.
     */
    protected static function newFactory(): TenantFactory
    {
        return TenantFactory::new();
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'trial_ends_at' => 'datetime',
            'payment_methods' => 'array',
        ];
    }

    /**
     * @return array<int, string>
     */
    public function enabledPaymentMethodValues(): array
    {
        if ($this->payment_methods === null) {
            return array_map(
                static fn (PaymentMethod $method): string => $method->value,
                PaymentMethod::cases(),
            );
        }

        return array_values(array_filter(
            $this->payment_methods,
            static fn (mixed $method): bool => is_string($method) && PaymentMethod::tryFrom($method) !== null,
        ));
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'memberships')
            ->withPivot(['role', 'is_active'])
            ->withTimestamps();
    }

    public function activeUsers(): BelongsToMany
    {
        return $this->users()->wherePivot('is_active', true);
    }

    public function admins(): BelongsToMany
    {
        return $this->activeUsers()->wherePivot('role', MembershipRole::Admin->value);
    }

    public function belongsToTenant(?string $tenantId): bool
    {
        return $tenantId !== null && (string) $this->getKey() === $tenantId;
    }

    /**
     * Gera um slug único a partir do nome informado.
     */
    public static function uniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'barbearia';
        $slug = $base;
        $suffix = 1;

        while (
            static::query()
                ->where('slug', $slug)
                ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
                ->exists()
        ) {
            $suffix++;
            $slug = $base.'-'.$suffix;
        }

        return $slug;
    }
}
