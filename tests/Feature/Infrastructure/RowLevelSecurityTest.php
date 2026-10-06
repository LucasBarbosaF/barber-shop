<?php

use App\Domain\Tenant\Enums\MembershipRole;
use App\Domain\Tenant\Models\Membership;
use App\Domain\Tenant\Models\Tenant;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * A segunda camada do isolamento entre barbearias, provada pelo efeito.
 *
 * A primeira camada é o código — `TenantScope` e `MembershipPolicy` — e ela erra
 * de um jeito específico: alguém escreve a query sem o filtro e ela passa. Isso
 * é um bug, não uma falha de segurança, e a suíte de integração pega o caso
 * comum. O que ela não pega é a query que ninguém testou, e é para isso que a
 * policy existe: ela é do banco e vale para toda query, inclusive a que ninguém
 * exercise.
 *
 * ## O que estes testes medem
 *
 * O que o PostgreSQL devolve quando `app.current_tenant` está declarado, quando
 * está vazio e quando aponta para outra barbearia. Cada afirmação vai por
 * `count()`/`pluck()` numa query que a aplicação poderia escrever. A estrutura
 * (`pg_class`, `pg_policies`) é conferida uma vez, no começo, e o resto prova
 * comportamento — `pg_policies` bonito com policy permissiva passa, e é
 * exatamente o que esta suíte existe para pegar.
 *
 * ## Duas propriedades sem as quais nada aqui significa
 *
 * **O papel.** Um papel `SUPERUSER` ou `BYPASSRLS` não é sujeito a policy. Com
 * ele, todas as negações abaixo viram aprovações e a suíte passa a verde sem
 * provar nada — o pior tipo de teste, o que mente. O primeiro teste falha alto
 * nesse caso.
 *
 * **O contexto declarado.** A policy lê a *customized option*
 * `app.current_tenant`. Sem ela o RLS nega tudo, e um teste que espera ver a
 * própria barbearia falha parecendo bug de policy quando o problema é o fixture.
 * Por isso os helpers de `tests/Pest.php` declaram a barbearia nas escritas, e
 * por isso os testes que querem "sem contexto" limpam explicitamente em vez de
 * confiar no estado herdado.
 *
 * ## Um teste passa a ser inútil sem os outros
 *
 * "Sem contexto, a lista volta vazia" é o mesmo resultado de "a tabela está
 * vazia". Por isso os cenários deste arquivo criam as linhas antes de negar, e
 * conferem que elas existem por um caminho permitido. Um teste de negação que não
 * prova a pré-condição não prova nada.
 */
