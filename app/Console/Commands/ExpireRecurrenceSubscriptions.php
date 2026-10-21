<?php

namespace App\Console\Commands;

use App\Application\Recurrence\Actions\ExpireSubscriptions;
use App\Domain\Tenant\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Encerra assinaturas de recorrência com a vigência vencida, em todas as
 * barbearias ativas.
 *
 * O RLS exige que a barbearia seja declarada antes de qualquer escrita, e um
 * comando agendado não tem sessão nem `TenantContext`: sem declarar o tenant,
 * o `UPDATE` casa zero linhas em silêncio (a assimetria da Sprint 5) e o
 * comando terminaria "sucesso" sem encerrar nada. Por isso o loop por tenant,
 * cada um na sua transação com `SET LOCAL`.
 */
class ExpireRecurrenceSubscriptions extends Command
{
    protected $signature = 'recurrence:expire';

    protected $description = 'Encerra assinaturas de recorrência cuja vigência já passou';

    public function handle(ExpireSubscriptions $expire): int
    {
        $total = 0;

        Tenant::query()
            ->where('is_active', true)
            ->pluck('id')
            ->each(function ($tenantId) use (&$total, $expire): void {
                $expired = DB::transaction(function () use ($tenantId, $expire): int {
                    DB::selectOne('SELECT set_config(?, ?, true)', [
                        'app.current_tenant',
                        (string) $tenantId,
                    ]);

                    return $expire->execute([])['expired'];
                });

                $total += $expired;
            });

        $this->info(sprintf('%d assinatura(s) encerrada(s).', $total));

        return self::SUCCESS;
    }
}
