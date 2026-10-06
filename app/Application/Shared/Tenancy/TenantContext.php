<?php

namespace App\Application\Shared\Tenancy;

/**
 * Central abstraction for the current tenant context.
 *
 * Flow:
 * Request → Authentication → User → Membership → TenantContext → RBAC → Use Case → DB
 *
 * The tenant is NEVER trusted solely because it came from the frontend.
 */
interface TenantContext
{
    public function id(): ?string;

    public function hasTenant(): bool;

    public function set(string $tenantId): void;

    public function clear(): void;
}