describe('a suite nao roda vazia', function () {
    it('o papel da aplicacao nao e superuser nem bypassrls', function () {
        $role = DB::selectOne(
            'SELECT rolsuper, rolbypassrls FROM pg_roles WHERE rolname = current_user'
        );

        expect($role->rolsuper)->toBeFalse(
            'A suite de RLS conectou como SUPERUSER: nenhuma policy vale para '
            .'superuser, entao todo teste de negacao abaixo estaria medindo nada. '
            .'Rode ./pest-pgsql.sh.'
        );

        expect($role->rolbypassrls)->toBeFalse(
            'O papel tem BYPASSRLS: as policies valem, mas nao sao aplicadas. '
            .'Rode ./pest-pgsql.sh.'
        );
    });

    it('a tabela tem RLS ligado e forcado', function () {
        $table = DB::selectOne(
            "SELECT relrowsecurity, relforcerowsecurity
             FROM pg_class WHERE relname = 'memberships'"
        );

        expect($table->relrowsecurity)->toBeTrue(
            'memberships sem ENABLE ROW LEVEL SECURITY: a policy nao e consultada.'
        );

        /*
         * `FORCE` fecha a saída do dono da tabela. Como é o dono quem roda a
         * migration, sem `FORCE` as policies seriam letra morta em qualquer
         * ambiente em que a aplicação conecte com o mesmo papel que migrou — que é
         * o ambiente de teste, e provavelmente o de produção.
         */
        expect($table->relforcerowsecurity)->toBeTrue(
            'memberships sem FORCE: o dono da tabela escapa da propria policy.'
        );
    });

    it('existe uma policy para cada operacao', function () {
        $policies = DB::select(
            "SELECT policyname, cmd FROM pg_policies
             WHERE tablename = 'memberships' ORDER BY cmd, policyname"
        );

        // Uma tabela sem policy correspondente a um comando nega esse comando
        // para todo mundo. Cobertura parcial — digamos, SELECT sem DELETE — é
        // vazamento silencioso, e nenhuma sintaxe avisa.
        expect($policies)->toHaveCount(4)
            ->and(array_map(fn ($policy) => strtoupper($policy->cmd), $policies))
            ->toBe(['DELETE', 'INSERT', 'SELECT', 'UPDATE']);
    });

    it('o tenant chega por GUC, e nao por parametro da query', function () {
        // Se a policy tivesse o tenant amarrado no texto, trocar de barbearia
        // exigiria reescrever a policy. Este é o contrato com o middleware:
        // `app.current_tenant`, lido por `app.current_tenant_id()`, e nada mais.
        expect(DB::selectOne('SELECT app.current_tenant_id() AS id')->id)->toBeNull();

        $tenant = Tenant::factory()->create();

        actingInTenant($tenant);

        expect(DB::selectOne('SELECT app.current_tenant_id() AS id')->id)
            ->toBe($tenant->getKey());
    });
});

