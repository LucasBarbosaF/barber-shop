-- Roles de banco: um para migrar, um para a aplicação.
--
-- Por que dois: o dono da tabela escapa da policy de RLS dela própria, a menos
-- que a tabela esteja com FORCE ROW LEVEL SECURITY. A migration acima liga o
-- FORCE, então a aplicação poderia ser o dono e ainda assim ficar presa ao RLS.
-- Separar os dois é o que mantém a produção em configuração mínima mesmo que
-- alguém esqueça o FORCE numa tabela nova.
--
-- `NOSUPERUSER` e `NOBYPASSRLS` nos dois: superuser e BYPASSRLS ignoram RLS
-- por completo, sem aviso. Um role assim não "tem todas as permissões" — ele
-- simplesmente **não participa** da segunda camada do isolamento.
--
-- Aplicar como superuser:
--     psql -U postgres -d barber_saas -v app_password=... -f database/sql/app-role.sql

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'barber_app') THEN
        -- A aplicação. Sem BYPASSRLS de propósito: é este role que o RLS
        -- precisa valer.
        EXECUTE $ddl$
            CREATE ROLE barber_app
                LOGIN
                NOSUPERUSER NOCREATEDB NOCREATEROLE NOBYPASSRLS
        $ddl$;
    END IF;

    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'barber_migrator') THEN
        -- Dono do schema e das tabelas. Só roda migration. Nenhuma policy
        -- precisa conhecer este role: RLS rege o acesso da aplicação, e a
        -- aplicação não é este role.
        EXECUTE $ddl$
            CREATE ROLE barber_migrator
                LOGIN
                NOSUPERUSER NOCREATEDB NOCREATEROLE NOBYPASSRLS
        $ddl$;
    END IF;
END
$$;

-- Senha fora do versionamento: vem de psql -v, ou de PGPASSWORD no ambiente.
DO $$
BEGIN
    IF current_setting('app.app_password', true) IS NULL THEN
        RAISE NOTICE 'app.app_password não setada: os roles ficaram sem senha (peer/trust local).';
        RETURN;
    END IF;

    EXECUTE format('ALTER ROLE barber_app PASSWORD %L', current_setting('app.app_password', true));
END
$$;

-- O dono do schema é quem roda `artisan migrate`, e só ele.
ALTER SCHEMA public OWNER TO barber_migrator;

-- Aplicação: DML. Nem DDL, nem GRANT, nem CREATE.
GRANT USAGE ON SCHEMA public TO barber_app;
GRANT USAGE ON SCHEMA app TO barber_app;
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO barber_app;
GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO barber_app;
GRANT EXECUTE ON ALL FUNCTIONS IN SCHEMA app TO barber_app;

-- Tabela que entra depois desta migration já nasce com a mesma permissão.
ALTER DEFAULT PRIVILEGES FOR ROLE barber_migrator IN SCHEMA public
    GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO barber_app;
ALTER DEFAULT PRIVILEGES FOR ROLE barber_migrator IN SCHEMA public
    GRANT USAGE, SELECT ON SEQUENCES TO barber_app;
