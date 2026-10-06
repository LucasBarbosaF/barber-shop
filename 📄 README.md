# Barber SaaS

Sistema SaaS multi-tenant para gestão de barbearias.

> **Estado atual (corrigido na Sprint 0 — 2026-10-02):** este é um projeto **Laravel**, não Java.
> A autenticação **existe e funciona** (Fortify + sessão, sem JWT/token) e deve ser preservada.
> **RBAC não existe ainda** — não há Gates, Policies concretas nem permissões; o único
> mecanismo em produção é o middleware `superadmin` sobre `users.is_superadmin`.
> As referências a Java, `@PreAuthorize` e `UUID getTenantId()` abaixo são especificações legadas,
> não descrição do código. Auditoria completa: `docs/sprint-0-discovery.md`.

## Objetivos desta fase

- preservar a Authentication existente e cobri-la com testes;
- **criar** o RBAC, que ainda não existe;
- criar Tenant e Membership;
- criar TenantContext;
- integrar tenant ao Authentication/RBAC;
- isolar dados por `tenant_id`;
- usar PostgreSQL Row-Level Security como segunda camada;
- migrar os módulos de negócio gradualmente;
- provar o isolamento com testes automatizados.

## Stack

### Backend

- PHP 8.4+
- Laravel 12+
- Laravel Livewire 4+
- Laravel Sanctum quando API/token for necessário
- Laravel Eloquent ORM
- Laravel Queues
- Laravel Scheduler
- Laravel Events/Listeners
- Laravel Notifications
- Laravel Policies/Gates
- PostgreSQL
- Pest/PHPUnit
- Laravel Pint
- Larastan/PHPStan

### Frontend

- Blade
- Livewire
- Tailwind CSS
- AdminLTE 4 (casca do painel: sidebar, topbar, área de conteúdo, rodapé)
- Bootstrap 5 (entra pelo AdminLTE; nunca importado à parte)
- Font Awesome 7 (ícones do painel)
- Alpine.js quando uma interação client-side simples for necessária

> **Diretriz:** Tailwind será o padrão visual. Bootstrap não deve ser usado para resolver a mesma responsabilidade que Tailwind — no mesmo componente. O shell do painel (sidebar/topbar/cards/tabelas/botões) é AdminLTE 4, que já embute o Bootstrap compilado; o conteúdo das páginas usa as classes desse shell para não virar um meio-termo das duas bases. Ver "Template ERP e UX do Painel".

### Infraestrutura

- Docker
- Nginx
- PHP-FPM
- PostgreSQL

#### Subir o ambiente local com Docker

Requisitos: Docker Engine com o plugin Docker Compose.

```bash
cp .env.example .env
docker compose up -d --build
docker compose exec php composer install
docker compose exec php php artisan key:generate
docker compose exec php php artisan migrate --force
docker compose exec php php artisan storage:link
npm ci && npm run build
```

O painel ficará disponível em `http://localhost:8080`. O serviço PHP usa a imagem
definida em `docker/php/Dockerfile`; o Nginx encaminha as requisições PHP pelo
arquivo `docker/nginx/default.conf`. O build dos assets roda no host conforme o
alvo `make front`.

### Arquitetura frontend

O MVP será **server-driven e Livewire-first**. Não haverá necessidade de React/Next.js no MVP.

```text
Browser
   ↓
Blade
   ↓
Livewire
   ↓
Laravel
   ↓
Application / Domain
   ↓
PostgreSQL
```

JavaScript será utilizado apenas quando houver necessidade real de interação client-side.

---

# Arquitetura

## Authentication x Authorization x Tenant

```text
Authentication
Quem é você?
        ↓
User

Authorization
O que você pode fazer?
        ↓
RBAC / Authorities

Tenant Context
Em qual empresa você está atuando?
        ↓
Tenant / Membership
```

O módulo de autenticação atual continua responsável por identificar o usuário e validar credenciais/token/session.

O RBAC atual continua responsável pelas permissões.

O novo módulo de tenancy determina **em qual barbearia o usuário está atuando**.

Fluxo:

```text
HTTP Request
      ↓
Authentication/RBAC existente
      ↓
Authenticated User
      ↓
Tenant Resolver
      ↓
Membership validation
      ↓
TenantContext
      ↓
RBAC
      ↓
Use Case
      ↓
Repository
      ↓
PostgreSQL
```

## Não reescrever Authentication

Antes de modificar o módulo atual, auditar:

