<?php

use App\Application\Shared\Tenancy\TenantContext;
use App\Domain\Authorization\Enums\Permission;
use App\Domain\Authorization\RolePermissions;
use App\Domain\Tenant\Enums\MembershipRole;
use App\Domain\Tenant\Models\Membership;
use App\Domain\Tenant\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in(__DIR__.'/Feature');

/*
 * ## O contexto de banco é o tenant, e o banco só obedece se ele for declarado
 *
 * Todo helper aqui escreve a *customized option* que as policies de RLS leem —
 * `app.current_tenant` para a barbearia, `app.current_user` para a pessoa. Isso
 * não é enfeite de fixture: sem declarar o tenant, o próprio `INSERT` do fixture
 * é recusado (`new row violates row-level security policy`), e sem declarar o
 * usuário, uma leitura de membership volta vazia.
 *
 * E a consequência que vale registrar: **estes testes só rodam em PostgreSQL**,
 * com um papel sem `SUPERUSER` e sem `BYPASSRLS`. Ver `./pest-pgsql.sh`. Com
 * `SUPERUSER` as policies não são exercitadas e a suíte passa a provar nada — o
 * teste `a suite nao roda vazia` existe para falhar alto nesse caso.
 */

/**
 * Cria uma barbearia, um usuário e a membership que liga os dois.
 *
 * É a forma padrão do cenário: duas barbearias, A e B, com usuários próprios.
 * Qualquer authorize cross-tenant aparece imediatamente nesse arranjo.
 *
 * O contexto de banco é setado depois da criação. Sob RLS, uma INSERT em
 * `memberships` sem `app.current_tenant` é recusada com
 * `new row violates row-level security policy` — e recusar aqui estaria
 * medindo o fixture, não o comportamento testado.
 *
 * @param  array<string, mixed>  $tenantAttributes
 * @param  array<string, mixed>  $userAttributes
 * @return array{0: Tenant, 1: User, 2: Membership}
 */
function tenantWithUser(
    MembershipRole $role = MembershipRole::Receptionist,
    array $tenantAttributes = [],
    array $userAttributes = [],
): array {
    $tenant = Tenant::factory()->create($tenantAttributes);
    $user = User::factory()->create($userAttributes);

    $membership = creatingMembershipIn($tenant, $user, $role);

    return [$tenant, $user, $membership];
}

/**
 * Cria mais uma pessoa **dentro da mesma** barbearia.
 *
 * `tenantWithUser()` sempre abre uma barbearia nova, o que serve para provar
 * isolamento entre barbearias mas quebra qualquer cenário que dependa de duas
 * pessoas convivendo na mesma (ex.: rebaixar um admin havendo outro).
 *
 * @param  array<string, mixed>  $userAttributes
 * @return array{0: User, 1: Membership}
 */
function addMemberTo(
    Tenant $tenant,
    MembershipRole $role = MembershipRole::Receptionist,
    array $userAttributes = [],
): array {
    $user = User::factory()->create($userAttributes);

    $membership = creatingMembershipIn($tenant, $user, $role);

    return [$user, $membership];
}

/**
 * Cria a membership declareando o contexto **só durante o INSERT**.
 *
 * A policy de INSERT do RLS exige `tenant_id = app.current_tenant_id()`, então
 * o fixture precisa apresentá-lo. Mas devolver o contexto setado seria um
 * efeito colateral: `tenantWithUser()` é chamado duas vezes no cenário comum
 * (barbearia A e B) e o último venceria — o teste passaria a rodar dentro de B
 * sem ter pedido isso, invertendo ~70 asserções que contam com "sem contexto,
 * tudo é negado".
 *
 * Por isso o contexto entra e sai. O que sobra para o teste é só o fixture.
 */
function creatingMembershipIn(Tenant $tenant, User $user, MembershipRole $role): Membership
{
    setDatabaseTenantId($tenant->getKey());

    try {
        return Membership::factory()->create([
            'user_id' => $user->getKey(),
            'tenant_id' => $tenant->getKey(),
            'role' => $role,
        ]);
    } finally {
        setDatabaseTenantId(null);
    }
}