describe('SELECT', function () {
    it('devolve as linhas da barbearia do contexto', function () {
        [$tenantA] = tenantWithUser();
        [$tenantB] = tenantWithUser();

        actingInTenant($tenantA);

        expect(Membership::query()->pluck('tenant_id')->unique()->values()->all())
            ->toBe([$tenantA->getKey()])
            ->not->toContain($tenantB->getKey());
    });

    it('nao devolve nada de outra barbearia, e a linha existe', function () {
        [$tenantA, , $membershipOfA] = tenantWithUser();
        [$tenantB, , $membershipOfB] = tenantWithUser();

        // As linhas existem: cada contexto enxerga a sua. Sem esta conferência,
        // "vazio" e "tabela vazia" são indistinguíveis, e a negação abaixo não
        // diria nada.
        actingInTenant($tenantA);
        expect(Membership::query()->pluck('id')->all())->toBe([$membershipOfA->getKey()]);

        actingInTenant($tenantB);
        expect(Membership::query()->pluck('id')->all())->toBe([$membershipOfB->getKey()]);

        // Agora a pergunta que interessa.
        actingInTenant($tenantA);

        expect(Membership::query()->find($membershipOfB->getKey()))->toBeNull();
        expect(Membership::query()->find($membershipOfA->getKey()))->not->toBeNull();
    });

    it('devolve as proprias memberships sem contexto, para resolver a barbearia', function () {
        [$tenantA, $user, $membershipOfA] = tenantWithUser();

        // A mesma pessoa em outra barbearia: o cenário que `ResolveTenant` existe
        // para resolver.
        $otherTenant = Tenant::factory()->create();
        $membershipOfOther = joinsTenant($user, $otherTenant);

        actingWithoutTenant();
        actingAsUser($user);

        /*
         * O furo que a migration documenta. Sem ele o produto inteiro pararia:
         * `ResolveTenant` precisa listar as próprias memberships antes de existir
         * contexto, porque listá-las é justamente o que decide a barbearia.
         */
        expect(Membership::query()->pluck('id')->all())
            ->toBe([$membershipOfA->getKey(), $membershipOfOther->getKey()]);
    });

    it('o furo de ler a si proprio nao vaza a barbearia alheia de outra pessoa', function () {
        [, , $membershipOfA] = tenantWithUser();
        [, $userOfB, $membershipOfB] = tenantWithUser();

        actingWithoutTenant();
        actingAsUser($userOfB);

        // O usuário de B enxerga o próprio vínculo — e nada de A. O furo é por
        // `user_id`, e `user_id = app.current_user_id()` não casa com a linha de
        // outra pessoa.
        expect(Membership::query()->pluck('id')->all())
            ->toBe([$membershipOfB->getKey()])
            ->not->toContain($membershipOfA->getKey());
    });

    it('nega tudo sem contexto', function () {
        tenantWithUser();
        tenantWithUser();

        actingWithoutTenant();

        expect(Membership::query()->count())->toBe(0);
    });

    it('nega tudo com um contexto que nao existe', function () {
        [$tenantA] = tenantWithUser();

        // Um id de barbearia inexistente é o pior caso para um `WHERE` mal
        // escrito: `tenant_id = 999999` não casa nada na policy, e a query volta
        // vazia em vez de barulhenta. Vazio é a resposta correta.
        setDatabaseTenantId(999999);

        expect(Membership::query()->count())->toBe(0);

        // E o contexto válido volta a funcionar: a negação foi do id, não do
        // mecanismo.
        actingInTenant($tenantA);
        expect(Membership::query()->count())->toBe(1);
    });

    it('o superadmin enxerga todas, sem contexto', function () {
        [, , $membershipOfA] = tenantWithUser();
        [, , $membershipOfB] = tenantWithUser();

        actingWithoutTenant();
        actingAsUser(User::factory()->superadmin()->create());

        // A exceção que permite cadastrar uma barbearia: `RegisterBarbershop`
        // cria a barbearia e a membership do owner a partir de `/admin`, que não
        // tem contexto de tenant por definição. Sem esta cláusula, cadastrar
        // passaria a ser impossível — e um cadastro quebrado faz o time
        // contornar o RLS, que é pior que a exceção.
        expect(Membership::query()->pluck('id')->all())
            ->toContain($membershipOfA->getKey())
            ->toContain($membershipOfB->getKey());
    });

    it('a Bandeira de superadmin vem do banco, e nao de uma flag da aplicacao', function () {
        // Se `app.is_superadmin()` lessse um GUC, bastaria um `set_config` para
        // virar superadmin. A função lê `users.is_superadmin` no banco, e
        // `users` não tem RLS — então o único caminho é o INSERT, que depende de
        // privilégio de escrita na tabela.
        actingAsUser(User::factory()->superadmin()->create());
        expect(DB::selectOne('SELECT app.is_superadmin() AS flag')->flag)->toBeTrue();

        [, , $membership] = tenantWithUser();
        actingAsUser($membership->user);
        expect(DB::selectOne('SELECT app.is_superadmin() AS flag')->flag)->toBeFalse();

        setDatabaseUserId(null);
        expect(DB::selectOne('SELECT app.is_superadmin() AS flag')->flag)->toBeFalse();
    });
});

describe('INSERT', function () {
    it('aceita dentro da barbearia do contexto', function () {
        [$tenantA] = tenantWithUser();
        $user = User::factory()->create();

        actingInTenant($tenantA);

        $membership = Membership::query()->create([
            'user_id' => $user->getKey(),
            'tenant_id' => $tenantA->getKey(),
            'role' => MembershipRole::Receptionist,
        ]);

        expect($membership->exists)->toBeTrue();
    });

    it('recusa em outra barbearia, e a barra e de privilegio', function () {
        [$tenantA] = tenantWithUser();
        $tenantB = Tenant::factory()->create();
        $user = User::factory()->create();

        actingInTenant($tenantA);

        $exception = failedWrite(fn () => Membership::query()->create([
            'user_id' => $user->getKey(),
            'tenant_id' => $tenantB->getKey(),
            'role' => MembershipRole::Receptionist,
        ]));

        /*
         * INSERT é o único dos quatro que **levanta erro** em vez de afetar zero
         * linhas. `WITH CHECK` é avaliado na linha nova, e a recusa sai como
         * `42501 insufficient_privilege`.
         *
         * A assimetria com UPDATE e DELETE é do SQL, não escolha: uma linha que
         * não passa no CHECK não existe, e não há "afetadas" para reportar. Ver
         * as seções seguintes.
         */
        expect($exception)->toBeInstanceOf(QueryException::class)
            ->and($exception->getCode())->toBe('42501');

        // A transação segue servível: `failedWrite` usou savepoint.
        actingInTenant($tenantA);
        expect(Membership::query()->count())->toBe(1);
    });

    it('recusa sem contexto', function () {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create();

        actingWithoutTenant();

        $exception = failedWrite(fn () => Membership::query()->create([
            'user_id' => $user->getKey(),
            'tenant_id' => $tenant->getKey(),
            'role' => MembershipRole::Receptionist,
        ]));

        expect($exception)->toBeInstanceOf(QueryException::class)
            ->and($exception->getCode())->toBe('42501');
    });
});

