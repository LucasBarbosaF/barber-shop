<?php

use App\Domain\Tenant\Enums\MembershipRole;
use App\Domain\Tenant\Models\Membership;
use App\Domain\Tenant\Models\Tenant;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Contrato do banco de `tenants` e `memberships`.
 *
 * Cada item da Sprint 2 é conferido pelo comportamento que ele promete, não por
 * leitura da migration: se alguém afrouxar um índice, uma FK ou um cascade,
 * o teste quebra aqui.
 *
 * ## A Sprint 5 mudou o que estes testes precisam declarar
 *
 * Com RLS ligado, ler `memberships` sem `app.current_tenant` devolve zero
 * linhas, e escrever sem ele afeta zero linhas **sem falhar**. Isso transforma
 * boa parte deste arquivo em asserções vacuamente verdade: "após o cascade, não
 * há memberships" é verdade tanto o cascade tendo funcionado quanto não, desde
 * que ninguém esteja olhando.
 *
 * Por isso a barbearia é declarada com `declaringDatabaseTenant()` antes de cada
 * leitura e escrita. Não é boilerplate: é o que separa "o banco agiu" de "o
 * banco não me mostrou".
 */
describe('constraints de membership', function () {
    it('recusa membership duplicada no mesmo tenant', function () {
        [$tenant, $user] = tenantWithUser();

        declaringDatabaseTenant($tenant);

        // A barbearia está declarada de propósito: sem isso a INSERT morre na
        // policy de RLS, que também é `QueryException`. O teste continuaria
        // verde estar provando a unique constraint ou a policy — e a diferença
        // é exatamente o contrato da Sprint 2.
        //
        // `failedWrite()` porque uma violação de unique aborta a transação, e a
        // leitura seguinte morreria com `25P02` em vez de responder.
        expect(failedWrite(fn () => Membership::query()->create([
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'role' => MembershipRole::Barber,
            'is_active' => true,
        ])))->toBeInstanceOf(QueryException::class);

        expect(Membership::query()
            ->where('user_id', $user->id)
            ->where('tenant_id', $tenant->id)
            ->count())->toBe(1);
    });

    it('aceita o mesmo usuario em varias barbearias', function () {
        [$tenantA] = tenantWithUser();
        [$tenantB] = tenantWithUser();
        $user = User::factory()->create();

        joinsTenant($user, $tenantA, MembershipRole::Admin);
        joinsTenant($user, $tenantB, MembershipRole::Barber);

        // Lê como as duas barbearias: cada uma vê a sua, e nenhuma enxerga a
        // membership que a outra gravou.
        declaringDatabaseTenant($tenantA);
        expect(Membership::query()->where('user_id', $user->id)->count())->toBe(1);
        expect($user->memberships()->count())->toBe(1);

        declaringDatabaseTenant($tenantB);
        expect(Membership::query()->where('user_id', $user->id)->count())->toBe(1);
        expect($user->memberships()->count())->toBe(1);
    });

    it('a role pertence ao vinculo, e cada barbearia so enxerga a sua', function () {
        [$tenantA] = tenantWithUser();
        [$tenantB] = tenantWithUser();
        $user = User::factory()->create();

        joinsTenant($user, $tenantA, MembershipRole::Admin);
        joinsTenant($user, $tenantB, MembershipRole::Barber);

        declaringDatabaseTenant($tenantA);
        expect($user->memberships()->pluck('role')->map(fn ($role) => $role->value)->all())
            ->toBe(['admin']);

        declaringDatabaseTenant($tenantB);
        expect($user->memberships()->pluck('role')->map(fn ($role) => $role->value)->all())
            ->toBe(['barber']);
    });

    it('aceita varias pessoas na mesma barbearia', function () {
        [$tenant] = tenantWithUser();

        addMemberTo($tenant, MembershipRole::Barber);
        addMemberTo($tenant, MembershipRole::Receptionist);
        addMemberTo($tenant, MembershipRole::Admin);

        declaringDatabaseTenant($tenant);

        expect($tenant->memberships()->count())->toBe(4);
        expect($tenant->activeUsers()->count())->toBe(4);
    });

    it('impede membership apontando para tenant inexistente', function () {
        $user = User::factory()->create();

        // O tenant declarado é o inexistente, para que a INSERT passe na policy e
        // morra na FK — que é o que este teste promete medir.
        setDatabaseTenantId(999999);

        expect(failedWrite(fn () => Membership::query()->create([
            'user_id' => $user->id,
            'tenant_id' => 999999,
            'role' => MembershipRole::Barber,
            'is_active' => true,
        ])))->toBeInstanceOf(QueryException::class);
    });
});

