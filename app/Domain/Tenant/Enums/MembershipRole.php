<?php

namespace App\Domain\Tenant\Enums;

/**
 * Papel do usuário DENTRO de uma barbearia (tenant).
 *
 * O papel do superadmin NÃO vive aqui: ele é global (users.is_superadmin)
 * porque cadastra as barbearias, não pertence a nenhuma delas.
 */
enum MembershipRole: string
{
    case Admin = 'admin';
    case Manager = 'manager';
    case Supervisor = 'supervisor';
    case Barber = 'barber';
    case Receptionist = 'receptionist';
    case Financeiro = 'financeiro';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Administrador',
            self::Manager => 'Gerente',
            self::Supervisor => 'Supervisor',
            self::Barber => 'Barbeiro',
            self::Receptionist => 'Recepcionista',
            self::Financeiro => 'Financeiro',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }

    public function canManageBarbershop(): bool
    {
        return in_array($this, [self::Admin, self::Manager, self::Supervisor], true);
    }

    /**
     * Quem pode conceder e revogar roles dentro da barbearia.
     *
     * Restrito ao admin de propósito: se o gerente também pudesse, poderia
     * promover alguém a admin e escalar privilégio.
     */
    public function canManageRoles(): bool
    {
        return $this === self::Admin;
    }
}
