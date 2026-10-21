<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * RLS em `recurrence_subscription_payments`, no mesmo formato das demais
 * tabelas de recorrência: o contexto de tenant chega pelo GUC lido por
 * `app.current_tenant_id()` e o FORCE cobre até o dono da tabela.
 */
return new class extends Migration
{
    private const TABLE = 'recurrence_subscription_payments';

    public function up(): void
    {
        $table = self::TABLE;

        DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
        DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");

        DB::statement(<<<SQL
            CREATE POLICY {$table}_select ON {$table} FOR SELECT USING (
                tenant_id = app.current_tenant_id()
            )
        SQL);

        DB::statement(<<<SQL
            CREATE POLICY {$table}_insert ON {$table} FOR INSERT WITH CHECK (
                tenant_id = app.current_tenant_id()
            )
        SQL);

        DB::statement(<<<SQL
            CREATE POLICY {$table}_update ON {$table} FOR UPDATE
            USING (tenant_id = app.current_tenant_id())
            WITH CHECK (tenant_id = app.current_tenant_id())
        SQL);

        DB::statement(<<<SQL
            CREATE POLICY {$table}_delete ON {$table} FOR DELETE USING (
                tenant_id = app.current_tenant_id()
            )
        SQL);
    }

    public function down(): void
    {
        $table = self::TABLE;

        DB::statement("DROP POLICY IF EXISTS {$table}_delete ON {$table}");
        DB::statement("DROP POLICY IF EXISTS {$table}_update ON {$table}");
        DB::statement("DROP POLICY IF EXISTS {$table}_insert ON {$table}");
        DB::statement("DROP POLICY IF EXISTS {$table}_select ON {$table}");
        DB::statement("ALTER TABLE {$table} NO FORCE ROW LEVEL SECURITY");
        DB::statement("ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY");
    }
};
