<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * RLS em `memberships`: a segunda camada do isolamento entre barbearias.
 *
 * A primeira camada é o código — `TenantScope` e `MembershipPolicy`. Ela erra
 * de um jeito específico: alguém escreve a query sem o filtro e ela passa. A
 * segunda camada não depende de ninguém lembrar de nada, porque a policy é do
 * banco e vale para toda query, inclusive a que ninguém testou.
 *
 * ## O tenant chega por GUC, não por parâmetro
 *
 * A policy não recebe o tenant por argumento — Postgres não tem como amarrar
 * um predicado a um valor vindo do PHP sem que a aplicação o injete em algum
 * lugar consultável. O lugar é a *customized option* `app.current_tenant`,
 * lida por `app.current_tenant_id()`.
 *
 * ## `SET LOCAL`, e por que a transação não é opcional
 *
 * O contexto é escrito com `SET LOCAL`, cujo escopo é a transação: o
 * `COMMIT`/`ROLLBACK` limpa sozinho. Isso é o que torna a aplicação segura com
 * connection pool — uma conexão devolvida ao pool nunca carrega barbearia de
 * outro.
 *
 * Experimentalmente (PG 16): `set_config(..., true)` **fora** de transação
 * tem efeito e persiste na sessão. A transação não é otimização, é a garantia
 * de limpeza — e por isso o middleware `SetTenantDatabaseContext` abre uma.
 *
 * ## `FORCE ROW LEVEL SECURITY`
 *
 * Sem `FORCE`, o dono da tabela escapa da própria policy. Como é o dono quem
 * roda a migration, as policies seriam letra morta em qualquer ambiente em que
 * a aplicação conecte com o mesmo usuário que migrou. `FORCE` fecha a saída —
 * e continua valendo para `superuser` exceto quando ele é `BYPASSRLS`, o que
 * é outro problema, tratado em `database/sql/app-role.sql`.
 *
 * ## Três furos reais, de propósito
 *
 * 1. `user_id = app.current_user_id()` **só no SELECT**. `ResolveTenant`
 *    precisa listar as próprias memberships antes de existir contexto — é o que
 *    decide a barbearia. Sem essa cláusula o produto inteiro pararia. Ela não
 *    se estende a INSERT/UPDATE/DELETE: ler a si próprio não é escrever no
 *    lugar alheio.
 * 2. `app.is_superadmin()`. `RegisterBarbershop` cria a barbearia e a
 *    membership do owner a partir de `/admin`, que não tem contexto de tenant
 *    por definição. Sem a cláusula, cadastrar uma barbearia passaria a ser
 *    impossível — e um cadastro quebrado faz o time contornar o RLS, que é pior
 *    que a exceção. O predicado é lido de `users.is_superadmin` no banco, e
 *    não de uma flag que a aplicação declara sobre si mesma.
 * 3. `tenants` **não** entra nesta migration. Ela não tem `tenant_id` — a
 *    pergunta dela é "esta requisição pode ver esta barbearia", que é outra
 *    política, e o superadmin precisa legitimamente de todas. Ver a Sprint 5 do
 *    relatório para o plano e o motivo de adiar.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * `app` é um schema só para as funções de RLS. Deixá-las em `public`
         * misturaria o nome delas com o das tabelas e abriria espaço para
         * alguém recriar `app.current_tenant_id()` com outro corpo depois.
         *
         * O schema é criado aqui por `IF NOT EXISTS` para o caso de banco novo,
         * mas quem o provisiona de fato é `database/sql/app-role.sql` — rodar
         * migration exige `CREATE` no schema `public` e `CREATE` no banco, e
         * nenhum dos dois é privilégio que a aplicação deva carregar.
         */
        DB::statement('CREATE SCHEMA IF NOT EXISTS app');

        /*
         * `current_setting(..., true)` devolve NULL quando a opção nunca foi
         * setada, e string vazia quando um `SET LOCAL` já foi desfeito. Os dois
         * viram NULL aqui, que é o que faz "sem contexto" valer como "sem
         * acesso" em vez de levantar erro de cast.
         */
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION app.current_tenant_id() RETURNS bigint
            LANGUAGE sql STABLE PARALLEL SAFE AS $$
                SELECT nullif(current_setting('app.current_tenant', true), '')::bigint
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION app.current_user_id() RETURNS bigint
            LANGUAGE sql STABLE PARALLEL SAFE AS $$
                SELECT nullif(current_setting('app.current_user', true), '')::bigint
            $$
        SQL);

        /*
         * Superadmin por consulta, não por flag. `users` não tem RLS, então
         * esta subquery não recursa; quando `users` ganhar RLS, é aqui que a
         * recursão vai aparecer — e a saída passa a ser um SECURITY DEFINER
         * com `search_path` fixo.
         */
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION app.is_superadmin() RETURNS boolean
            LANGUAGE sql STABLE AS $$
                SELECT EXISTS (
                    SELECT 1
                    FROM public.users u
                    WHERE u.id = app.current_user_id()
                      AND u.is_superadmin
                )
            $$
        SQL);

        DB::statement('GRANT USAGE ON SCHEMA app TO PUBLIC');

        DB::statement('ALTER TABLE memberships ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE memberships FORCE ROW LEVEL SECURITY');

        /*
         * SELECT leva três alternativas. As duas últimas são os furos
         * documentados no cabeçalho; a primeira é a fronteira de verdade.
         */
        DB::statement(<<<'SQL'
            CREATE POLICY memberships_select ON memberships FOR SELECT USING (
                tenant_id = app.current_tenant_id()
                OR user_id = app.current_user_id()
                OR app.is_superadmin()
            )
        SQL);

        /*
         * `WITH CHECK` vale para a linha **nova**, não só para as vistas. Sem
         * ele dava para pegar uma membership própria e reescrever `tenant_id`
         * para outra barbearia: o `USING` aceitaria a linha antiga, e o
         * registro mudaria de dono. Trocar `tenant_id` é a escrita mais
         * perigosa possível numa tabela de vínculo.
         */
        DB::statement(<<<'SQL'
            CREATE POLICY memberships_insert ON memberships FOR INSERT WITH CHECK (
                tenant_id = app.current_tenant_id()
                OR app.is_superadmin()
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE POLICY memberships_update ON memberships FOR UPDATE
            USING (
                tenant_id = app.current_tenant_id()
                OR app.is_superadmin()
            )
            WITH CHECK (
                tenant_id = app.current_tenant_id()
                OR app.is_superadmin()
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE POLICY memberships_delete ON memberships FOR DELETE USING (
                tenant_id = app.current_tenant_id()
                OR app.is_superadmin()
            )
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP POLICY IF EXISTS memberships_delete ON memberships');
        DB::statement('DROP POLICY IF EXISTS memberships_update ON memberships');
        DB::statement('DROP POLICY IF EXISTS memberships_insert ON memberships');
        DB::statement('DROP POLICY IF EXISTS memberships_select ON memberships');

        DB::statement('ALTER TABLE memberships NO FORCE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE memberships DISABLE ROW LEVEL SECURITY');

        DB::statement('DROP FUNCTION IF EXISTS app.is_superadmin()');
        DB::statement('DROP FUNCTION IF EXISTS app.current_user_id()');
        DB::statement('DROP FUNCTION IF EXISTS app.current_tenant_id()');

        /*
         * `RESTRICT` e não `CASCADE`: derrubar o schema `app` junto derrubaria
         * qualquer coisa que a Sprint 6+ tenha colocado lá, e o rollback desta
         * migration não tem direito de apagar trabalho posterior.
         */
        DB::statement('DROP SCHEMA IF EXISTS app RESTRICT');
    }
};
