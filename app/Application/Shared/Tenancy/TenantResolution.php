<?php

namespace App\Application\Shared\Tenancy;

/**
 * Resultado da resolução do tenant, para a camada HTTP decidir o que fazer.
 *
 * `availableTenantIds` viaja junto porque, no caso de `SelectionRequired`, é
 * exatamente a lista que a tela de seleção precisa oferecer — e offersa apenas
 * as barbearias que a pessoa realmente pertence.
 */
final class TenantResolution
{
    /**
     * Nome do atributo da request onde a resolução fica disponível.
     *
     * A request é quem sabe o contexto da navegação; o controller recebe a
     * resolução por injeção de tipo quando ela importa, sem depender do
     * middleware existir.
     */
    public const ATTRIBUTE = 'tenant_resolution';

    /**
     * @param  array<int, string>  $availableTenantIds
     */
    private function __construct(
        public readonly ResolutionStatus $status,
        public readonly ?string $tenantId,
        public readonly array $availableTenantIds,
    ) {}

    /**
     * @param  array<int, string>  $availableTenantIds
     */
    public static function resolved(string $tenantId, array $availableTenantIds): self
    {
        return new self(ResolutionStatus::Resolved, $tenantId, $availableTenantIds);
    }

    /**
     * @param  array<int, string>  $availableTenantIds
     */
    public static function selectionRequired(array $availableTenantIds): self
    {
        return new self(ResolutionStatus::SelectionRequired, null, $availableTenantIds);
    }

    public static function none(): self
    {
        return new self(ResolutionStatus::None, null, []);
    }

    public function needsSelection(): bool
    {
        return $this->status === ResolutionStatus::SelectionRequired;
    }

    public function isResolved(): bool
    {
        return $this->status === ResolutionStatus::Resolved;
    }
}