- login, logout, JWT/session;
- refresh e expiração;
- password hashing;
- contexto de autenticação e principal;
- filters;
- authorities, roles e `@PreAuthorize`;
- endpoints protegidos.

A regra é:

```text
Auditar → Testar → Integrar → Migrar
```

e não reescrever Authentication.

---

# Multi-tenancy

Modelo inicial:

```text
1 PostgreSQL
+
tenant_id
+
Membership
+
TenantContext
+
PostgreSQL RLS
```

Modelo:

```text
User
 │
 └── Membership
       │
       └── Tenant
              │
              ├── Customers
              ├── Barbers
              ├── Services
              ├── Appointments
              ├── Attendances
              ├── Payments
              ├── Commissions
              └── Cash
```

Um usuário pode pertencer a várias barbearias.

```text
Lucas
 ├── Barbearia Centro → ADMIN
 └── Barbearia Shopping → MANAGER
```

O RBAC existente deve continuar sendo reutilizado. A Membership define o contexto empresarial.

---

# TenantContext

Criar uma abstração central:

```java
public interface TenantContext {
    UUID getTenantId();
}
```

O tenant nunca deve ser confiado apenas porque veio do frontend.

Se houver `X-Tenant-Id`, o backend deve validar:

```text
usuário autenticado
       ↓
membership
       ↓
tenant solicitado
       ↓
tenant ativo
       ↓
TenantContext
```

---

# PostgreSQL RLS

As entidades tenant-scoped terão `tenant_id`.

Exemplo:

```text
customers
----------------
id
tenant_id
name
phone
email
created_at
```

RLS será uma segunda camada de defesa:

```sql
CREATE POLICY tenant_isolation
ON customers
USING (
    tenant_id = current_setting('app.current_tenant')::uuid
);
```

O backend deverá definir o tenant na transação/conexão antes das queries.

Também manter autorização na aplicação, validação de membership, constraints tenant-scoped e testes de isolamento.

RLS não substitui RBAC.

---

# Entidades tenant-scoped

```text
customers
barbers
services
business_hours
blocked_periods
appointments
attendances
attendance_items
payments
commission_rules
commissions
commission_payments
cash_registers
cash_transactions
products
stock_movements
notifications
audit_logs
```

Índices:

```text
(tenant_id, created_at)
(tenant_id, starts_at)
(tenant_id, status)
(tenant_id, phone)
```

Unique constraints:

```text
UNIQUE (tenant_id, phone)
UNIQUE (tenant_id, slug)
```

---

# Clean Architecture

```text
Presentation
      ↓
Application
      ↓
Domain
      ↑
Infrastructure
```

Estrutura:

```text
apps/api/src/main/java/com/barbersaas/

├── shared/
│   ├── security/
│   ├── tenant/
│   ├── exception/
│   ├── validation/
│   └── observability/
│
└── modules/
    ├── auth/          # EXISTENTE: auditar e preservar
    ├── tenant/        # NOVO
    ├── membership/    # NOVO
    ├── customer/
    ├── barber/
    ├── service/
    ├── scheduling/
    ├── attendance/
    ├── payment/
    ├── commission/
    ├── cash/
    ├── notification/
    └── audit/
```

---

# Regras importantes

## Authentication

Não criar segundo login ou segundo mecanismo de token.

## RBAC

Não duplicar roles existentes.

A autorização final deve considerar:

```text
Authenticated?
     ↓
Membership válida?
     ↓
Tenant ativo?
     ↓
Possui permission?
     ↓
Recurso pertence ao tenant?
     ↓
PERMITIDO
```

## Dinheiro

Usar `BigDecimal`. Nunca `double` ou `float`.

## Comissão

Copiar preço e regra para o item quando o serviço for adicionado ao atendimento; o
fechamento do período registra a comissão definitiva a partir desse snapshot:

```text
commission_percentage = 50
commission_amount = 35.00
```

## Auditoria

Registrar alterações de role, preço, comissão, pagamentos, estornos, repasses e memberships.

---

# Testes obrigatórios

O principal requisito do multi-tenancy é provar:

```text
Tenant A ≠ Tenant B
```

Testar:

```text
A lê A
A não lê B

A altera A
A não altera B

A exclui A
A não exclui B

A agenda A
A não agenda B

A vê comissão A
A não vê comissão B
```

Também testar usuário com múltiplos tenants e troca de tenant.

---

# Evolução

Continuar como:

```text
Modular Monolith
+
PostgreSQL
+
RLS

```

