<?php

namespace App\Application\WhatsApp\Support;

/**
 * Normalização de telefone entre dois formatos.
 *
 * O WhatsApp entrega e aceita o formato internacional sem sinal (`5511999999999`),
 * mas o sistema trabalha com telefone nacional com DDD (`11999999999`) — é o que
 * `CreatePublicAppointment` valida e o que a tabela `customers` guarda. Este
 * helper é a ponte: `national()` alimenta o agendamento, `international()`
 * alimenta o envio da resposta.
 */
final class WhatsAppPhone
{
    public static function digits(string $raw): string
    {
        return preg_replace('/\D+/', '', $raw) ?? '';
    }

    /**
     * Formato nacional de 10/11 dígitos, ou null quando o número não é brasileiro
     * plausível.
     */
    public static function national(string $raw): ?string
    {
        $digits = self::digits($raw);

        if (str_starts_with($digits, '55') && in_array(strlen($digits), [12, 13], true)) {
            $digits = substr($digits, 2);
        }

        return in_array(strlen($digits), [10, 11], true) ? $digits : null;
    }

    /**
     * Formato internacional sem sinal, como o Graph API espera receber em `to`.
     */
    public static function international(string $raw): ?string
    {
        $digits = self::digits($raw);

        if (in_array(strlen($digits), [10, 11], true)) {
            return '55'.$digits;
        }

        if (str_starts_with($digits, '55') && strlen($digits) >= 12) {
            return $digits;
        }

        return null;
    }
}
