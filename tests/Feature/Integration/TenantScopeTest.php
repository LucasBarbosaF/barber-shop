<?php

use App\Domain\Tenant\Models\Membership;
use App\Domain\Tenant\Models\Tenant;
use App\Infrastructure\Shared\Persistence\TenantScope;
use App\Infrastructure\Shared\Persistence\TenantScopedModel;
use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Tests\Fixtures\TenantScopedWidget;

/*
 * O escopo de tenant, provado pelo efeito e não pela implementação: o que
 * importa é que a consulta que omite o filtro traga só a barbearia do contexto.
 *
 * ## Sem tenant no contexto: duas camadas, uma não é suficiente
 *
 * O escopo em PHP não filtra sem contexto — continua devolvendo tudo. Isso é
 * proposital e não é a segurança do produto: o RLS do PostgreSQL é quem nega, e
 * ele devolve zero linhas. A aplicação não pode tratar "o escopo não filtrou"
 * como erro, porque `Membership` também é usado na criação (a linha ainda não
 * existe) e no contexto de resolução de tenant.
 *
 * Se a aplicação tentasse falhar fechado no PHP, ela quebraria a escrita de
 * memberships e a própria tela de escolha de barbearia. A última linha é o
 * ponto: quem garante o isolamento é o banco, e o banco é testado com o
 * contexto vazio de verdade — ver `RowLevelSecurityTest`.
 *
 * O teste abaixo fixa as duas metades: o escopo sozinho não filtra, e mesmo
 * assim a consulta não devolve nada.
 */

beforeEach(function () {
    Schema::create('tenant_scoped_widgets', function ($table) {
        $table->id();
        $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
        $table->string('name');
        $table->timestamps();
    });
});

afterEach(function () {
    Schema::dropIfExists('tenant_scoped_widgets');
});

describe('TenantScope explicito', function () {
    it('filtra a consulta pela barbearia do contexto', function () {
        [$tenantA] = tenantWithUser();
        [$tenantB] = tenantWithUser();

        actingInTenant($tenantA);

        expect(TenantScope::for(Membership::query())->pluck('tenant_id')->unique()->values()->all())
            ->toBe([$tenantA->getKey()]);

        // Um filtro que não muda com a escolha não está filtrando nada.
        actingInTenant($tenantB);

        expect(TenantScope::for(Membership::query())->pluck('tenant_id')->unique()->values()->all())
            ->toBe([$tenantB->getKey()]);
    });

    it('qualifica a coluna, para nao colidir com o where do dono da query', function () {
        [$tenantA] = tenantWithUser();

        actingInTenant($tenantA);

        // A coluna precisa ser `memberships.tenant_id`. Um `tenant_id` solto numa
        // query com join seria ambíguo — ou, pior, o da outra tabela.
        $sql = TenantScope::for(
            Membership::query()->join('users', 'users.id', '=', 'memberships.user_id')
        )->toSql();

        expect($sql)->toContain('"memberships"."tenant_id"');
    });

    it('nao filtra sem tenant no contexto, e o banco nega assim mesmo', function () {
        [$tenantA, , $membershipOfA] = tenantWithUser();
        [$tenantB, , $membershipOfB] = tenantWithUser();

        // Mesmo tenant do contexto, o RLS não aparece na query. Prova de que a
        // filtragem abaixo não é o escopo fazendo o trabalho dele.
        expect(TenantScope::for(Membership::query())->toSql())
            ->not->toContain((string) $tenantA->getKey());

        /*
         * Contexto vazio: o escopo não filtra e a query volta... vazia.
         *
         * Era 2 linhas aqui antes do RLS. A resposta certa não é "volta tudo" nem
         * "lança exceção em PHP": `memberships` tem RLS e sem `app.current_tenant`
         * o PostgreSQL não devolve linha nenhuma.
         *
         * Uma aplicação que contasse linhas para decidir algo continua
         * funcionando; uma que esperasse receber dados alheios para filtrar depois
         * já não consegue — que é o ponto. A defesa é do banco, e o middleware
         * `SetTenantDatabaseContext` existe para que o banco rarely chegue
         * nesse estado em produção.
         */
        expect(TenantScope::for(Membership::query())->count())->toBe(0);

        /*
         * As duas linhas existem — nenhuma consulta sem contexto as alcança, nem
         * tirando os escopos do Eloquent. `withoutGlobalScopes()` é de PHP e o
         * RLS é do banco: `SET row_security = off` numa sessão sem papel dono da
         * tabela só produz erro, não uma leitura livre.
         *
         * A prova de que elas existem é que cada contexto enxerga a sua, e são
         * duas linhas diferentes: 1 + 1 = 2, e nenhuma soma 2.
         */
        actingInTenant($tenantA);
        expect(TenantScope::for(Membership::query())->count())->toBe(1);

        actingInTenant($tenantB);
        expect(TenantScope::for(Membership::query())->count())->toBe(1);

        // E o par que o oráculo de existência denunciaria: com contexto de A, a
        // membership de B some do resultado.
        actingInTenant($tenantA);

        expect(TenantScope::for(Membership::query())->pluck('id')->all())
            ->toBe([$membershipOfA->getKey()])
            ->not->toContain($membershipOfB->getKey());
    });

    it('soma ao escopo global em vez de substitui-lo', function () {
        [$tenantA] = tenantWithUser();
        [$tenantB] = tenantWithUser();

        actingInTenant($tenantA);

        TenantScopedWidget::query()->create(['name' => 'de-a']);
        TenantScopedWidget::query()->create([
            'name' => 'de-b',
            'tenant_id' => $tenantB->getKey(),
        ]);

        // `forTenant` estreita, não amplia: com o contexto em A, pedir B não
        // devolve nada. O argumento não é uma saída para alcançar outra
        // barbearia a partir de um contexto alheio — a widening tem de ser
        // visível na chamada.
        expect(TenantScopedWidget::query()->forTenant((string) $tenantB->getKey())->count())->toBe(0);
        expect(TenantScopedWidget::query()->forTenant((string) $tenantA->getKey())->count())->toBe(1);

        // O caminho de widening é explícito.
        expect(
            TenantScopedWidget::query()
                ->withoutGlobalScope(TenantScope::class)
                ->forTenant((string) $tenantB->getKey())
                ->count()
        )->toBe(1);
    });
});

