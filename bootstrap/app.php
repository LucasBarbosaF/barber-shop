<?php

use App\Http\Middleware\EnsurePasswordIsSet;
use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\EnsureSuperadmin;
use App\Http\Middleware\EnsureTenantSelected;
use App\Http\Middleware\ResolveTenant;
use App\Http\Middleware\SetTenantDatabaseContext;
use App\Http\Middleware\SetUserDatabaseContext;
use App\Http\Responses\AccessDeniedResponse;
use App\Http\Responses\UnauthenticatedResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'superadmin' => EnsureSuperadmin::class,
            'password.set' => EnsurePasswordIsSet::class,
            'permission' => EnsurePermission::class,
            'tenant.selected' => EnsureTenantSelected::class,
        ]);

        /*
         * `authenticated` é o pipeline do produto, e a ordem dos dois importa:
         *
         *   Requisição → Authentication (sessão) → User → Membership
         *             → TenantContext → RBAC → Use Case → Resource
         *
         * A resolução do tenant entra no grupo, e não como middleware opcional
         * de cada rota. Se dependesse de cada grupo se lembrar, a primeira rota
         * nova sem o alias autorizaria contra um TenantContext vazio — e o Gate
         * nega tudo nesse caso, o que é seguro, mas aparece como 403 em tela em
         * vez de como "aqui é o pipeline".
         *
         * `Authenticate` vem primeiro porque `ResolveTenant` precisa do usuário.
         *
         * Os dois middlewares de banco vêm em lados opostos de `ResolveTenant`, e
         * essa é a ordem que a policy de RLS exige:
         *
         *   Authenticate → SetUserDatabaseContext → ResolveTenant
         *                 → SetTenantDatabaseContext
         *
         * `SetUserDatabaseContext` abre a transação e seta `app.current_user`
         * **antes** do resolver, porque `ResolveTenant` consulta `memberships`
         * para decidir a barbearia. Sem o usuário setado, essa consulta volta
         * zero linhas — não há tenant ainda, e a policy de tenant não tem como
         * autorizar —, o tenant nunca resolve e o RLS derruba a própria
         * autenticação.
         *
         * `SetTenantDatabaseContext` vem **depois** porque só depois do resolver o
         * `TenantContext` está preenchido. Invertê-lo faria toda policy de RLS
         * ver `app.current_tenant` vazio, e "vazio" para o RLS significa "não vê
         * nada" — a requisição passaria a falhar em 500 dentro do primeiro SELECT.
         *
         * `auth:sanctum` NÃO passa por aqui: ele continua sendo só o guard, e
         * um token de API não carrega a escolha de barbearia — quem precisar de
         * tenant por token resolve explicitamente (Sprint 20).
         *
         * `EnsureTenantSelected` fica fora, à parte: exige um tenant escolhido e
         * por isso não pode barrar a troca de barbearia nem a tela de seleção,
         * que existem justamente para quem ainda não escolheu.
         */
        $middleware->group('authenticated', [
            Authenticate::class,
            SetUserDatabaseContext::class,
            ResolveTenant::class,
            SetTenantDatabaseContext::class,
        ]);

        /*
                 * Tudo que decide acesso — e tudo que escreve contexto no banco — precisa
                 * rodar antes de `SubstituteBindings`, e a **prioridade** é o que garante
                 * isso. A posição dentro do grupo não alcança o binding.
                 *
                 * ## O que estava quebrado (medido na Sprint 5)
                 *
                 * `Membership` entra como route model binding, e o binding consulta
                 * `memberships` **antes** do grupo `authenticated`. `app.current_tenant`
                 * estava vazio naquele ponto, o RLS escondeu a linha e `findOrFail`
                 * devolveu 404 — em toda rota `show()` tenant-scoped do produto,
                 * inclusive para a membership da própria barbearia. Não era brecha, era a
                 * página quebrada.
                 *
                 * O mesmo valia para as duas decisões de acesso: um usuário sem barbearia
                 * escolhida recebia 404 em vez do 403 que diz "escolha uma barbearia".
                 *
                 * ## A regra que sobra
                 *
                 *   403 quando a decisão é sobre quem pede — sem permissão, sem
                 *       barbearia escolhida;
                 *   404 quando o registro em si é invisível — id de outra barbearia.
                 *
                 * A segunda metade é o RLS decidindo, e é uma troca boa: a Sprint 4
                 * recusava 404 porque "403 aqui, 404 ali" reconstrói a base alheia id por
                 * id. Agora todo id de outra barbearia dá 404, igual a um id que não
                 * existe, e não há par de respostas para comparar. Os testes de integração
                 * fixam o par lado a lado.
                 *
                 * ## Por que `prependToPriorityList` e não `priority()`
                 *
                 * `priority()` substitui a lista inteira, o que obrigaria a reescrever a
                 * lista padrão do framework aqui — e ela muda de versão para versão. Cada
                 * chamada insere antes de `SubstituteBindings`, então a ordem entre os
                 * middlewares é a ordem das chamadas. `StartSession` e `Authenticate` já
                 * vêm antes na lista padrão: a sessão e o usuário estão disponíveis.
                 */
        $beforeBinding = [
            SetUserDatabaseContext::class,
            ResolveTenant::class,
            SetTenantDatabaseContext::class,
            // `tenant.selected` antes de `permission`: a permission é uma
            // permission **daquela** barbearia, e sem barbearia escolhida não há o
            // que avaliar. Invertido, o Gate recusa por falta de contexto e a
            // pessoa lê "Você não tem permissão para esta ação" quando o problema
            // era não ter escolhido uma barbearia.
            EnsureTenantSelected::class,
            EnsurePermission::class,
        ];

        foreach ($beforeBinding as $beforeSubstituteBindings) {
            $middleware->prependToPriorityList(SubstituteBindings::class, $beforeSubstituteBindings);
        }

        $middleware->redirectGuestsTo(fn (Request $request) => route('login'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // 401 e 403 padronizados: qualquer caminho que negue um acesso passa por
        // estas duas respostas, e nenhum delas decide autorização.
        $exceptions->render(
            fn (AuthenticationException $e, Request $request) => UnauthenticatedResponse::for($request),
        );

        $exceptions->render(
            fn (AuthorizationException $e, Request $request) => AccessDeniedResponse::for($request, $e->getMessage()),
        );

        // Só o 403 é interceptado; os demais status seguem o tratamento normal.
        $exceptions->render(
            fn (HttpExceptionInterface $e, Request $request) => $e->getStatusCode() === 403
                ? AccessDeniedResponse::for($request, $e->getMessage())
                : null,
        );
    })->create();
