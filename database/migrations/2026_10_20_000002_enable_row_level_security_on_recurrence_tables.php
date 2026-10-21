<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['recurrence_plans', 'recurrence_subscriptions'] as $table) {
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
    }

    public function down(): void
    {
        foreach (['recurrence_subscriptions', 'recurrence_plans'] as $table) {
            DB::statement("DROP POLICY IF EXISTS {$table}_delete ON {$table}");
            DB::statement("DROP POLICY IF EXISTS {$table}_update ON {$table}");
            DB::statement("DROP POLICY IF EXISTS {$table}_insert ON {$table}");
            DB::statement("DROP POLICY IF EXISTS {$table}_select ON {$table}");
            DB::statement("ALTER TABLE {$table} NO FORCE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY");
        }
    }
};
