<?php

namespace App\Infrastructure\Shared\Tenancy;

use App\Application\Shared\Tenancy\TenantContext as TenantContextContract;

final class SessionTenantContext implements TenantContextContract
{
    private const SESSION_KEY = 'current_tenant_id';

    private ?string $tenantId = null;

    public function id(): ?string
    {
        return $this->tenantId ?? session(self::SESSION_KEY);
    }

    public function hasTenant(): bool
    {
        return $this->id() !== null;
    }

    public function set(string $tenantId): void
    {
        $this->tenantId = $tenantId;
        session([self::SESSION_KEY => $tenantId]);
    }

    public function clear(): void
    {
        $this->tenantId = null;
        session()->forget(self::SESSION_KEY);
    }
}