Não criar microserviços no MVP.

Extrair serviços somente quando existir necessidade real.

---

# Critério de sucesso

```text
Authentication existente
        +
RBAC existente
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
Multi-tenancy seguro
```

**Regra principal: não reescrever o que já funciona; integrar o tenancy ao que já existe.**

# MVP — Escopo obrigatório

O MVP deve entregar cinco jornadas ponta a ponta:

## 1. Site responsivo + agendamento

```text
Cliente → Site → Serviço → Barbeiro → Horário → Agendamento → Banco
```

- catálogo público por barbearia;
- serviços, preços e duração;
- barbeiros e disponibilidade;
- criação, confirmação, cancelamento e remarcação;
- prevenção de double booking;
- identificação do cliente;
- experiência mobile-first.

## 2. WhatsApp + IA + agendamento

```text
Cliente → WhatsApp → IA → Disponibilidade → Agendamento → Banco
```

- integração WhatsApp;
- identificação do cliente;
- consulta de serviços, barbeiros e horários;
- criação, cancelamento e remarcação;
- transferência para humano;
- auditoria das ações da IA.

**A IA nunca acessa o banco diretamente.** Ela utiliza tools/API do backend, que aplicam `TenantContext`, RBAC e regras de negócio.

```text
WhatsApp → IA → Tools/API → Use Case → TenantContext/RBAC → DB
```

## 3. Painel Web

```text
Painel → Gestão → Clientes / Barbeiros / Serviços / Produtos / Agenda / Financeiro
```

Obrigatório:
- clientes;
- barbeiros;
- serviços: corte, barba, corte + barba e outros;
- produtos e estoque;
- agenda diária/semanal;
- bloqueios, cancelamentos, no-show e encaixes;
- vendas, recebimentos, despesas, caixa e fechamento.

## 4. Comissão e repasse

```text
Venda/Atendimento → Regra de Comissão → Cálculo → Fechamento → Repasse → Relatório
```

- comissão padrão por barbeiro com override opcional por serviço; regras de
  produto ficam para a sprint do módulo Produtos;
- percentual ou valor fixo por unidade de serviço;
- cálculo automático;
- snapshot do preço/regra no item do atendimento e registro definitivo no fechamento do período;
- período aberto/fechado/pago;
- histórico de repasses;
- relatório por funcionário e período.

## 5. Assinatura SaaS

```text
Barbearia → Plano → API Gateway → Checkout → Webhook → Subscription → Tenant
```

- planos mensal/anual;
- trial quando aplicável;
- checkout;
- assinatura;
- renovação, cancelamento e inadimplência;
- webhook idempotente;
- estados `TRIAL`, `ACTIVE`, `PAST_DUE`, `CANCELED`, `SUSPENDED`;
- restrição do tenant conforme assinatura.

## Módulos obrigatórios do MVP

```text
auth/             # existente — validar/preservar
tenant/
membership/
customer/
barber/
service/
scheduling/
attendance/
payment/
commission/
cash/
product/
subscription/
notification/
whatsapp/
ai/
audit/
```

## Definition of Done do MVP

As cinco jornadas precisam funcionar ponta a ponta e, principalmente, **nenhuma delas pode permitir acesso entre tenants**.


---

# Decisão arquitetural — MVP

```text
Laravel + Livewire + Blade + Tailwind + PostgreSQL
```

Modelo: **monólito modular Laravel**, fácil de desenvolver, testar, hospedar e evoluir.

No MVP não adicionar React, Next.js, microserviços, Kubernetes, API Gateway ou event bus sem uma necessidade concreta.

---

# Template ERP e UX do Painel

O sistema terá uma interface administrativa no estilo **ERP SaaS**, responsiva e orientada para a operação diária da barbearia.

## Layout

```text
┌───────────────────────────────────────────────────────────────┐
│ LOGO   Busca                         🔔 Tenant   Usuário      │
├───────────────┬───────────────────────────────────────────────┤
│ Dashboard     │                  CONTEÚDO                     │
│ Agenda        │                                               │
│ Clientes      │                                               │
│ Barbeiros     │                                               │
│ Serviços      │                                               │
│ Produtos      │                                               │
│ Atendimento   │                                               │
│ Financeiro    │                                               │
│ Comissões     │                                               │
│ Relatórios    │                                               │
│ Assinatura    │                                               │
│ Configurações │                                               │
│ Sair          │                                               │
└───────────────┴───────────────────────────────────────────────┘
```

