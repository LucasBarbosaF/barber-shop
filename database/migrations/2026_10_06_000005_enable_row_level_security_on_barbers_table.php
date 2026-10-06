<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE barbers ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE barbers FORCE ROW LEVEL SECURITY');

        DB::statement(<<<'SQL'
            CREATE POLICY barbers_select ON barbers FOR SELECT USING (
                tenant_id = app.current_tenant_id()
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE POLICY barbers_insert ON barbers FOR INSERT WITH CHECK (
                tenant_id = app.current_tenant_id()
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE POLICY barbers_update ON barbers FOR UPDATE
            USING (tenant_id = app.current_tenant_id())
            WITH CHECK (tenant_id = app.current_tenant_id())
        SQL);

        DB::statement(<<<'SQL'
            CREATE POLICY barbers_delete ON barbers FOR DELETE USING (
                tenant_id = app.current_tenant_id()
            )
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP POLICY IF EXISTS barbers_delete ON barbers');
        DB::statement('DROP POLICY IF EXISTS barbers_update ON barbers');
        DB::statement('DROP POLICY IF EXISTS barbers_insert ON barbers');
        DB::statement('DROP POLICY IF EXISTS barbers_select ON barbers');
        DB::statement('ALTER TABLE barbers NO FORCE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE barbers DISABLE ROW LEVEL SECURITY');
    }
};
