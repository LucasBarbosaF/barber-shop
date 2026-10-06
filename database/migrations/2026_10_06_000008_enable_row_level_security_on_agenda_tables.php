<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['business_hours', 'blocked_periods', 'appointments', 'appointment_notifications'] as $table) {
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
        }

        foreach (['business_hours', 'blocked_periods', 'appointments'] as $table) {
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

        foreach (['SELECT', 'INSERT', 'UPDATE', 'DELETE'] as $operation) {
            $policy = 'appointment_notifications_'.strtolower($operation);
            $clause = match ($operation) {
                'SELECT', 'DELETE' => 'USING (tenant_id = app.current_tenant_id() AND user_id = app.current_user_id())',
                'INSERT' => 'WITH CHECK (tenant_id = app.current_tenant_id())',
                'UPDATE' => 'USING (tenant_id = app.current_tenant_id() AND user_id = app.current_user_id()) WITH CHECK (tenant_id = app.current_tenant_id() AND user_id = app.current_user_id())',
            };
            DB::statement("CREATE POLICY {$policy} ON appointment_notifications FOR {$operation} {$clause}");
        }
    }

    public function down(): void
    {
        foreach (['business_hours', 'blocked_periods', 'appointments', 'appointment_notifications'] as $table) {
            DB::statement("DROP POLICY IF EXISTS {$table}_select ON {$table}");
            DB::statement("DROP POLICY IF EXISTS {$table}_insert ON {$table}");
            DB::statement("DROP POLICY IF EXISTS {$table}_update ON {$table}");
            DB::statement("DROP POLICY IF EXISTS {$table}_delete ON {$table}");
            DB::statement("ALTER TABLE {$table} NO FORCE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY");
        }
    }
};
