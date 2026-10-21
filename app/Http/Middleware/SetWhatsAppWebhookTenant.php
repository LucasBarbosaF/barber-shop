<?php

namespace App\Http\Middleware;

use App\Application\Shared\Tenancy\TenantContext;
use App\Domain\Tenant\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolve a barbearia a partir do payload do webhook do WhatsApp.
 *
 * O Meta não manda sessão nem usuário: ele manda `phone_number_id` dentro de
 * `entry.changes.value.metadata`, e esse id é o telefone configurado na
 * barbearia. É o análogo público do `SetPublicBookingTenant` — o mesmo
 * "declara o tenant antes de qualquer leitura", só que por número em vez de
 * slug.
 *
 * Os dois contextos são preenchidos porque a máquina de estados lê e escreve
 * dado tenant-scoped dentro desta mesma requisição: o `TenantContext` para o
 * `TenantScope` da aplicação, o `set_config` para as policies de RLS do banco.
 * O escopo global não filtra nada sem contexto, e "sem filtro" aqui é
 * vazamento entre barbearias, não falha visível.
 */
class SetWhatsAppWebhookTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $phoneNumberId = $request->json('entry.0.changes.0.value.metadata.phone_number_id');

        $tenant = Tenant::query()
            ->whereNotNull('whatsapp_settings')
            ->whereRaw("whatsapp_settings->>'phone_number_id' = ?", [(string) $phoneNumberId])
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

                $request->attributes->set('whatsapp_tenant', $tenant);

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