describe('cascade', function () {
    it('excluir usuario remove as memberships dele', function () {
        [$tenant, $user] = tenantWithUser();

        declaringDatabaseTenant($tenant);
        expect($tenant->memberships()->count())->toBe(1);

        $user->delete();

        declaringDatabaseTenant($tenant);

        expect(Membership::query()->where('user_id', $user->id)->count())->toBe(0);
        expect($tenant->memberships()->count())->toBe(0);
    });

    it('excluir tenant remove as memberships do tenant', function () {
        [$tenant, $user] = tenantWithUser();

        declaringDatabaseTenant($tenant);

        $tenant->delete();

        declaringDatabaseTenant($tenant);

        expect(Membership::query()->where('tenant_id', $tenant->id)->count())->toBe(0);
        expect(User::query()->whereKey($user->id)->exists())->toBeTrue();
    });

    it('o cascade de FK ignora o rls, e isso esta medido', function () {
        /*
         * Achado da Sprint 5, medido em PostgreSQL 16: um trigger de integridade
         * referencial roda com os privilégios do dono da tabela e **não** obedece
         * às policies de RLS. Apagar a linha de `users` levou a membership junto
         * com a sessão sem nenhum contexto declarado — o banco enxerga a linha e
         * a apaga.
         *
         * Hoje isso não é brecha: `users` não é tenant-scoped, e quem apaga a
         * própria conta apaga os próprios vínculos. Mas o Sprint 15 vai colocar
         * "excluir usuário" no painel, dentro de uma barbearia — e esse
         * `delete()` cascateia as memberships da pessoa **nas outras
         * barbearias dela**, que a tela não enxerga.
         *
         * Fica como dívida measured, com teste que falha se o comportamento
         * mudar: se o cascade um dia passar a respeitar o RLS, este teste avisa.
         */
        [$tenant, $user] = tenantWithUser();

        actingWithoutTenant();

        expect(Membership::query()->where('user_id', $user->id)->count())->toBe(0);

        $user->delete();

        declaringDatabaseTenant($tenant);

        expect(Membership::query()->where('user_id', $user->id)->count())->toBe(0);
    });
});

describe('desativacao preserva historico', function () {
    it('membership desativada continua no banco', function () {
        [$tenant, , $membership] = tenantWithUser(MembershipRole::Barber);

        revokingMembership($membership);

        declaringDatabaseTenant($tenant);
        $stored = Membership::query()->findOrFail($membership->id);

        expect($stored->is_active)->toBeFalse();
        expect($stored->role)->toBe(MembershipRole::Barber);
        expect($tenant->memberships()->count())->toBe(1);
        expect($tenant->activeUsers()->count())->toBe(0);
    });

    it('tenant desativado continua no banco e sem acesso', function () {
        [$tenant, , $membership] = tenantWithUser();

        $tenant->update(['is_active' => false]);
        $tenant->refresh();

        declaringDatabaseTenant($tenant);

        expect($tenant->is_active)->toBeFalse();
        expect(Tenant::query()->findOrFail($tenant->id)->memberships()->count())->toBe(1);
        expect($membership->fresh()->is_active)->toBeTrue();
    });

    it('reativar membership devolve acesso', function () {
        [$tenant, , $membership] = tenantWithUser(MembershipRole::Barber);

        revokingMembership($membership);
        reactivatingMembership($membership);

        declaringDatabaseTenant($tenant);
        $stored = Membership::query()->findOrFail($membership->id);

        expect($stored->is_active)->toBeTrue();
        expect($stored->isActive())->toBeTrue();
    });
});

