<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE whatsapp_conversations ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE whatsapp_conversations FORCE ROW LEVEL SECURITY');

        DB::statement(<<<'SQL'
            CREATE POLICY whatsapp_conversations_select ON whatsapp_conversations FOR SELECT USING (
                tenant_id = app.current_tenant_id()
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE POLICY whatsapp_conversations_insert ON whatsapp_conversations FOR INSERT WITH CHECK (
                tenant_id = app.current_tenant_id()
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE POLICY whatsapp_conversations_update ON whatsapp_conversations FOR UPDATE
            USING (tenant_id = app.current_tenant_id())
            WITH CHECK (tenant_id = app.current_tenant_id())
        SQL);

        DB::statement(<<<'SQL'
            CREATE POLICY whatsapp_conversations_delete ON whatsapp_conversations FOR DELETE USING (
                tenant_id = app.current_tenant_id()
            )
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP POLICY IF EXISTS whatsapp_conversations_delete ON whatsapp_conversations');
        DB::statement('DROP POLICY IF EXISTS whatsapp_conversations_update ON whatsapp_conversations');
        DB::statement('DROP POLICY IF EXISTS whatsapp_conversations_insert ON whatsapp_conversations');
        DB::statement('DROP POLICY IF EXISTS whatsapp_conversations_select ON whatsapp_conversations');
        DB::statement('ALTER TABLE whatsapp_conversations NO FORCE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE whatsapp_conversations DISABLE ROW LEVEL SECURITY');
    }
};
