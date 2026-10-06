<?php

namespace App\Application\Payments;

use Illuminate\Support\Facades\DB;

final class IdempotencyKeyLock
{
    public static function acquire(string $operation, string $key): void
    {
        DB::selectOne(
            "SELECT pg_advisory_xact_lock(hashtextextended(app.current_tenant_id()::text || ':' || ? || ':' || ?, 0))",
            [$operation, $key],
        );
    }
}
