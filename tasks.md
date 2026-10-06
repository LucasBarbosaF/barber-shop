# Sprint 0.1 — Fundação Laravel

- [x] Definir PHP 8.4+ (PHP 8.4.26 via Docker `php:8.4-fpm`)
- [x] Criar Laravel 12+ (Laravel 13.x, satisfaz "Laravel 12+")
- [x] Configurar PostgreSQL (postgres:17-alpine, host `postgres`, porta host 5433)
- [x] Configurar Redis (redis:7-alpine, phpredis, sessions/queue/cache em dev)
- [x] Configurar Livewire (`livewire/livewire` v4, rotas auto)
- [x] Configurar Tailwind (`@tailwindcss/vite` + `resources/css/app.css`; layouts Blade auto-contidos até build de assets)
- [x] Definir uso pontual de Bootstrap (somente quando necessário; stack atual é Tailwind-first)
- [x] Configurar Pint (`composer pint`, 63 arquivos PASS)
- [x] Configurar Pest/PHPUnit (Pest v4, sqlite `:memory:`, 38 testes PASS — 30 funcionais + 8 de arquitetura)
- [x] Configurar Larastan/PHPStan (level 5, 0 erros)
- [x] Configurar Docker + Nginx + PHP-FPM (`docker compose up` validado; health `/up` 200; login/forgot-password 200; registro público desativado)
- [x] Configurar Queue (`queue` worker com Redis)
- [x] Configurar Scheduler (`scheduler` loop `schedule:run`)
- [x] Definir estrutura Domain/Application/Infrastructure (scaffolding criado em `app/Domain`, `app/Application`, `app/Infrastructure`, `app/Policies`)
- [x] Definir padrões de Actions/Use Cases, Policies, Jobs e Events (`Action` base, `BasePolicy`, `Event`/`Job` base, `Money` value object com bcmath)

Notas da entrega:
- Autenticação Fortify registrada com views custom (`auth/*`) e bindings em `FortifyServiceProvider`
- Registro público **desativado** (`config/fortify.php`): tenants nascem só em `/admin/barbearias`
- Fluxos cobertos por Pest: login, logout, troca de senha obrigatória, isolamento do painel admin
- Docker: host ports evitam conflito (`HOST_DB_PORT=5433`, `HOST_REDIS_PORT=6380`)
- Validação: `composer test|pint|stan` + HTTP em `http://localhost:8080`
- Pendente de ambiente: `.env` aponta `DB_HOST=127.0.0.1`, inacessível do container. Alinhar antes de
  rodar `make migrate` e `php artisan db:seed` no Postgres real.

---

# Tasks — Barber SaaS / Multi-tenancy

> **Correção de premissa (Sprint 0, 2026-10-02):** a documentação afirmava que o backend já
> possuía *Authentication + RBAC*. A auditoria do código mostrou que **Authentication existe**
> (Fortify + sessão, funcionando) e que **RBAC não existe**. Detalhes e evidências em
> `docs/sprint-0-discovery.md`.
>
> Consequência: a Sprint 1 deixa de *validar* RBAC e passa a **criar** a camada de autorização.

---

# Sprint 0 — Discovery do backend existente

**Relatório:** `docs/sprint-0-discovery.md`
**Veredito:** Authentication preservável; RBAC inexistente; Domain acoplado ao ORM.

## Authentication
- [x] Mapear login/logout — Fortify, guard `web`, sessão; sem token
- [x] Identificar JWT/session — sessão; JWT inexistente
- [x] Identificar refresh e expiração — refresh inexistente; expiração por `config/session.php`
- [x] Identificar password hashing — bcrypt (padrão; sem `config/hashing.php`)
- [x] Identificar contexto de autenticação do Laravel e principal — `App\Models\User` via guard `web`
- [x] Identificar Security Filters — aliases `superadmin` e `password.set` em `bootstrap/app.php`

## RBAC
- [x] Mapear roles — `MembershipRole` (owner/manager/barber/receptionist), escopado por tenant
- [x] Mapear authorities — inexistente; nenhum modelo, tabela ou trait de permissão
- [x] Mapear `@PreAuthorize` — inexistente (conceito Spring); equivalente Laravel também vazio
- [x] Mapear endpoints protegidos — 6 rotas; ver seção 2.6 do relatório
- [x] Identificar regras hardcoded — `EnsureSuperadmin`, `EnsurePasswordIsSet`, `hasRoleInAnyTenant()`

## Arquitetura
- [x] Mapear entities — `Tenant`, `Membership` (Eloquent em Domain), `User`, `Money`
- [x] Mapear repositories — `App\Domain\Shared\Contracts\Repository` vazia e sem implementações
- [x] Mapear services/use cases — `RegisterBarbershop` é o único use case real
- [x] Mapear controllers — `BarbershopController`, `PasswordSetupController`
- [x] Mapear Laravel Migrations migrations — 7 arquivos; inventário no relatório
- [x] Identificar dependências entre módulos — grafo sem ciclos; Presentation → Application → Domain

## Dívidas registradas
- [x] A-01 Domain acoplado ao ORM (`Tenant`, `Membership` estendem `Model`)
- [x] A-02 Use case depende de facades e de models Eloquent
- [x] A-03 `Repository` vazio; fluxo não passa por repository
- [x] S-01 `hasRoleInAnyTenant()` ignora tenant corrente (bypass cross-tenant)
- [x] D-01 Hash duplo da senha do owner
- [x] F-01 Login sem rate limiting
- [x] F-02 Sanctum instalado e inerte, com `expiration => null`
- [x] O-01 `TenantContext`, `LogContext` e `BasePolicy` sem callers
- [x] O-02 CSS inline vs. diretriz Tailwind-first

## Fronteiras travadas
- [x] Criar `tests/Architecture/BoundariesTest.php` (Pest `arch()` + ratchet)
- [x] Congelar a dívida de persistência do Domain em `{Tenant, Membership}`
- [x] Congelar o conjunto de classes que estendem base de persistência
- [x] Registrar a testsuite `Architecture` em `phpunit.xml`

