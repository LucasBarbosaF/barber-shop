<?php

namespace App\Infrastructure\Shared\Observability;

use Illuminate\Support\Facades\Log;

/**
 * Structured logging context for multi-tenant observability.
 *
 * Never log passwords, tokens, refresh tokens or secrets.
 */
final class LogContext
{
    /**
     * @param  array<string, mixed>  $context
     */
    public static function with(array $context): void
    {
        foreach ($context as $key => $value) {
            Log::shareContext([$key => $value]);
        }
    }

    public static function tenant(?string $tenantId): void
    {
        if ($tenantId !== null) {
            self::with(['tenant_id' => $tenantId]);
        }
    }

    public static function user(?string $userId): void
    {
        if ($userId !== null) {
            self::with(['user_id' => $userId]);
        }
    }

    public static function requestId(string $requestId): void
    {
        self::with(['request_id' => $requestId]);
    }
}