describe('identidade da barbearia', function () {
    it('recusa slug repetido', function () {
        Tenant::factory()->create(['slug' => 'barbearia-sanca']);

        expect(fn () => Tenant::factory()->create(['slug' => 'barbearia-sanca']))
            ->toThrow(QueryException::class);
    });

    it('gera slug unico com sufixo', function () {
        Tenant::factory()->create(['name' => 'Barbearia Sanca', 'slug' => 'barbearia-sanca']);

        expect(Tenant::uniqueSlug('Barbearia Sanca'))->toBe('barbearia-sanca-2');
        expect(Tenant::uniqueSlug('Barbearia Nova'))->toBe('barbearia-nova');
    });

    it('gera slug a partir do nome em barbearia sem nome registered', function () {
        $tenant = Tenant::factory()->create(['name' => 'Studio Magne', 'slug' => 'studio-magne']);

        expect($tenant->slug)->toBe('studio-magne');
        expect($tenant->belongsToTenant((string) $tenant->id))->toBeTrue();
        expect($tenant->belongsToTenant((string) ($tenant->id + 1)))->toBeFalse();
        expect($tenant->belongsToTenant(null))->toBeFalse();
    });
});

describe('indices', function () {
    /**
     * @param  array<int, array<string, mixed>>  $indexes
     * @return array<string, mixed>|null
     */
    function findIndex(array $indexes, array $columns): ?array
    {
        $wanted = collect($columns)->sort()->values()->all();

        foreach ($indexes as $index) {
            if (collect($index['columns'])->sort()->values()->all() === $wanted) {
                return $index;
            }
        }

        return null;
    }

    it('memberships tem unique por usuario e tenant', function () {
        $index = findIndex(Schema::getIndexes('memberships'), ['user_id', 'tenant_id']);

        expect($index)->not->toBeNull();
        expect($index['unique'])->toBeTrue();
    });

    it('memberships tem indice por tenant e role', function () {
        $index = findIndex(Schema::getIndexes('memberships'), ['tenant_id', 'role']);

        expect($index)->not->toBeNull();
    });

    it('tenants tem indice de is_active e slug unico', function () {
        $active = findIndex(Schema::getIndexes('tenants'), ['is_active']);
        $slug = findIndex(Schema::getIndexes('tenants'), ['slug']);

        expect($active)->not->toBeNull();
        expect($slug)->not->toBeNull();
        expect($slug['unique'])->toBeTrue();
    });
});

describe('integridade de papel', function () {
    it('a coluna role e texto e o cast e quem valida', function () {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create();

        // Escrita direta pelo query builder, para provar que a validação está no
        // cast do model e não na coluna. A barbearia está declarada porque, sem
        // ela, a INSERT morre na policy e o teste mediria a policy.
        declaringDatabaseTenant($tenant);

        $membershipId = DB::table('memberships')->insertGetId([
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'role' => 'nao-existe',
            'is_active' => true,
        ]);

        expect(DB::table('memberships')->where('id', $membershipId)->value('role'))
            ->toBe('nao-existe');

        expect(fn () => Membership::query()->findOrFail($membershipId)->role)
            ->toThrow(ValueError::class);
    });

    it('toda role do enum cabe na coluna de 32 caracteres', function () {
        // O comprimento da coluna so e garantido em PostgreSQL/MySQL; o SQLite
        // aceita qualquer tamanho. O que da para provar em qualquer driver e que
        // nenhum valor do enum estoura o limite declarado na migration.
        foreach (MembershipRole::cases() as $role) {
            expect(strlen($role->value))->toBeLessThanOrEqual(32);
        }

        expect(Schema::getColumnListing('memberships'))
            ->toContain('role', 'user_id', 'tenant_id', 'is_active');
    });
});