> Regra do ratchet: a dívida pode **diminuir** (remova a classe do baseline no mesmo commit),
> nunca crescer. Não refatorar nesta sprint.

---

# Sprint 1 — Criar Authorization (RBAC)

Objetivo: construir a camada de autorização sobre a autenticação existente.
Referência: seção 7 do relatório de discovery.

**Relatório:** `docs/sprint-1-authorization.md`

> Decisões de design resolvidas (antes de codar):
> 1. Roles → **6** (`Admin`, `Manager`, `Supervisor`, `Barber`, `Receptionist`, `Financeiro`).
>    Ampliar em vez de sobrecarregar o `Admin` com recepção e financeiro.
> 2. Permissions → **matriz constante** permission→role, sem tabela.
> 3. Granularidade → **por recurso** (`customers.view`).
> 4. Mecanismo → **Gate para ação, Policy para recurso**; as duas portas usam
>    `PermissionChecker`, único ponto de decisão.

## Authentication — preservar e cobrir
- [x] Testar login válido
- [x] Testar login inválido
- [x] Testar sessão válida
- [x] Testar sessão inválida / expirada
- [x] Testar logout
- [x] Testar rate limiting de login (F-01)
- [x] Corrigir hash duplo da senha do owner (D-01) e cobrir
- [x] Validar redirecionamento de senha provisória
- [x] Validar `superadmin` obrigatório em `/admin/*`
- [x] Confirmar que `config/sanctum.php` não expõe tokens sem expiração (F-02)

## RBAC — criar
- [x] Definir catálogo de permissions
- [x] Definir matriz role → permission
- [x] Implementar o mecanismo escolhido
- [x] Registrar Gates e/ou Policies
- [x] Documentar a regra de autorização
- [x] Testar cada role × permission
- [x] Testar endpoint sem permission (403)
- [x] Testar endpoint sem autenticação (401)
- [x] Testar privilege escalation
- [x] Testar alteração indevida de role
- [x] Garantir que `hasRoleInAnyTenant()` não é usado para autorizar (S-01)

## Arquitetura
- [x] Rodar `tests/Architecture/BoundariesTest.php` no `composer check`
- [x] Manter A-01/A-02 documentadas sem refatorar

## Entrega
- [x] Relatório de validação da autorização
- [x] Testes de regressão de autorização

Notas da entrega:
- `Owner` foi renomeado para `Admin` no código e nas views; o payload HTTP manteve
  `owner_name`/`owner_email` para não quebrar o formulário já publicado.
- **D-01 e F-01 eram falsos positivos do discovery.** O cast `hashed` não re-hasheia valor já
  hasheado (o `Hash::make()` explícito era código morto), e o limiter `login` existe e responde
  429. Ambos foram refutados por teste, não por leitura. Detalhes na seção 5 do relatório.
- Decisão de segurança: **superadmin não passa por Gate tenant-scoped**. Ele não pertence a nenhuma
  barbearia; seu acesso é do middleware `superadmin`. Sem tenant no contexto, tudo é negado.
- `hasRoleInAnyTenant()` continua existindo (S-01) mas não participa de nenhuma decisão; a remoção
  é da Sprint 3, junto com o `TenantResolver`.
- `TenantContext` ainda é preenchido à mão nos testes — o `TenantResolver` é da Sprint 3.
- Fica registrado, sem corrigir: o login bem-sucedido **não** zera o contador do limiter nesta
  versão do Fortify. É decisão de produto, não bug.

> Não alterar a Authentication existente: ela está correta e deve ser preservada.

---

# Sprint 2 — Tenant e Membership

> A camada de dados (tabelas, FKs, unique constraints, índices) e a regra de role por tenant já
> foram entregues antes desta sprint. Confirmar item a item; o que falta é a validação.

**Relatório:** `docs/sprint-2-tenant-membership.md`

## Database
- [x] Criar `tenants`
- [x] Criar `memberships`
- [x] Definir status do tenant
- [x] Definir slug
- [x] Criar Laravel Migrations migration
- [x] Criar FK
- [x] Criar unique constraints
- [x] Criar indexes

## Regras
- [x] Usuário pode pertencer a um tenant
- [x] Usuário pode pertencer a vários tenants
- [x] Membership pode ser desativada
- [x] Tenant pode ser desativado
- [x] Impedir membership duplicada

## RBAC
- [x] Reutilizar as roles criadas na Sprint 1
- [x] Não duplicar roles
- [x] Definir relação role/membership
- [x] Documentar regra

## Validação
- [x] Testar unique `(user_id, tenant_id)`
- [x] Testar cascade ao excluir usuário
- [x] Testar cascade ao excluir tenant
- [x] Testar FK com tenant inexistente
- [x] Testar slug único e geração com sufixo
- [x] Testar índices `(tenant_id, role)` e `is_active`
- [x] Testar que desativar preserva o histórico
- [x] Testar que a role é do vínculo, não do usuário

Prova: `tests/Feature/Infrastructure/TenantMembershipSchemaTest.php` (17 testes).

Notas da entrega:
- A camada de dados já existia; o que faltava era **prova**. Nenhum teste cobria unique, FK ou
  cascade — os checkboxes estavam marcados por leitura da migration, não por comportamento.
- `role` é coluna de texto (32) e quem valida é o cast do modelo (`ValueError` ao ler valor fora do
  enum). **Decisão deliberada:** não criar CHECK no banco, para não obrigar migration a cada role
  nova — coerente com a Sprint 1 ("catálogo muda por deploy"). Risco registrado: escrita direta no
  banco insere role inválida e quebra na leitura; todas as escritas passam pelo modelo.
- Isolamento entre barbearias e negação por tenant desativado são provados na Sprint 1
  (`PermissionGateTest`, `ProtectedRouteTest`).

---

# Sprint 3 — TenantContext

**Relatório:** `docs/sprint-3-tenant-context.md`

- [x] Criar `TenantContext`
- [x] Criar `TenantResolver`
- [x] Integrar com autenticação/middleware do Laravel
- [x] Resolver usuário autenticado
- [x] Resolver membership
- [x] Validar tenant
- [x] Definir tenant na requisição
- [x] Limpar contexto ao final

