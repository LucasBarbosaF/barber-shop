<?php

namespace App\Application\Shared\Tenancy\Exceptions;

use RuntimeException;

/**
 * A pessoa pediu um tenant ao qual não pertence, ou que não está ativo.
 *
 * Falha de aplicação, não de HTTP: quem chama decide a resposta. O
 * `SwitchTenantController` transforma em 403; um canal de API transformaria em
 * outra coisa, sem mudar a regra.
 */
final class TenantAccessDenied extends RuntimeException
{
    public static function forTenant(string $tenantId): self
    {
        return new self("Acesso ao tenant [{$tenantId}] negado: sem membership ativa.");
    }
}