/**
 * Coloca uma pessoa numa barbearia já existente.
 *
 * É o caminho para o cenário "a mesma pessoa pertence a A e a B", que não cabe
 * em `tenantWithUser()` — aquele sempre abre uma barbearia nova. Substituir o
 * `Membership::factory()->create([...])` direto por isto importa uma coisa: a
 * escrita passa a declarar o tenant, e sem isso o RLS recusa o INSERT.
 */
function joinsTenant(User $user, Tenant $tenant, MembershipRole $role = MembershipRole::Receptionist): Membership
{
    return creatingMembershipIn($tenant, $user, $role);
}

/**
 * Coloca a barbearia no contexto da requisição atual — **e no banco**.
 *
 * São dois contextos porque são dois lugares: o `TenantContext` é o que a
 * aplicação lê (Gate, Policy, `TenantScope`), e `app.current_tenant` é o que a
 * policy de RLS lê. Um teste que age dentro de uma barbearia precisa dos dois,
 * senão o banco e o código discordam sobre quem é o usuário — e o teste passa
 * a provar a coisa errada.
 */
function actingInTenant(Tenant $tenant): void
{
    app(TenantContext::class)->set((string) $tenant->getKey());
    setDatabaseTenantId($tenant->getKey());
}

/**
 * O oposto de `actingInTenant`: limpa os dois contextos.
 *
 * Existe porque "sem barbearia no contexto" é um estado **legítimo** — quem
 * pertence a várias ainda não escolheu — e o comportamento dele precisa de
 * teste. Sem este helper, um teste que quer provar a negação sem contexto
 * ficaria dependente de o fixture não ter setado nada, o que é frágil: basta
 * `tenantWithUser()` ganhar um `actingInTenant()` interno e o teste passa a
 * provar outra coisa sem reclamar.
 */
function actingWithoutTenant(): void
{
    app(TenantContext::class)->clear();
    setDatabaseTenantId(null);
}

/**
 * Diz ao banco qual usuário está agindo, para a policy reconhecer as próprias
 * memberships (o furo de SELECT que permite escolher a barbearia) e o
 * superadmin.
 */
function actingAsUser(User $user): void
{
    setDatabaseUserId($user->getKey());
}

/**
 * Declara a barbearia **só no banco**, sem tocar no `TenantContext` da aplicação.
 *
 * Existe separado de `actingInTenant()` porque são dois públicos diferentes: em
 * parte dos testes o que se quer é "o banco sabe qual é a barbearia" sem que a
 * aplicação acredite que há contexto — por exemplo ao revogar uma membership e
 * então provar que o resolver devolve `None` justamente porque o contexto está
 * vazio.
 *
 * ## Por que os testes precisam declarar a barbearia para escrever
 *
 * Sem `app.current_tenant`, um `UPDATE` em `memberships` **não falha**: a policy
 * `USING` simplesmente não casa com a linha, o banco afeta zero linhas e o
 * Eloquent devolve `0` sem reclamar. O teste segue adiante verificando um
 * estado que nunca foi gravado, e a falha aparece longe da causa — como se o
 * código estivesse errado.
 *
 * Por isso `revokingMembership()` e `reactivatingMembership()` conferem a
 * contagem de linhas afetadas. Sem essa checagem, "revogar a membership" e
 * "não fazer nada" são o mesmo teste.
 */
function declaringDatabaseTenant(Tenant $tenant): void
{
    setDatabaseTenantId($tenant->getKey());
}

/**
 * Revoga a membership e prova que a escrita aconteceu.
 *
 * @return Membership a mesma instância, com `is_active` jáfalse em memória
 */
function revokingMembership(Membership $membership): Membership
{
    return writingMembershipFlag($membership, false);
}

/**
 * Reativa a membership. O caminho inverso do anterior, com a mesma checagem.
 *
 * @return Membership a mesma instância, com `is_active` já true em memória
 */
function reactivatingMembership(Membership $membership): Membership
{
    return writingMembershipFlag($membership, true);
}