## Segurança
- [x] Não confiar em `tenant_id` do frontend
- [x] Validar membership
- [x] Validar tenant ativo
- [x] Rejeitar tenant inexistente
- [x] Rejeitar membership inativa

## Seleção
- [x] Seleção automática para usuário com um tenant
- [x] Seleção explícita para usuário com vários tenants
- [x] Avaliar subdomínio

Decisões tomadas antes de codar:
- **Sessão agora, subdomínio depois.** A sessão é a única fonte do tenant nesta sprint; subdomínio
  entra com o site público (Sprint 19), que exige DNS e certificado próprios. Subdomínio hoje
  quebraria o dev local sem ganho: ninguém tem URL pública ainda.
- **Troca de tenant entra nesta sprint**, com validação de membership e sessão regenerada.

Notas da entrega:
- A sessão é **revalidada, nunca obedecida**: se `current_tenant_id` aponta para barbearia que a
  pessoa não pode mais acessar (membership revogada, barbearia desativada, sessão forjada), o
  contexto é descartado. Sem isso, plantar a chave na sessão bastaria para escrever na barbearia
  alheia — o teste `descarta tenant plantado na sessao` prova exatamente isso.
- **Mais de uma barbearia não escolhe sozinho.** Adotar a "primeira" seria inventar autorização
  que ninguém concedeu; o middleware deixa a pessoa sem contexto e a rota tenant-scoped nega com
  403 até a escolha ser feita.
- `tenant` (middleware bruto) e `tenant.selected` (exigência) são separados de propósito: a troca
  de tenant e a tela de seleção precisam funcionar justamente para quem ainda não escolheu.
- `PermissionChecker::activeTenantIds()` foi exposto para o resolver reaproveitar a mesma regra de
  "membership ativa em barbearia ativa" — duplicar essa consulta criaria dois lugares decidindo.
- Superadmin não tem atalho: sem membership, ele não resolve tenant nem troca de barbearia.
- A tela de seleção em si é da Sprint 15; o que ela precisa (`TenantResolution` na request) já
  está pronto e testado.

---

# Sprint 4 — Integrar Authentication + RBAC + Tenant

Fluxo:

```text
Request
  ↓
Authentication (sessão — não há JWT)
  ↓
User
  ↓
Membership
  ↓
TenantContext
  ↓
RBAC (criado na Sprint 1)
  ↓
Use Case
  ↓
Resource
```

- [x] Integrar TenantResolver ao fluxo atual
- [x] Preservar autenticação por sessão (não introduzir JWT)
- [x] Reusar o RBAC da Sprint 1, sem duplicar regras
- [x] Validar role dentro do tenant
- [x] Validar acesso ao recurso
- [x] Padronizar 401
- [x] Padronizar 403
- [x] Testar usuário com um tenant
- [x] Testar usuário com múltiplos tenants
- [x] Testar usuário sem membership

**Relatório:** `docs/sprint-4-integration.md`

Notas da entrega:
- O pipeline virou um **grupo de middleware** (`authenticated` = `Authenticate` + `ResolveTenant`) em
  `bootstrap/app.php`, e não um alias opcional por rota. Dependendo de cada grupo se lembrar, a
  primeira rota nova sem o alias autorizaria contra um `TenantContext` vazio — o Gate nega, o que é
  seguro, mas aparece como 403 em tela em vez de como pipeline faltando.
- O alias `tenant` foi removido por ser redundante com o grupo. `tenant.selected` continua separado e
  nomeado: ele exige **escolha** e por isso não pode entrar no grupo, que precisa valer justamente
  para a troca de barbearia.
- `ResolveTenant` só roda com sessão (`hasSession`). Token de API não tem `current_tenant_id`; resolver
  mesmo assim produziria um "escolha uma barbearia" falso em toda chamada stateless. Tenant por token
  fica para a Sprint 20.
- **401 e 403 padronizados** em `App\Http\Responses`, registrados como callback em `withExceptions()`:
  valem para qualquer `AuthenticationException`/`AuthorizationException`/`abort(403)`, sem caminho de
  negação com resposta própria. JSON nos dois é `{"message": ...}` — um cliente trata erro de
  autenticação e de autorização lendo o mesmo campo.
- `BasePolicy` passou a retornar `bool|Response`. Devolvendo `false`, o Gate monta a exceção com o
  texto padrão do framework — "This action is unauthorized", em inglês e genérico demais para quem
  está na tela. Agora a recusa diz o motivo, e a Policy continua sem revelar *de qual* barbearia é o
  registro recusado (seria um oráculo de existência, id por id).
  > **Supersedido pela Sprint 5:** o 403 para registro cross-tenant virou 404, porque o RLS não devolve a
  > linha e o binding não acha. O argumento do oráculo continua válido — e agora está satisfeito de vez,
  > já que id alheio e id inexistente dão a mesma resposta. Ver `docs/sprint-5-rls.md`.
- `TenantScopedModel` ganhou escopo global e preenchimento automático de `tenant_id`, entregues pela
  Sprint 3 como handoff. `Membership` **não** estende a base: `App\Domain` não pode depender de
  `App\Infrastructure` (regra do `BoundariesTest`), e quem consulta membership usa
  `TenantScope::for()`.
- `forTenant()` **soma** ao escopo global em vez de substituí-lo — só restringe, nunca amplia. O
  caminho para alcançar outra barbearia a partir de um contexto alheio é `withoutGlobalScope()`, que
  fica visível na chamada.
- Três ratchets novos no `BoundariesTest` congelam quem pode ler a matriz `RolePermissions`, quem pode
  resolver `Permission::` e qual middleware pode ler o `TenantContext`. Autorização reimplementada por
  baixo do `PermissionChecker` é o modo como a Sprint 1 se degrada em silêncio.
- `Controller` passou a usar `AuthorizesRequests`, para que a Policy seja ponto de decisão dentro do
  controller e não um middleware que a pessoa pode esquecer na rota.

Prova: `tests/Feature/Integration/` (40 testes) + `tests/Architecture/BoundariesTest.php` (11).

