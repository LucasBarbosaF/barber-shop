<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('commission_periods', 'status')) {
            Schema::table('commission_periods', function (Blueprint $table): void {
                $table->string('status', 16)->nullable();
            });
        }

        DB::statement("
            UPDATE commission_periods AS period
            SET status = CASE
                WHEN EXISTS (
                    SELECT 1
                    FROM commission_payments AS payment
                    WHERE payment.tenant_id = period.tenant_id
                      AND payment.period_id = period.id
                )
                AND NOT EXISTS (
                    SELECT 1
                    FROM commissions AS commission
                    WHERE commission.tenant_id = period.tenant_id
                      AND commission.period_id = period.id
                      AND commission.amount > 0
                      AND commission.commission_payment_id IS NULL
                ) THEN 'paid'
                ELSE 'closed'
            END
            WHERE period.status IS NULL
        ");

        DB::statement('ALTER TABLE commission_periods DROP CONSTRAINT IF EXISTS commission_periods_status_check');
        DB::statement("ALTER TABLE commission_periods ADD CONSTRAINT commission_periods_status_check CHECK (status IN ('closed', 'paid'))");
        DB::statement("ALTER TABLE commission_periods ALTER COLUMN status SET DEFAULT 'closed'");
        DB::statement('ALTER TABLE commission_periods ALTER COLUMN status SET NOT NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE commission_periods DROP CONSTRAINT IF EXISTS commission_periods_status_check');

        if (Schema::hasColumn('commission_periods', 'status')) {
            Schema::table('commission_periods', function (Blueprint $table): void {
                $table->dropColumn('status');
            });
        }
    }
};
