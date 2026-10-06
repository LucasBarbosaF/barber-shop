<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['commission_rules', 'commission_periods', 'commission_payments', 'commissions'] as $table) {
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");

            foreach (['SELECT', 'INSERT', 'UPDATE', 'DELETE'] as $operation) {
                $policy = strtolower($table.'_'.$operation);
                $clause = match ($operation) {
                    'SELECT', 'DELETE' => 'USING (tenant_id = app.current_tenant_id())',
                    'INSERT' => 'WITH CHECK (tenant_id = app.current_tenant_id())',
                    'UPDATE' => 'USING (tenant_id = app.current_tenant_id()) WITH CHECK (tenant_id = app.current_tenant_id())',
                };

                DB::statement("CREATE POLICY {$policy} ON {$table} FOR {$operation} {$clause}");
            }
        }
    }

    public function down(): void
    {
        foreach (['commission_rules', 'commission_periods', 'commission_payments', 'commissions'] as $table) {
            foreach (['select', 'insert', 'update', 'delete'] as $operation) {
                DB::statement("DROP POLICY IF EXISTS {$table}_{$operation} ON {$table}");
            }

            DB::statement("ALTER TABLE {$table} NO FORCE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY");
        }
    }
};