---

# Sprint 5 — PostgreSQL RLS

**Relatório:** `docs/sprint-5-rls.md`

## Preparação
- [x] Definir `app.current_tenant`
- [x] Definir estratégia para connection pool
- [x] Definir tenant dentro da transação
- [x] Garantir reset/limpeza

## Policies
- [x] SELECT
- [x] INSERT
- [x] UPDATE
- [x] DELETE

## Testes
- [x] Tenant A lê A
- [x] Tenant A não lê B
- [x] Tenant A altera A
- [x] Tenant A não altera B
- [x] Tenant A exclui A
- [x] Tenant A não exclui B
- [x] Testar conexão sem tenant
- [x] Testar rollback
- [x] Testar connection pooling

Notas da entrega:
- **O PostgreSQL deixou de ser opcional.** A migration usa `FORCE ROW LEVEL SECURITY` e `set_config()`,
  e nada disso existe em SQLite. `phpunit.xml` passou a exigir `pgsql`; um `./vendor/bin/pest` solto
  agora aponta para `barber_saas_test` (o nome ficou pinado de propósito — sem ele, o `RefreshDatabase`
  faria `migrate:fresh` no `barber_saas` do `.env`).
- **A suíte só vale com um papel que não ignora RLS.** `SUPERUSER` e `BYPASSRLS` não são sujeitos a
  policy, então toda negação viraria aprovação e a suíte passaria verde sem provar nada. O `.env` local
  apontava justamente para `postgres`, que é superuser. `./pest-pgsql.sh` monta `barber_app`
  (`NOSUPERUSER NOBYPASSRLS`), e `RowLevelSecurityTest > a suite nao roda vazia` falha alto se o papel
  ignorar RLS — foi verificado: com `postgres` conectado, 16 dos 25 testes falham.
- **`SET LOCAL` + transação explícita** é a estratégia de pool. Medido em PG 16: `set_config(..., true)`
  **fora** de transação tem efeito e persiste na sessão — com pool, a requisição seguinte herdaria a
  barbearia da anterior. É por isso que `SetTenantDatabaseContext` abre transação em vez de confiar em
  limpeza implícita.
- **A ordem dos middlewares virou requisito, não detalhe.** `SubstituteBindings` roda antes do grupo
  `authenticated` por padrão, e o binding consultava `memberships` com `app.current_tenant` vazio: o
  RLS escondia a linha e **toda** rota `show()` tenant-scoped respondia 404, inclusive para a membership
  da própria barbearia. Não era brecha, era a página quebrada. `SetUserDatabaseContext`, `ResolveTenant`,
  `SetTenantDatabaseContext`, `EnsureTenantSelected` e `EnsurePermission` entraram na *prioridade* de
  middleware (`bootstrap/app.php`), antes de `SubstituteBindings`.
- **`EnsureTenantSelected` vem antes de `EnsurePermission`.** A permission é daquela barbearia, e sem
  barbearia escolhida não há o que avaliar. Invertido, o Gate recusa por falta de contexto e a pessoa lê
  "Você não tem permissão para esta ação" quando o problema era não ter escolhido uma barbearia.
- **403 virou 404 para recurso cross-tenant — e isso é correção, não regressão.** A Sprint 4 recusava o
  404 porque "403 aqui, 404 ali" reconstrói a base alheia id por id. O argumento seguia certo, mas o 403
  não resolvia: o oráculo estava no par de respostas. Com RLS, id de outra barbearia e id inexistente
  dão o **mesmo** 404, e não há par para comparar. A regra que sobra: 403 quando a decisão é sobre quem
  pede; 404 quando o registro em si é invisível. Os testes fixam os dois lados lado a lado para que
  reintroduzir o 403 pareça escolha, não detalhe.
- **INSERT levanta erro; UPDATE e DELETE não.** Com `WITH CHECK`, uma linha que não passa não existe e a
  recusa sai como `42501`. Em UPDATE/DELETE de linha invisível a policy `USING` simplesmente não casa: o
  banco afeta **zero linhas** e o Eloquent devolve `0` calado. Código que ignora o retorno do `update()`
  acha que gravou. `RowLevelSecurityTest` fixa a assimetria, e `failedWrite()` usa savepoint porque a
  violação aborta a transação (`25P02`).
- **Cascade de FK ignora RLS.** `DELETE FROM users` apaga memberships sem contexto nenhum, porque a
  verificação do RLS não acontece nas ações referenciais. Fica registrado como dívida conhecida em
  `TenantMembershipSchemaTest`; qualquer rota de exclusão de usuário precisa de RBAC próprio.
- **Três furos deliberados na policy de SELECT:** `user_id = app.current_user_id()` (sem ele o produto
  para — `ResolveTenant` precisa listar as próprias memberships para decidir a barbearia) e
  `app.is_superadmin()` (sem ele cadastrar uma barbearia fica impossível, e cadastro quebrado faz o time
  contornar o RLS). O primeiro **não** se estende a INSERT/UPDATE/DELETE; o superadmin é lido de
  `users.is_superadmin` no banco, nunca de flag declarada pela aplicação.
- **`tenants` ficou de fora.** Não tem `tenant_id`; a pergunta dela é "esta requisição pode ver esta
  barbearia", que é outra política, e o superadmin precisa legitimamente de todas. Planejado para a
  Sprint 6+, junto com `customers`.

Prova: `tests/Feature/Infrastructure/RowLevelSecurityTest.php` (25 testes) +
`tests/Feature/Integration/` (40) + `tests/Feature/Tenant/TenantResolverTest.php` (17) +
`tests/Architecture/BoundariesTest.php` (11).

Suíte completa: 765 passando, 4 falhando, 2 pulando. As 4 falhas são `MoneyTest` e são de ambiente — o
PHP do WSL não tem `bcmath` (`Call to undefined function bcadd()`) e não há sudo para instalar. Não são
regressão desta sprint; em `make check` (container) devem passar.

---

# Sprint 6 — Migrar Customers