### Requisitos

- sidebar fixa no desktop;
- sidebar recolhível;
- menu responsivo no mobile;
- topbar;
- breadcrumb;
- cards de indicadores;
- tabelas responsivas;
- filtros e busca;
- paginação;
- modais e drawers;
- toasts;
- loading e empty states;
- confirmação de operações destrutivas.

Entregues na casca AdminLTE (`layouts/app.blade.php`): sidebar fixa,
recolhível e off-canvas no mobile, topbar, paginação e tabelas responsivas.
Faltam: breadcrumb, seletor de tenant, notificações, modais/drawers, toasts,
loading/empty states e confirmação destrutiva.

### Diretriz visual

- **Tailwind CSS é o padrão visual**;
- Bootstrap pode ser utilizado pontualmente;
- Alpine.js para pequenas interações client-side;
- Livewire para a maior parte das interações do painel.

Não transformar o painel em SPA.

### Casca do painel — AdminLTE 4

Todo roteamento `/app` e `/admin` renderiza por `<x-app-layout>`, que inclui
`resources/views/layouts/app.blade.php`: sidebar, topbar, área de conteúdo e
rodapé. Uma página só precisa envolver o conteúdo e, se quiser, passar
`title="..."`.

| Arquivo | Papel |
|---|---|
| `resources/views/layouts/app.blade.php` | casca (`app-wrapper`, `app-sidebar`, `app-header`, `app-main`, `app-footer`) e flash de `session('status')` |
| `resources/views/layouts/partials/sidebar.blade.php` | menu por seção, com `@can`/superadmin e itens ainda sem rota desabilitados |
| `resources/views/layouts/partials/header.blade.php` | topbar com `data-lte-toggle="sidebar"`, dropdown do usuário e logout |
| `resources/views/layouts/partials/footer.blade.php` | rodapé |
| `resources/css/admin.css` | AdminLTE + Font Awesome + theme/utilities do Tailwind **sem preflight** |
| `resources/js/admin.js` | `import 'bootstrap'` + `import 'admin-lte'` |

Por que assim:

- o AdminLTE 4 **já publica o Bootstrap compilado dentro do CSS dele**, então
  `bootstrap.min.css` não é importado à parte — dois reboot pelo mesmo seletor
  é como o painel vira um meio-termo dos dois frameworks;
- Tailwind entra só como `theme.css` + `utilities.css`: preflight apagaria
  margens/padding dos componentes AdminLTE;
- AdminLTE 4 é ESM e **não usa jQuery** — `resources/js/admin.js` só importa os
  dois pacotes, que se auto-inicializam no DOM;
- sidebar recolhível = `sidebar-mini` + `sidebar-collapse` alternado pelo
  pushmenu; menu mobile = off-canvas abaixo de 992px (`sidebar-expand-lg`);
- paginação usa `Paginator::useBootstrapFive()` (`AppServiceProvider`) para os
  `links()` saírem no mesmo idioma visual do card.

Build de assets (Vite, inputs em `vite.config.js`):

```bash
npm install   # uma vez
npm run dev   # dev server
npm run build # /public/build (gitignored)
```

Os testes chamam `withoutVite()` (`tests/TestCase.php`), então a suíte não
depende de `npm run build`. `AdminTemplateTest` tem um caso que foge da regra:
ele reativa o Vite e confere que `vite.config.js` e os `@vite` resolvem para os
arquivos reais em `/public/build` — e pula quando não há build.

# Agenda — Centro da operação

A agenda é uma das telas mais importantes do MVP.

Ela deve permitir visualizar rapidamente:

- clientes agendados;
- barbeiro responsável;
- serviço;
- horário;
- duração;
- status;
- origem do agendamento;
- situação do atendimento.

## Visualizações

### Dia

```text
             SEGUNDA — 05/10

08:00 │
08:30 │ João
      │ Corte
      │ Lucas
09:00 │
09:30 │ Maria
      │ Corte + Barba
      │ Pedro
10:00 │
...
```

### Semana

```text
         SEG   TER   QUA   QUI   SEX   SAB
08:00    ░     ░     █     ░     █     █
09:00    █     ░     █     █     ░     █
10:00    █     █     ░     █     █     ░
...
```

### Por barbeiro

```text
Lucas
├── 09:00 João — Corte
├── 10:00 Maria — Barba
└── 11:00 Pedro — Corte + Barba

Carlos
├── 09:30 Ana — Corte
└── 11:00 Bruno — Barba
```