describe('UPDATE', function () {
    it('altera dentro da barbearia do contexto', function () {
        [$tenantA, , $membership] = tenantWithUser(MembershipRole::Barber);

        actingInTenant($tenantA);

        expect(Membership::query()
            ->whereKey($membership->getKey())
            ->update(['role' => MembershipRole::Admin->value])
        )->toBe(1);

        expect(Membership::query()->whereKey($membership->getKey())->value('role'))
            ->toBe(MembershipRole::Admin);
    });

    it('afeta zero linhas em outra barbearia, sem levantar erro', function () {
        [$tenantA] = tenantWithUser();
        [$tenantB, , $membershipOfB] = tenantWithUser();

        actingInTenant($tenantA);

        /*
         * A assimetria que mais custa tempo de quem escreve teste: UPDATE e
         * DELETE em linha invisível **não falham**. A policy `USING` não casa, o
         * banco afeta zero linhas e o Eloquent devolve `0` calado.
         *
         * O código de produção precisa conferir o retorno. Um `update()` cujo
         * resultado ninguém guarda é um "deu certo" que não aconteceu, e a falha
         * aparece depois, em outro lugar. Já `first()` antes do `update()` levanta
         * `ModelNotFoundException` — é o que a rota `show` usa, e por isso ela
         * responde 404.
         */
        $affected = Membership::query()
            ->whereKey($membershipOfB->getKey())
            ->update(['role' => MembershipRole::Admin->value]);

        expect($affected)->toBe(0);

        // A linha segue intacta, provada pelo contexto que a enxerga.
        actingInTenant($tenantB);

        expect(Membership::query()->whereKey($membershipOfB->getKey())->value('role'))
            ->toBe($membershipOfB->role);
    });

    it('recusa mudar o dono da linha para outra barbearia', function () {
        [$tenantA, , $membershipOfA] = tenantWithUser();
        $tenantB = Tenant::factory()->create();

        actingInTenant($tenantA);

        /*
         * `WITH CHECK` vale para a linha **nova**, não só para as vistas.
         *
         * Sem ele, dava para pegar uma membership própria — que a `USING` aceita —
         * e reescrever `tenant_id` para outra barbearia: a linha antiga passaria,
         * o registro mudaria de dono e nada apareceria. Trocar `tenant_id` é a
         * escrita mais perigosa possível numa tabela de vínculo: ela decide de
         * quem é a equipe.
         */
        $exception = failedWrite(fn () => Membership::query()
            ->whereKey($membershipOfA->getKey())
            ->update(['tenant_id' => $tenantB->getKey()])
        );

        expect($exception)->toBeInstanceOf(QueryException::class)
            ->and($exception->getCode())->toBe('42501');

        // E a linha não mudou de dono.
        actingInTenant($tenantA);

        expect(Membership::query()->whereKey($membershipOfA->getKey())->value('tenant_id'))
            ->toBe($tenantA->getKey());
    });

    it('afeta zero linhas sem contexto', function () {
        [, , $membership] = tenantWithUser();

        actingWithoutTenant();

        expect(Membership::query()
            ->whereKey($membership->getKey())
            ->update(['role' => MembershipRole::Admin->value])
        )->toBe(0);
    });
});