- [x] Adicionar `tenant_id`
- [x] Backfill (não aplicável: não existia tabela nem fonte legada de customers)
- [x] FK
- [x] Indexes
- [x] Unique tenant-scoped (`tenant_id`, `phone`)
- [x] RLS
- [x] Atualizar entity
- [x] Atualizar repository
- [x] Atualizar use cases
- [x] Atualizar queries
- [x] Testar isolamento
- [x] Testar IDOR

**Relatório:** `docs/sprint-6-customers.md`

---

# Sprint 7 — Migrar Barbers + Services

## Barbers
- [x] `tenant_id`
- [x] Backfill (não aplicável: não existia tabela nem fonte legada de barbeiros)
- [x] FK
- [x] Index
- [x] RLS
- [x] Repository
- [x] Use cases
- [x] Tests

## Services
- [x] `tenant_id`
- [x] Backfill (não aplicável: não existia tabela nem fonte legada de serviços)
- [x] FK
- [x] Index
- [x] RLS
- [x] Repository
- [x] Use cases
- [x] Tests

**Relatório:** `docs/sprint-7-barbers-services.md`

## Regra adicional de serviços por barbeiro
- [x] Associar um ou mais serviços ao perfil profissional do barbeiro
- [x] Exibir no agendamento público apenas os serviços associados ao barbeiro selecionado
- [x] Validar o vínculo também na consulta de disponibilidade e na criação do agendamento
- [x] Manter os vínculos de barbeiros e serviços isolados por tenant com RLS
- [x] Cadastrar comissão e expediente semanal no mesmo fluxo de criação do barbeiro

---

# Sprint 8 — Migrar Agenda

- [x] BusinessHours
- [x] BlockedPeriod
- [x] Appointment
- [x] Adicionar `tenant_id`
- [x] RLS
- [x] Índices
- [x] Validar customer do mesmo tenant
- [x] Validar barber do mesmo tenant
- [x] Validar service do mesmo tenant
- [x] Validar conflito dentro do tenant
- [x] Testar cross-tenant

**Relatório:** `docs/sprint-8-agenda.md`

---

# Sprint 9 — Migrar Atendimento

- [x] Attendance
- [x] AttendanceItem
- [x] `tenant_id`
- [x] RLS
- [x] Validar relacionamentos tenant-scoped
- [x] Snapshot de preço
- [x] Snapshot de comissão
- [x] Testes

**Relatório:** `docs/sprint-9-atendimento.md`

---

# Sprint 10 — Migrar Pagamentos + Caixa

## Payments
- [x] `tenant_id`
- [x] RLS
- [x] Validar atendimento do mesmo tenant
- [x] Estorno
- [x] Idempotência
- [x] Auditoria

## Cash
- [x] CashRegister
- [x] CashTransaction
- [x] `tenant_id`
- [x] RLS
- [x] Permissões
- [x] Testes

**Relatório:** `docs/sprint-10-payments-cash.md`

---

# Sprint 11 — Migrar Comissões + Repasse

- [x] CommissionRule
- [x] Commission
- [x] CommissionPayment
- [x] `tenant_id`
- [x] RLS
- [x] Snapshot
- [x] Fechamento de período
- [x] Repasse
- [x] Testes

**Relatório:** `docs/sprint-11-commissions-payout.md`

---

# Sprint 12 — Auditoria

- [ ] AuditLog
- [ ] `tenant_id`
- [ ] `user_id`
- [ ] action
- [ ] entity
- [ ] entityId
- [ ] timestamp

## Eventos
- [ ] Login
- [ ] Alteração de role
- [ ] Alteração de preço
- [ ] Alteração de comissão
- [ ] Pagamento
- [ ] Estorno
- [ ] Repasse
- [ ] Membership

---

# Sprint 13 — Testes completos de isolamento

Criar:

```text
Tenant A
 ├── User A
 ├── Customer A
 ├── Barber A
 └── Appointment A

Tenant B
 ├── User B
 ├── Customer B
 ├── Barber B
 └── Appointment B
```

- [ ] A lê A
- [ ] A não lê B
- [ ] A altera A
- [ ] A não altera B
- [ ] A exclui A
- [ ] A não exclui B
- [ ] A agenda A
- [ ] A não agenda B
- [ ] A vê pagamento A
- [ ] A não vê pagamento B
- [ ] A vê comissão A
- [ ] A não vê comissão B
- [ ] B não acessa A
- [ ] Usuário multi-tenant alterna A/B
- [ ] Trocar tenant invalida cache anterior

---

# Sprint 14 — Migração dos dados existentes

## Antes
- [ ] Backup
- [ ] Validar quantidade de registros
- [ ] Identificar empresa/barbearia dos dados atuais
- [ ] Criar tenant inicial
- [ ] Criar memberships

## Migration
- [ ] Backfill `tenant_id`
- [ ] Detectar registros órfãos
- [ ] Validar FKs
- [ ] Validar duplicidades
- [ ] Habilitar RLS

## Depois
- [ ] Comparar contagens
- [ ] Testar login
- [ ] Testar RBAC
- [ ] Testar clientes
- [ ] Testar agenda
- [ ] Testar financeiro
- [ ] Testar relatórios

---

# Sprint 15 — Frontend Multi-tenant

- [ ] Contexto de tenant
- [ ] Seleção de tenant
- [ ] Mostrar barbearia atual
- [x] Mostrar logo/nome no painel e no agendamento público; configurar dados, logo e formas de pagamento por barbearia
- [ ] Trocar tenant
- [ ] Limpar cache ao trocar tenant
- [ ] Resolver tenant no backend
- [ ] Nunca confiar no frontend para autorização

---

# Sprint 16 — Hardening

## Security
- [ ] Revisar Authentication
- [ ] Revisar RBAC
- [ ] Revisar TenantContext
- [ ] Revisar RLS
- [ ] Revisar IDOR
- [ ] Revisar privilege escalation
- [ ] Revisar CORS
- [ ] Revisar rate limiting
- [ ] Revisar secrets
- [ ] Revisar logs

## Database
- [ ] Revisar indexes
- [ ] Revisar FKs
- [ ] Revisar unique constraints
- [ ] Revisar RLS policies
- [ ] Testar connection pooling

