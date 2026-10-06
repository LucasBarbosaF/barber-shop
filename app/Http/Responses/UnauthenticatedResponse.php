<?php

namespace App\Http\Responses;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 401 em um único formato.
 *
 * A distinção entre 401 e 403 carrega significado: 401 diz "entre", 403 diz "não
 * pode". Enquanto as duas respostas forem montadas caso a caso em cada
 * middleware, um dia alguém escreve 403 para quem só não estava autenticado — e
 * o cliente passa a tratar "sem permissão" como "faça login de novo", em loop.
 *
 * Duas portas, uma decisão:
 *
 * - JSON (`api/*` ou `Accept: application/json`) → 401 com `{"message": ...}`;
 * - HTML → redirecionamento para o login, que é o que uma pessoa espera ao
 *   abrir uma tela protegida no navegador. Responder 401 a um formulário
 *   deixaria a pessoa numa página em branco sem saber o que fazer.
 *
 * Registrado em `bootstrap/app.php`, então vale para qualquer `AuthenticationException`
 * — o guard `web`, o `auth:sanctum`, e o que vier depois. Nenhum caminho de
 * autenticação tem resposta própria.
 */
final class UnauthenticatedResponse
{
    public static function for(Request $request): Response
    {
        if (self::expectsJson($request)) {
            return response()->json([
                'message' => 'Não autenticado.',
            ], 401);
        }

        return redirect()->guest(route('login'));
    }

    /**
     * Espelha `shouldRenderJsonWhen` do `bootstrap/app.php`: `/api/*` é JSON
     * mesmo sem `Accept` explícito, porque o contrato é do endpoint.
     */
    public static function expectsJson(Request $request): bool
    {
        return $request->is('api/*') || $request->expectsJson();
    }
}
