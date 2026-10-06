<?php

namespace App\Http\Middleware;

use App\Application\Shared\Tenancy\TenantContext;
use App\Domain\Tenant\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class SetPublicBookingTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = Tenant::query()
            ->where('slug', $request->route('tenant'))
            ->where('is_active', true)
            ->firstOrFail();

        $tenantContext = app(TenantContext::class);
        $previousTenantId = $tenantContext->id();
        $tenantContext->set((string) $tenant->getKey());

        try {
            return DB::transaction(function () use ($request, $next, $tenant): Response {
                DB::selectOne('SELECT set_config(?, ?, true)', [
                    'app.current_tenant',
                    (string) $tenant->getKey(),
                ]);

                $request->attributes->set('public_booking_tenant', $tenant);

                return $next($request);
            });
        } finally {
            if ($previousTenantId === null) {
                $tenantContext->clear();
            } else {
                $tenantContext->set($previousTenantId);
            }
        }
    }
}
