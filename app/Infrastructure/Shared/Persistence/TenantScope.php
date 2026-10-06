<?php

namespace App\Infrastructure\Shared\Persistence;

use App\Application\Shared\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Escopo global de tenant.
 *
 * Existe para que a pergunta "de qual barbearia?" não dependa do desenvolvedor
 * lembrar de escrever `->where('tenant_id', ...)` em cada consulta. Omitir o
 * filtro não é o modo padrão de errar aqui: é vazamento silencioso entre
 * barbearias, que só aparece quando alguém olha a tela errada.
 *
 * Deliberadamente **não** registrado como global scope em todo model: só quem
 * estende `TenantScopedModel` o recebe. Um model sem `tenant_id` — usuários,
 * planos, configurações de plataforma — quebraria com `column not found` se
 * recebesse o filtro.
 *
 * Sem tenant no contexto o escopo não filtra nada, e a query volta a ser
 * cross-tenant. Por isso `EnsureTenantSelected` é middleware obrigatório nas
 * rotas que leem dado de barbearia: a exceção de quem nunca resolvida é o 403
 * explícito, não um vazamento silencioso. A leitura defensiva abaixo existe para
 * o escopo poder ser usado em console e testes sem explodir; a garantia real
 * continua sendo a da rota.
 */
final class TenantScope implements Scope
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    public function apply(Builder $builder, Model $model): void
    {
        $this->constrain($builder);
    }

    /**
     * Filtro explícito para models que não estendem `TenantScopedModel`.
     *
     * `Membership` é um: é um model de `App\Domain`, e `App\Domain` não pode
     * depender de `App\Infrastructure` (regra do `BoundariesTest`). O escopo
     * global resolveria isso se a dependência fosse permitida; como não é, quem
     * consulta membership usa esta porta explícita, com o mesmo filtro.
     */
    public function constrain(Builder $query): Builder
    {
        $tenantId = $this->tenantContext->id();

        if ($tenantId !== null) {
            $query->where($query->getModel()->qualifyColumn('tenant_id'), $tenantId);
        }

        return $query;
    }

    public static function for(Builder $query): Builder
    {
        return (new TenantScope(app(TenantContext::class)))->constrain($query);
    }
}