---

# Sprint 17 — Observabilidade

- [ ] Correlation ID
- [ ] tenant_id no contexto de logs
- [ ] user_id no contexto de logs
- [ ] Métricas por tenant quando necessário
- [ ] Monitorar 401
- [ ] Monitorar 403
- [ ] Monitorar falhas RLS
- [ ] Monitorar queries lentas
- [ ] Criar alertas

Nunca registrar password, token, refresh token ou secrets.

---

# Sprint 18 — Validação final

## Authentication
- [ ] Login continua funcionando
- [ ] Logout continua funcionando
- [ ] Sessão continua válida e expirando corretamente
- [ ] Senhas continuam com hash correto

## RBAC
- [ ] Roles continuam funcionando
- [ ] Permissions continuam funcionando
- [ ] Endpoints protegidos continuam protegidos

## Multi-tenancy
- [ ] TenantContext funciona
- [ ] Membership funciona
- [ ] RLS funciona
- [ ] Isolamento funciona
- [ ] Usuário multi-tenant funciona

## Negócio
- [ ] Clientes isolados
- [ ] Barbeiros isolados
- [ ] Serviços isolados
- [ ] Agenda isolada
- [ ] Atendimento isolado
- [ ] Pagamentos isolados
- [ ] Caixa isolado
- [ ] Comissões isoladas

---

# Definition of Done

```text
Authentication por sessão (existente, preservada)
        +
RBAC (criado na Sprint 1)
        +
Membership
        +
TenantContext
        +
tenant_id
        +
PostgreSQL RLS
        +
testes de isolamento
        =
Multi-tenancy validado
```

A migração só termina quando não existir nenhum caminho de API, repository, query ou endpoint que permita atravessar a fronteira de um tenant.

## Regra de implementação

```text
Auditar Authentication (feito — Sprint 0)
        ↓
Criar RBAC (Sprint 1)
        ↓
Criar testes de regressão de autorização
        ↓
Tenant/Membership já migrados
        ↓
TenantContext
        ↓
Integrar com o contexto de autenticação do Laravel
        ↓
Implementar RLS
        ↓
Migrar módulos gradualmente
        ↓
Testar isolamento
        ↓
Migrar dados
        ↓
Validar produção
```

**Não reescrever a Authentication existente.** Ela foi auditada na Sprint 0 e está correta.
A Sprint 1 **cria** o RBAC, que não existe.

---

# Sprint 19 — MVP: Site + Agendamento

- [x] Criar site público responsivo por tenant
- [x] Exibir serviços, preços e duração
- [x] Exibir barbeiros
- [x] Consultar disponibilidade
- [x] Selecionar serviço/barbeiro/horário
- [x] Criar agendamento
- [x] Identificar/criar cliente
- [x] Confirmar agendamento
- [ ] Cancelar/remarcar
- [x] Prevenir double booking no backend
- [ ] Registrar origem `WEB`
- [x] Testar isolamento por tenant

# Sprint 20 — MVP: WhatsApp + IA

- [ ] Integrar WhatsApp
- [ ] Configurar e validar webhook
- [ ] Identificar cliente pelo telefone
- [ ] Definir provider/model de IA
- [ ] Criar tools `getServices`, `getBarbers`, `getAvailability`
- [ ] Criar tools `createAppointment`, `cancelAppointment`, `rescheduleAppointment`
- [ ] Criar `getCustomerAppointments`
- [ ] Criar `handoffToHuman`
- [ ] IA nunca acessa DB diretamente
- [ ] Todas as tools usam TenantContext/RBAC
- [ ] Confirmar intenção antes de agendar
- [ ] Validar disponibilidade novamente antes de gravar
- [ ] Registrar auditoria das ações da IA
- [ ] Registrar origem `WHATSAPP`
- [ ] Testar falha da IA e fallback humano

# Sprint 21 — MVP: Painel Web

## Gestão
- [ ] Dashboard
- [ ] CRUD de clientes
- [ ] CRUD de barbeiros
- [ ] CRUD de serviços
- [ ] CRUD de produtos
- [ ] Estoque: entrada/saída/ajuste
- [ ] Agenda diária/semanal
- [ ] Bloqueios
- [ ] Cancelamento/remarcação
- [ ] No-show
- [ ] Encaixe

## Financeiro
- [ ] Vendas
- [ ] Recebimentos
- [ ] Despesas
- [ ] Caixa
- [ ] Fechamento
- [ ] Estornos
- [ ] Relatórios por período

# Sprint 22 — MVP: Atendimento + Pagamento + Caixa

- [ ] Abrir atendimento
- [ ] Associar cliente/barbeiro
- [ ] Adicionar serviços
- [ ] Adicionar produtos
- [ ] Aplicar desconto
- [ ] Fechar atendimento
- [ ] Registrar dinheiro/PIX/cartão
- [ ] Estorno
- [ ] Idempotência
- [ ] Abrir caixa
- [ ] Sangria/suprimento
- [ ] Fechar caixa
- [ ] Registrar todas as movimentações por tenant

# Sprint 23 — MVP: Comissão + Repasse

- [ ] Comissão por barbeiro
- [ ] Comissão por serviço
- [ ] Comissão por produto
- [ ] Percentual/valor fixo
- [ ] Calcular automaticamente no fechamento
- [ ] Salvar snapshot da comissão
- [ ] Fechar período
- [ ] Aprovar repasse
- [ ] Marcar como pago
- [ ] Histórico de repasses
- [ ] Relatório por funcionário
- [ ] Relatório por período
- [ ] Receita/comissão/líquido

# Sprint 24 — MVP: Assinatura SaaS

- [ ] Criar planos
- [ ] Mensal/anual
- [ ] Trial quando aplicável
- [ ] Criar customer no gateway
- [ ] Checkout
- [ ] Criar subscription
- [ ] Endpoint de webhook
- [ ] Validar assinatura do webhook
- [ ] Idempotência dos eventos
- [ ] Persistir evento recebido
- [ ] Atualizar subscription
- [ ] Atualizar status do tenant
- [ ] `TRIAL`
- [ ] `ACTIVE`
- [ ] `PAST_DUE`
- [ ] `CANCELED`
- [ ] `SUSPENDED`
- [ ] Definir período de tolerância
- [ ] Restringir tenant suspenso