## Status

```text
PENDING
CONFIRMED
ARRIVED
IN_SERVICE
COMPLETED
CANCELED
NO_SHOW
```

## Ações rápidas

- novo agendamento;
- confirmar;
- cancelar;
- remarcar;
- marcar cliente como chegou;
- iniciar atendimento;
- finalizar atendimento;
- marcar no-show;
- visualizar cliente;
- visualizar histórico;
- encaixar cliente.

# Dashboard ERP

O dashboard deve mostrar a operação da barbearia.

```text
┌─────────────┐ ┌─────────────┐ ┌─────────────┐ ┌─────────────┐
│ Agendamentos│ │ Atendimentos│ │ Faturamento │ │ Comissão    │
│    Hoje     │ │    Hoje     │ │    Hoje     │ │    Hoje     │
└─────────────┘ └─────────────┘ └─────────────┘ └─────────────┘
```

Também exibir próximos clientes, agenda do dia, faturamento, atendimentos concluídos, cancelamentos, no-show, barbeiros trabalhando, serviços/produtos mais vendidos, comissão acumulada, caixa atual e alertas.

# Menu lateral

```text
Dashboard

OPERAÇÃO
├── Agenda
├── Atendimento
└── Clientes

CADASTROS
├── Clientes
├── Barbeiros
├── Serviços
└── Produtos

FINANCEIRO
├── Caixa
├── Vendas
├── Despesas
├── Comissões
└── Repasse

RELATÓRIOS
├── Faturamento
├── Atendimento
├── Clientes
├── Barbeiros
├── Produtos
└── Comissões

SaaS
└── Assinatura

CONFIGURAÇÕES
├── Minha Barbearia
├── Equipe
├── Permissões
├── Horários
├── Formas de pagamento
└── Integrações

PLATAFORMA (somente superadmin)
└── Barbearias

SAIR
```

O menu deve ser controlado por RBAC/Policies no backend. Esconder uma opção no frontend não é mecanismo de segurança.

Hoje o menu vive em `resources/views/layouts/partials/sidebar.blade.php`. As configurações de Minha
Barbearia, Permissões, Horários e Formas de pagamento exigem `settings.manage`; a identidade salva
no tenant é usada no painel e no agendamento público. Os demais módulos ainda sem rota permanecem
desabilitados até serem implementados. Para servir logos enviados no disco `public`, execute
`php artisan storage:link` no ambiente.

# Componentes Livewire

Criar componentes reutilizáveis:

```text
App\Livewire\Layout\Sidebar
App\Livewire\Layout\Topbar
App\Livewire\Layout\Notifications
App\Livewire\Dashboard\Index
App\Livewire\Scheduling\Calendar
App\Livewire\Scheduling\AppointmentModal
App\Livewire\Scheduling\AppointmentDetails
App\Livewire\Customers\Index
App\Livewire\Customers\Form
App\Livewire\Customers\History
App\Livewire\Barbers\Index
App\Livewire\Services\Index
App\Livewire\Products\Index
App\Livewire\Attendance\Checkout
App\Livewire\Finance\Cash
App\Livewire\Finance\Sales
App\Livewire\Commission\Index
App\Livewire\Commission\Settlement
App\Livewire\Reports\Revenue
App\Livewire\Reports\Barbers
```

# Site público x Painel

## Site público

```text
/
├── Home
├── Serviços
├── Barbeiros
├── Agendamento
├── Minha agenda
└── Login
```

Foco em simplicidade, mobile-first e conversão.

## Painel

```text
/app
```

Foco em produtividade, operação, agenda, financeiro e relatórios.

# Responsividade

O painel deve funcionar em desktop, notebook, tablet e celular. A agenda deve possuir uma experiência específica para telas pequenas, em vez de apenas reduzir a agenda desktop.

# Design System

Criar componentes reutilizáveis:

```text
Button
Input
Select
DatePicker
TimePicker
Modal
Drawer
Dropdown
Badge
Card
Table
Pagination
Tabs
Toast
Alert
EmptyState
Loading
Avatar
StatusBadge
Money
```

# UX do fluxo diário

O sistema será utilizado diariamente por recepcionistas e barbeiros.

```text
Agenda
  ↓
Cliente
  ↓
Atendimento
  ↓
Pagamento
  ↓
Comissão
```

A agenda deve ser o centro operacional da aplicação e as ações frequentes devem exigir poucos cliques.
