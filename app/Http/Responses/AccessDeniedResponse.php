<?php

namespace App\Http\Responses;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 403 em um único formato.
 *
 * Três caminhos distintos chegam aqui e nenhum deles pode ter resposta própria:
 *
 * - `abort(403)`, que é como os middlewares negam;
 * - `AuthorizationException`, que é o que `Gate::authorize()` e
 *   `$this->authorize()` lançam quando uma Policy recusa;
 * - qualquer outro `HttpExceptionInterface` com status 403.
 *
 * A mensagem exibida é sempre a que o ponto de decisão escreveu. Aqui não se
 * decide *se* a pessoa pode; só se formata a recusa. A regra continua sendo a
 * da Sprint 1 (`PermissionChecker`), e o motivo não pode ser vago para quem
 * está na tela — "acesso negado" sem motivo vira chamado no suporte.
 *
 * O corpo JSON é `{"message": ...}`, o mesmo envelope do 401: um cliente que
 * trata erro de autenticação e de autorização precisa ler o mesmo campo nos
 * dois casos.
 */
final class AccessDeniedResponse
{
    private const FALLBACK_MESSAGE = 'Você não tem permissão para esta ação.';

    public static function for(Request $request, ?string $message = null): Response
    {
        $message = ($message === null || $message === '') ? self::FALLBACK_MESSAGE : $message;

        if (UnauthenticatedResponse::expectsJson($request)) {
            return response()->json(['message' => $message], 403);
        }

        return response()->view('errors.403', ['message' => $message], 403);
    }
}