# Sprint 25 — MVP: Notificações

- [x] Alerta interno de novo agendamento para o barbeiro (sininho com polling de 1 minuto)

- [ ] Confirmação de agendamento
- [ ] Lembrete
- [ ] Cancelamento
- [ ] Remarcação
- [ ] Confirmação de pagamento
- [ ] Aviso de assinatura
- [ ] Falha de pagamento
- [ ] WhatsApp
- [ ] E-mail

# Sprint 26 — MVP End-to-End

## Jornada Site
- [ ] Cliente escolhe serviço
- [ ] Escolhe barbeiro
- [ ] Escolhe horário
- [ ] Agenda
- [ ] Recebe confirmação
- [ ] Aparece no painel

## Jornada WhatsApp + IA
- [ ] Cliente inicia conversa
- [ ] IA identifica cliente
- [ ] Consulta serviço
- [ ] Consulta disponibilidade
- [ ] Cliente escolhe horário
- [ ] IA agenda
- [ ] Confirmação enviada
- [ ] Aparece no painel

## Jornada Gestão
- [ ] Gestor administra clientes
- [ ] Gestor administra barbeiros
- [ ] Gestor administra serviços
- [ ] Gestor administra produtos
- [ ] Gestor acompanha agenda
- [ ] Gestor acompanha financeiro

## Jornada Comissão
- [ ] Atendimento fechado
- [ ] Comissão calculada
- [ ] Período fechado
- [ ] Repasse realizado
- [ ] Relatório gerado

## Jornada Assinatura
- [ ] Tenant escolhe plano
- [ ] Checkout criado
- [ ] Pagamento realizado
- [ ] Webhook recebido
- [ ] Subscription ativada
- [ ] Tenant fica `ACTIVE`

# Sprint 27 — MVP: Segurança e Go-Live

- [ ] Testar isolamento de todas as jornadas
- [ ] Testar RLS
- [ ] Testar RBAC
- [ ] Testar IDOR
- [ ] Testar webhook e idempotência
- [ ] Testar double booking concorrente
- [ ] Testar rate limiting
- [ ] Backup e restore
- [ ] Logs estruturados
- [ ] tenant_id e user_id no contexto de logs
- [ ] Health checks
- [ ] CI/CD
- [ ] Staging
- [ ] Smoke tests
- [ ] Checklist de produção

# MVP Final — Critério de aceite

```text
SITE
Cliente → Serviço → Barbeiro → Horário → Agendamento

WHATSAPP + IA
Cliente → WhatsApp → IA → Disponibilidade → Agendamento

GESTÃO
Painel → Clientes → Barbeiros → Serviços → Produtos → Financeiro

COMISSÃO
Atendimento → Comissão → Cálculo → Repasse → Relatório

ASSINATURA
Barbearia → Plano → Gateway → Webhook → Subscription
```

**O MVP só está pronto quando as cinco jornadas funcionarem ponta a ponta e estiverem completamente isoladas por tenant.**

---

# Sprint 28 — Laravel Production Architecture

## Qualidade
- [ ] Laravel Pint
- [ ] Larastan/PHPStan
- [ ] Pest
- [ ] CI executando testes
- [ ] CI executando análise estática

## Performance
- [ ] Eager loading
- [ ] Evitar N+1
- [ ] Paginação
- [ ] Índices PostgreSQL
- [ ] Redis Cache quando necessário
- [ ] Queue para tarefas pesadas
- [ ] Cache isolado por tenant

## Segurança
- [ ] Policies
- [ ] Middleware
- [ ] Rate limiting
- [ ] CSRF
- [ ] Validação de entrada
- [ ] Secrets fora do código
- [ ] Webhook signatures
- [ ] Idempotency keys
- [ ] PostgreSQL RLS

## Arquitetura final

```text
Browser
  ↓
Blade + Livewire
  ↓
Laravel
  ↓
Application
  ↓
Domain
  ↓
Eloquent / Infrastructure
  ↓
PostgreSQL + RLS
```

Integrações externas ficam em Infrastructure e são consumidas pelos Use Cases.

**Princípio:** simplicidade operacional primeiro.

---

# Sprint 29 — Template ERP / Layout Administrativo

## Layout
- [x] Criar layout principal do ERP (`resources/views/layouts/app.blade.php`, exposto como `<x-app-layout>`; casca AdminLTE 4 + Bootstrap 5)
- [x] Criar Sidebar (`layouts/partials/sidebar.blade.php`, seções do README, `data-lte-toggle="treeview"`)
- [x] Criar Topbar (`layouts/partials/header.blade.php`, `data-lte-toggle="sidebar"`, dropdown do usuário)
- [x] Criar tela de login no padrão AdminLTE 4
- [x] Direcionar a página inicial para o login quando visitante e para `/app` quando autenticado
- [x] Agrupar Operação, Cadastros, Financeiro, Relatórios, SaaS, Configurações, Equipe e Plataforma em menus recolhíveis
- [ ] Criar Breadcrumb
- [x] Criar área de conteúdo (`main.app-main > .app-content > .container-fluid` + flash de `session('status')`)
- [x] Criar sidebar recolhível (AdminLTE `sidebar-mini` + `sidebar-collapse` no pushmenu)
- [x] Criar menu mobile (off-canvas abaixo de 992px via `sidebar-expand-lg`)
- [ ] Criar perfil do usuário
- [ ] Criar seletor de tenant
- [ ] Criar notificações
- [x] Criar logout (dropdown do header e item "Sair" da sidebar, POST Fortify)

## Design System
- [ ] Button
- [ ] Input
- [ ] Select
- [ ] DatePicker
- [ ] TimePicker
- [ ] Modal
- [ ] Drawer
- [ ] Dropdown
- [ ] Badge
- [ ] Card
- [ ] Table
- [ ] Pagination
- [ ] Tabs
- [ ] Toast
- [ ] Alert
- [ ] EmptyState
- [ ] Loading
- [ ] Avatar
- [ ] StatusBadge
- [ ] Money

