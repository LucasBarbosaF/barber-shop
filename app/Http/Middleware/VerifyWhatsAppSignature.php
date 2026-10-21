<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Confirma que o webhook veio do Meta.
 *
 * Sem esta checagem qualquer um que descubra a URL poderia POSTar mensagens e
 * agendar em nome dos clientes — o `phone_number_id` do payload é a única
 * coisa que liga a requisição a uma barbearia. O Meta assina o corpo bruto com
 * o app secret em `X-Hub-Signature-256`; a comparação é por `hash_equals` para
 * não vazar timing, e a ausência de segredo configurado recusa (fail closed).
 */
class VerifyWhatsAppSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = config('services.whatsapp.app_secret');
        $signature = (string) $request->header('X-Hub-Signature-256', '');

        $expected = is_string($secret) && $secret !== ''
            ? 'sha256='.hash_hmac('sha256', $request->getContent(), $secret)
            : null;

        abort_unless(
            $expected !== null && str_starts_with($signature, 'sha256=') && hash_equals($expected, $signature),
            401,
            'Assinatura do webhook inválida.',
        );

        return $next($request);
    }
}