describe('TenantScopedModel', function () {
    it('preenche tenant_id a partir do contexto', function () {
        [$tenant] = tenantWithUser();

        actingInTenant($tenant);

        $widget = TenantScopedWidget::query()->create(['name' => 'agenda']);

        expect($widget->tenant_id)->toBe((string) $tenant->getKey());
    });

    it('nao sobrescreve um tenant_id definido a proposito', function () {
        [$tenantA] = tenantWithUser();
        [$tenantB] = tenantWithUser();

        actingInTenant($tenantA);

        // Escrever em B a partir do contexto de A é operação de console e
        // importação, não de request. Se o escopo global de leitura existisse
        // aqui, o `find` de volta já voltaria vazio.
        $widget = TenantScopedWidget::query()->create([
            'name' => 'importado',
            'tenant_id' => $tenantB->getKey(),
        ]);

        expect($widget->tenant_id)->toBe($tenantB->getKey());
    });

    it('registra o escopo global: a query sem filtro ja vem escopada', function () {
        [$tenantA] = tenantWithUser();
        [$tenantB] = tenantWithUser();

        actingInTenant($tenantA);

        // Insere em A pelo contexto, e em B com `withoutGlobalScope`, para que as
        // duas linhas existam de verdade.
        TenantScopedWidget::query()->create(['name' => 'de-a']);
        TenantScopedWidget::query()
            ->withoutGlobalScope(TenantScope::class)
            ->create(['name' => 'de-b', 'tenant_id' => $tenantB->getKey()]);

        expect(TenantScopedWidget::query()->pluck('name')->all())->toBe(['de-a']);
    });

    it('sem contexto, o escopo global deixa a query passar', function () {
        [$tenantA] = tenantWithUser();

        TenantScopedWidget::query()
            ->withoutGlobalScope(TenantScope::class)
            ->create(['name' => 'de-a', 'tenant_id' => $tenantA->getKey()]);

        // Sem tenant não há barbearia para filtrar. A garantia real continua
        // sendo o 403 de `tenant.selected`, não este escopo.
        expect(TenantScopedWidget::query()->count())->toBe(1);
    });

    it('nao recebe o escopo um model sem tenant_id', function () {
        // `User` e `Tenant` são globais. Se o escopo fosse registrado em todo
        // model, a consulta a eles quebraria com "column not found".
        expect(class_uses_recursive(Tenant::class))
            ->not->toContain(TenantScopedModel::class);

        User::factory()->create();

        expect(User::query()->pluck('id'))->not->toBeEmpty();
    });
});