## Responsividade
- [ ] Desktop
- [ ] Notebook
- [ ] Tablet
- [ ] Mobile
- [x] Sidebar mobile (off-canvas do AdminLTE abaixo de 992px, alternada pelo pushmenu)
- [x] Tabelas adaptadas (`table-responsive` nas listagens de equipe e barbearias)
- [ ] Agenda adaptada

## RBAC
- [x] Menu respeita permissions (`@can('users.view')` em Equipe; bloco PLATAFORMA só com `isSuperadmin()`; `tests/Feature/Application/AdminTemplateTest.php`)
- [ ] Actions respeitam Policies
- [x] Rotas protegidas (middleware `authenticated` + `permission`; `ProtectedRouteTest`)
- [x] Backend não depende de esconder menu (o 403 vem do Gate/middleware; `PermissionGateTest`, `AccessResponseTest`)

## Assets
- [x] Inputs de Vite do painel (`resources/css/admin.css` e `resources/js/admin.js` em `vite.config.js`)
- [x] Casca sem preflight do Tailwind (theme + utilities por cima do reboot do Bootstrap)
- [x] `Paginator::useBootstrapFive()` para os `links()` renderizarem no idioma do painel
- [x] `withoutVite()` em `tests/TestCase.php` (a suíte não depende de `npm run build`)
- [x] Restyle das pages existentes (dashboard, equipe, barbearias) para as classes da casca, com os mesmos textos dos testes
- [x] `npm run build` validado (admin.css ~399 kB, admin.js ~110 kB)
- [x] Teste do manifest do Vite (só roda quando `/public/build` existe; pula com dev server ligado)

# Sprint 30 — Dashboard ERP

- [ ] Dashboard principal
- [ ] Agendamentos de hoje
- [ ] Atendimentos de hoje
- [ ] Faturamento do dia
- [ ] Comissão do dia
- [ ] Próximos clientes
- [ ] Cancelamentos
- [ ] No-show
- [ ] Barbeiros trabalhando
- [ ] Serviços mais vendidos
- [ ] Produtos mais vendidos
- [ ] Caixa atual
- [ ] Alertas
- [ ] Cards responsivos
- [ ] Dashboard filtrado pelo tenant

# Sprint 31 — Agenda Operacional

## Calendário
- [x] Visão diária
- [ ] Visão semanal
- [x] Visão por barbeiro
- [x] Navegação de datas
- [x] Filtro por barbeiro
- [ ] Filtro por status
- [ ] Filtro por serviço
- [ ] Atualização via Livewire
- [ ] Responsividade

## Agendamento
- [ ] Novo agendamento
- [ ] Selecionar cliente
- [ ] Criar cliente durante agendamento
- [ ] Selecionar serviço
- [ ] Selecionar barbeiro
- [ ] Selecionar data
- [ ] Selecionar horário
- [ ] Validar disponibilidade
- [ ] Validar conflito
- [ ] Confirmar
- [ ] Cancelar
- [ ] Remarcar

## Operação
- [ ] Cliente chegou
- [ ] Iniciar atendimento
- [ ] Finalizar atendimento
- [ ] Marcar no-show
- [ ] Encaixe
- [ ] Visualizar histórico
- [ ] Abrir ficha do cliente

## Status
- [ ] PENDING
- [ ] CONFIRMED
- [ ] ARRIVED
- [ ] IN_SERVICE
- [ ] COMPLETED
- [ ] CANCELED
- [ ] NO_SHOW

# Sprint 32 — Experiência Mobile da Agenda

- [ ] Agenda mobile
- [ ] Lista cronológica do dia
- [ ] Próximo cliente destacado
- [ ] Ações rápidas
- [ ] Marcar chegada rapidamente
- [ ] Iniciar atendimento rapidamente
- [ ] Finalizar atendimento rapidamente
- [ ] Evitar calendário desktop reduzido no celular

# Sprint 33 — Integração do Template com os módulos

- [ ] Clientes no menu
- [ ] Barbeiros no menu
- [ ] Serviços no menu
- [ ] Produtos no menu
- [ ] Agenda no menu
- [ ] Atendimento no menu
- [ ] Caixa no menu
- [ ] Financeiro no menu
- [ ] Comissões no menu
- [ ] Relatórios no menu
- [ ] Assinatura no menu
- [ ] Configurações no menu
- [ ] Aplicar Policies
- [ ] Aplicar TenantContext

# Sprint 34 — UX do fluxo diário

```text
Agenda
  ↓
Cliente
  ↓
Atendimento
  ↓
Serviço/Produto
  ↓
Pagamento
  ↓
Comissão
```

- [ ] Acessar agenda em 1 clique
- [ ] Abrir cliente a partir da agenda
- [ ] Abrir atendimento a partir da agenda
- [ ] Finalizar atendimento
- [ ] Receber pagamento
- [ ] Gerar comissão
- [ ] Voltar para agenda
- [ ] Atualizar próximo cliente
- [ ] Atalhos para ações frequentes
- [ ] Feedback visual
- [ ] Loading states
- [ ] Empty states
- [ ] Toasts
- [ ] Mensagens de erro amigáveis

# Sprint 35 — Aceite visual do MVP

- [ ] Revisar consistência do design system
- [ ] Revisar espaçamentos
- [ ] Revisar tipografia
- [ ] Revisar estados de botão
- [ ] Revisar loading
- [ ] Revisar estados vazios
- [ ] Revisar mensagens de erro
- [ ] Revisar mobile
- [ ] Revisar tablet
- [ ] Revisar desktop
- [ ] Revisar acessibilidade básica
- [ ] Revisar navegação por teclado
- [ ] Revisar contraste
- [ ] Revisar todas as telas do ERP

## Critério

O usuário deve conseguir abrir o painel e, sem treinamento complexo:

```text
ver a agenda
→ identificar o próximo cliente
→ abrir o cliente
→ iniciar atendimento
→ finalizar
→ receber pagamento
→ visualizar comissão
```