describe('DELETE', function () {
    it('apaga dentro da barbearia do contexto', function () {
        [$tenantA, , $membership] = tenantWithUser();

        actingInTenant($tenantA);

        expect(Membership::query()->whereKey($membership->getKey())->delete())->toBe(1);

        actingInTenant($tenantA);
        expect(Membership::query()->count())->toBe(0);
    });

    it('apaga zero linhas em outra barbearia', function () {
        [$tenantA] = tenantWithUser();
        [$tenantB, , $membershipOfB] = tenantWithUser();

        actingInTenant($tenantA);

        expect(Membership::query()->whereKey($membershipOfB->getKey())->delete())->toBe(0);

        // A linha segue lá, provada pelo contexto que a vê.
        actingInTenant($tenantB);

        expect(Membership::query()->whereKey($membershipOfB->getKey())->exists())->toBeTrue();
    });

    it('apaga zero linhas sem contexto', function () {
        [, , $membership] = tenantWithUser();

        actingWithoutTenant();

        expect(Membership::query()->whereKey($membership->getKey())->delete())->toBe(0);
    });
});

describe('o escopo do GUC', function () {
    it('SET LOCAL morre com a transacao, e e por isso que o middleware abre uma', function () {
        [$tenantA] = tenantWithUser();

        /*
         * `SET LOCAL` **fora** de transação explícita tem efeito e persiste na
         * sessão — medido em PG 16. Numa conexão devolvida ao pool, a próxima
         * requisição herdaria a barbearia da anterior.
         *
         * Aqui a transação do `RefreshDatabase` é a que segura o `SET LOCAL`, e o
         * `rollBack()` é o proxy do `COMMIT` do middleware.
         */
        DB::beginTransaction();

        try {
            actingInTenant($tenantA);

            expect(Membership::query()->count())->toBe(1);
        } finally {
            DB::rollBack();
        }

        expect(Membership::query()->count())->toBe(0);
    });

    it('so a transacao mais externa limpa, e um savepoint liberado nao', function () {
        [$tenantA] = tenantWithUser();

        /*
         * Este é o lado que atrapalha quem escreve teste, e vale mais que o
         * anterior: `RELEASE SAVEPOINT` **não** limpa o `SET LOCAL`.
         *
         * O `RefreshDatabase` já abre uma transação, então `beginTransaction()`
         * aqui cria um savepoint, e `commit()` o libera — mantendo o valor. Por
         * isso este teste não consegue observar o `COMMIT` de verdade do
         * middleware, e fingir que consegue daria uma garantia falsa à suíte.
         *
         * O que dá para afirmar aqui é o que importa para quem escreve teste
         * em cima do middleware: rollback desfaz e volta ao valor anterior,
         * commit de savepoint mantém. Se um teste depende do contexto ter sumido
         * depois de um `DB::commit()` aninhado, ele está medindo o savepoint e
         * não a requisição.
         */
        DB::beginTransaction();

        try {
            actingInTenant($tenantA);

            expect(Membership::query()->count())->toBe(1);
        } finally {
            DB::commit();
        }

        // Liberado o savepoint, o contexto continua lá.
        expect(Membership::query()->count())->toBe(1);

        DB::beginTransaction();

        try {
            actingWithoutTenant();
        } finally {
            DB::rollBack();
        }

        // Rollback restaura o valor de antes do savepoint — que aqui já era o
        // valor default. É a mesma propriedade do teste anterior, vista pelo lado
        // do savepoint.
        actingWithoutTenant();

        expect(Membership::query()->count())->toBe(0);
    });

    it('o papel da aplicacao nao consegue virar superuser', function () {
        // A garantia de `database/sql/app-role.sql` testada pelo lado de dentro:
        // mesmo com senha, `barber_app` não assume um papel que ignore RLS.
        $exception = failedWrite(fn () => DB::select('SET ROLE postgres'));

        expect($exception)->toBeInstanceOf(QueryException::class)
            ->and($exception->getCode())->toBe('42501');
    });
});
