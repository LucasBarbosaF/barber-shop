<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Mensalidades da assinatura de recorrência: um linha por mês de contrato,
 * com status `pending`/`paid` — o agendamento de um mês só é liberado quando
 * o mês tem pagamento confirmado (ver RecurrenceQuota::assertCanSchedule).
 *
 * `gateway` + `gateway_payment_id` já existem para a integração futura com o
 * Asaas: a cobrança recorrente por cartão criará linhas `pending` e as
 * confirmará via webhook; a regra de liberação de agendamento não muda.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recurrence_subscription_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('recurrence_subscription_id');
            $table->date('period_month');
            $table->decimal('amount', 10, 2);
            $table->string('status', 16)->default('pending');
            $table->string('method', 32)->nullable();
            $table->string('obs', 255)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('cash_posted_at')->nullable();
            $table->string('gateway', 32)->nullable();
            $table->string('gateway_payment_id', 64)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            /*
             * Nomes curtos de propósito: o padrão do Laravel
             * (`recurrence_subscription_payments_tenant_id_...`) passa de 63
             * caracteres, o limite do PostgreSQL — e aí unique, index e
             * foreign que compartilham o mesmo prefixo colidem todos na mesma
             * forma truncada, derrubando a migration.
             */
            $table->unique(['tenant_id', 'recurrence_subscription_id', 'period_month'], 'rsp_subscription_month_unique');
            $table->unique(['tenant_id', 'gateway', 'gateway_payment_id'], 'rsp_gateway_charge_unique');
            $table->index(['tenant_id', 'status']);
            // Sem index próprio em (tenant_id, recurrence_subscription_id): o
            // unique acima já serve essas consultas pelo prefixo.
            $table->foreign(['tenant_id', 'recurrence_subscription_id'], 'rsp_subscription_month_fk')
                ->references(['tenant_id', 'id'])
                ->on('recurrence_subscriptions')
                ->restrictOnDelete();
        });
        DB::statement('ALTER TABLE recurrence_subscription_payments ADD CONSTRAINT recurrence_subscription_payments_amount_check CHECK (amount >= 0)');
        DB::statement("ALTER TABLE recurrence_subscription_payments ADD CONSTRAINT recurrence_subscription_payments_status_check CHECK (status IN ('pending', 'paid', 'failed', 'canceled'))");
        DB::statement('ALTER TABLE recurrence_subscription_payments ADD CONSTRAINT recurrence_subscription_payments_period_month_check CHECK (period_month = date_trunc(\'month\', period_month)::date)');

        /*
         * Backfill das assinaturas já pagas: cria a mensalidade do mês 1 a
         * partir dos campos legados (`paid_amount`/`paid_at`), senão toda
         * assinatura ativa ficaria sem mês pago e os cortes seriam recusados.
         *
         * A leitura de `recurrence_subscriptions` está sob RLS (FORCE), então o
         * contexto de tenant é setado por linha via `set_config` — o mesmo GUC
         * que `app.current_tenant_id()` lê. `tenants` não tem RLS e serve de
         * lista. A tabela nova ainda está sem policies nesta migration.
         */
        DB::transaction(function (): void {
            foreach (DB::table('tenants')->orderBy('id')->pluck('id') as $tenantId) {
                DB::select("SELECT set_config('app.current_tenant', ?, true)", [(string) $tenantId]);

                DB::insert(
                    <<<'SQL'
                        INSERT INTO recurrence_subscription_payments
                            (tenant_id, recurrence_subscription_id, period_month, amount, status,
                             method, obs, paid_at, cash_posted_at, created_by, created_at, updated_at)
                        SELECT s.tenant_id, s.id, date_trunc('month', s.starts_at)::date, s.paid_amount, 'paid',
                               s.paid_method, s.paid_obs, s.paid_at, s.cash_posted_at, s.activated_by, now(), now()
                        FROM recurrence_subscriptions s
                        WHERE s.tenant_id = ?
                          AND s.paid_at IS NOT NULL
                          AND NOT EXISTS (
                              SELECT 1 FROM recurrence_subscription_payments p
                              WHERE p.tenant_id = s.tenant_id
                                AND p.recurrence_subscription_id = s.id
                          )
                        SQL,
                    [$tenantId],
                );
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recurrence_subscription_payments');
    }
};
