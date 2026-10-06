<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE customers ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE customers FORCE ROW LEVEL SECURITY');

        DB::statement(<<<'SQL'
            CREATE POLICY customers_select ON customers FOR SELECT USING (
                tenant_id = app.current_tenant_id()
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE POLICY customers_insert ON customers FOR INSERT WITH CHECK (
                tenant_id = app.current_tenant_id()
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE POLICY customers_update ON customers FOR UPDATE
            USING (tenant_id = app.current_tenant_id())
            WITH CHECK (tenant_id = app.current_tenant_id())
        SQL);

        DB::statement(<<<'SQL'
            CREATE POLICY customers_delete ON customers FOR DELETE USING (
                tenant_id = app.current_tenant_id()
            )
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP POLICY IF EXISTS customers_delete ON customers');
        DB::statement('DROP POLICY IF EXISTS customers_update ON customers');
        DB::statement('DROP POLICY IF EXISTS customers_insert ON customers');
        DB::statement('DROP POLICY IF EXISTS customers_select ON customers');
        DB::statement('ALTER TABLE customers NO FORCE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE customers DISABLE ROW LEVEL SECURITY');
    }
};