/**
 * A escrita em `memberships` que o RLS pode esconder, com a prova de que não
 * escondeu.
 *
 * O `WHERE id = ...` isolado importa: sem ele o teste poderia afetar zero linhas
 * por outro motivo (a linha não existe) e ainda assim passar.
 *
 * O atributo em memória é atualizado à mão porque um `refresh()` logo depois
 * falharia — o contexto do banco já foi devolvido, e o `SELECT` de volta não
 * visitaria a linha.
 */
function writingMembershipFlag(Membership $membership, bool $isActive): Membership
{
    $tenantId = $membership->tenant_id;

    setDatabaseTenantId($tenantId);

    try {
        $affected = Membership::query()
            ->whereKey($membership->getKey())
            ->update(['is_active' => $isActive]);
    } finally {
        setDatabaseTenantId(null);
    }

    expect($affected)->toBe(
        1,
        'RLS escondeu a escrita: sem app.current_tenant o UPDATE casa zero linhas '
        .'e não levanta erro. Declare a barbearia antes de escrever.',
    );

    $membership->is_active = $isActive;
    $membership->syncOriginalAttribute('is_active');

    return $membership;
}

/**
 * `SET LOCAL` dentro da transação que o `RefreshDatabase` já abre.
 *
 * Não há `try/finally` nem reset: o contexto morre com a transação do teste,
 * que é exatamente a garantia que a Sprint 5 comprou para a aplicação.
 */
function setDatabaseTenantId(int|string|null $tenantId): void
{
    setDatabaseOption('app.current_tenant', $tenantId);
}

function setDatabaseUserId(int|string|null $userId): void
{
    setDatabaseOption('app.current_user', $userId);
}

function setDatabaseOption(string $name, int|string|null $value): void
{
    DB::selectOne('SELECT set_config(?, ?, true)', [
        $name,
        $value === null ? '' : (string) $value,
    ]);
}

/**
 * Executa uma escrita que **deve** falhar e devolve a exceção.
 *
 * Devolve `null` quando a escrita passou, para o `expect(...)->toBeInstanceOf()`
 * acusar a ausência de erro em vez de estourar com "no exception thrown".
 *
 * ## Por que o savepoint é obrigatório
 *
 * No PostgreSQL, qualquer violação de constraint **derruba a transação**:
 * depois dela, todo comando responde `25P02 current transaction is aborted`. E
 * desde a Sprint 4 toda requisição autenticada roda dentro de uma transação —
 * a que `SetUserDatabaseContext` abre. Um teste que espera uma violação de
 * unique ou de FK e segue lendo na mesma transação não falha no que espera:
 * falha na consulta seguinte, com um erro que não tem nada a ver com o
 * contrato testado.
 *
 * `DB::transaction()` aninhado vira `SAVEPOINT`, e a exceção faz o Laravel
 * voltar ao savepoint em vez de desfazer a transação de fora. O teste mede a
 * constraint e a transação continua servível.
 *
 * O mesmo cuidado vale para a aplicação: capturar uma `QueryException` e seguir
 * na mesma transação não é uma saída — é um `25P02` garantido. Ou a escrita
 * vai num savepoint, ou o erro sobe e a transação é desfeita.
 *
 * @param  Closure(): mixed  $write
 */
function failedWrite(Closure $write): ?Throwable
{
    try {
        DB::transaction($write);
    } catch (Throwable $exception) {
        return $exception;
    }

    return null;
}

/**
 * Qualquer role que concede a permission; o admin é o último recurso, já que
 * é a única role com a matriz completa.
 */
function roleGranting(Permission $permission): MembershipRole
{
    foreach (MembershipRole::cases() as $role) {
        if (in_array($permission, RolePermissions::for($role), true)) {
            return $role;
        }
    }

    return MembershipRole::Admin;
}

/**
 * Uma role que **não** concede a permission, ou null quando todas concedem.
 *
 * O null importa: algumas permissions (o dashboard, por exemplo) são de leitura
 * e valem para as seis roles, então não existe quem negar.
 */
function roleDenying(Permission $permission): ?MembershipRole
{
    foreach (MembershipRole::cases() as $role) {
        if (! in_array($permission, RolePermissions::for($role), true)) {
            return $role;
        }
    }

    return null;
}
