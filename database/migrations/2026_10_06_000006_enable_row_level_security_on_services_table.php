<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE services ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE services FORCE ROW LEVEL SECURITY');

        DB::statement(<<<'SQL'
            CREATE POLICY services_select ON services FOR SELECT USING (
                tenant_id = app.current_tenant_id()
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE POLICY services_insert ON services FOR INSERT WITH CHECK (
                tenant_id = app.current_tenant_id()
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE POLICY services_update ON services FOR UPDATE
            USING (tenant_id = app.current_tenant_id())
            WITH CHECK (tenant_id = app.current_tenant_id())
        SQL);

        DB::statement(<<<'SQL'
            CREATE POLICY services_delete ON services FOR DELETE USING (
                tenant_id = app.current_tenant_id()
            )
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP POLICY IF EXISTS services_delete ON services');
        DB::statement('DROP POLICY IF EXISTS services_update ON services');
        DB::statement('DROP POLICY IF EXISTS services_insert ON services');
        DB::statement('DROP POLICY IF EXISTS services_select ON services');
        DB::statement('ALTER TABLE services NO FORCE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE services DISABLE ROW LEVEL SECURITY');
    }
};
