<?php

namespace App\Infrastructure\Shared\Persistence;

use App\Application\Shared\Tenancy\TenantContext;
use App\Domain\Tenant\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Base model for tenant-scoped entities.
 *
 * Child models must define:
 * - protected string $table
 * - protected array $fillable
 * - casts for money as decimal:2
 *
 * Uma linha de `tenant_id` no `create` já é preenchida a partir do contexto, e
 * a constraint `(tenant_id, ...)` do banco passa a ser o que impede um id
 * plantado de sobreviver. Se o valor vier preenchido, é porque alguém o setou de
 * propósito — não a partir do pedido.
 *
 * @property string|int|null $tenant_id
 */
abstract class TenantScopedModel extends Model
{
    protected $guarded = [];

    /**
     * `tenant_id` é infraestrutura, não campo do caso de uso — e precisa entrar
     * sempre.
     *
     * A convenção da base pede `$fillable` no model filho, e um `$fillable`
     * preenchido torna todo o resto **não** mass-assignable, `tenant_id`
     * incluso. Aí o `create(['tenant_id' => ...])` da importação e do console
     * seria descartado em silêncio, o hook `creating` veria `null` e
     * sobrescreveria com o tenant do contexto: sem erro, só o destino errado.
     *
     * Sobrescrever `getFillable()` em vez de `initialize{Class}()` porque a
     * segunda é convenção de trait — o inicializador de uma classe base
     * abstrata nunca é chamado, e o filtro silencioso continuaria lá.
     *
     * @return array<int, string>
     */
    public function getFillable(): array
    {
        return array_values(array_unique(array_merge(parent::getFillable(), ['tenant_id'])));
    }

    /**
     * Preenchido uma vez por modelo na carga, não a cada consulta.
     */
    protected static function booted(): void
    {
        static::addGlobalScope(app(TenantScope::class));

        static::creating(function (self $model): void {
            $tenantId = app(TenantContext::class)->id();

            if ($tenantId !== null && $model->tenant_id === null) {
                $model->tenant_id = $tenantId;
            }
        });
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Restringe a um tenant explícito, **além** do escopo global.
     *
     * Os dois filtros se somam: `where(tenant_id do contexto)` E
     * `where(tenant_id do argumento)`. Isso só pode restringir mais, nunca
     * ampliar — então o argumento não é uma saída para alcançar outra
     * barbearia a partir de um contexto alheio. Para isso (console,
     * importação, superadmin) o caminho é `withoutGlobalScope(TenantScope::class)`,
     * que é visível na chamada.
     */
    public function scopeForTenant(Builder $query, string $tenantId): Builder
    {
        return $query->where($this->qualifyColumn('tenant_id'), $tenantId);
    }
}
