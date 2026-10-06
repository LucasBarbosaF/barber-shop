<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('barber_services', function (Blueprint $table) {
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('barber_id');
            $table->foreignId('service_id');
            $table->timestamps();

            $table->primary(['tenant_id', 'barber_id', 'service_id']);
            $table->foreign(['tenant_id', 'barber_id'])
                ->references(['tenant_id', 'id'])
                ->on('barbers')
                ->cascadeOnDelete();
            $table->foreign(['tenant_id', 'service_id'])
                ->references(['tenant_id', 'id'])
                ->on('services')
                ->cascadeOnDelete();
            $table->index(['tenant_id', 'service_id']);
        });

        DB::transaction(function (): void {
            foreach (DB::table('tenants')->pluck('id') as $tenantId) {
                DB::select('SELECT set_config(?, ?, true)', ['app.current_tenant', (string) $tenantId]);
                DB::insert(<<<'SQL'
                    INSERT INTO barber_services (tenant_id, barber_id, service_id, created_at, updated_at)
                    SELECT barbers.tenant_id, barbers.id, services.id, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
                    FROM barbers
                    INNER JOIN services ON services.tenant_id = barbers.tenant_id
                    WHERE barbers.tenant_id = ? AND services.tenant_id = ?
                    ON CONFLICT (tenant_id, barber_id, service_id) DO NOTHING
                SQL, [$tenantId, $tenantId]);
            }
        });

        DB::statement('ALTER TABLE barber_services ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE barber_services FORCE ROW LEVEL SECURITY');

        foreach (['SELECT', 'INSERT', 'UPDATE', 'DELETE'] as $operation) {
            $policy = 'barber_services_'.strtolower($operation);
            $clause = match ($operation) {
                'SELECT', 'DELETE' => 'USING (tenant_id = app.current_tenant_id())',
                'INSERT' => 'WITH CHECK (tenant_id = app.current_tenant_id())',
                'UPDATE' => 'USING (tenant_id = app.current_tenant_id()) WITH CHECK (tenant_id = app.current_tenant_id())',
            };

            DB::statement("CREATE POLICY {$policy} ON barber_services FOR {$operation} {$clause}");
        }
    }

    public function down(): void
    {
        foreach (['SELECT', 'INSERT', 'UPDATE', 'DELETE'] as $operation) {
            DB::statement('DROP POLICY IF EXISTS barber_services_'.strtolower($operation).' ON barber_services');
        }

        DB::statement('ALTER TABLE barber_services NO FORCE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE barber_services DISABLE ROW LEVEL SECURITY');
        Schema::dropIfExists('barber_services');
    }
};
