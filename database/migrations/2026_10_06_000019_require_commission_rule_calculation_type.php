<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('commission_rules', 'fixed_amount')) {
            Schema::table('commission_rules', function (Blueprint $table): void {
                $table->decimal('fixed_amount', 10, 2)->nullable();
            });
        }

        if (! Schema::hasColumn('commission_rules', 'calculation_type')) {
            Schema::table('commission_rules', function (Blueprint $table): void {
                $table->string('calculation_type', 16)->nullable();
            });
        }

        $invalidRulesExist = DB::table('commission_rules')
            ->whereNull('calculation_type')
            ->where(function ($query): void {
                $query->where(function ($query): void {
                    $query->whereNull('percentage')->whereNull('fixed_amount');
                })->orWhere(function ($query): void {
                    $query->whereNotNull('percentage')->whereNotNull('fixed_amount');
                });
            })
            ->exists();

        if ($invalidRulesExist) {
            throw new RuntimeException(
                'Cannot infer calculation_type for commission rules with zero or two configured values.'
            );
        }

        DB::statement("
            UPDATE commission_rules
            SET calculation_type = CASE
                WHEN percentage IS NULL AND fixed_amount IS NOT NULL THEN 'fixed_amount'
                ELSE 'percentage'
            END
            WHERE calculation_type IS NULL
        ");

        DB::statement('ALTER TABLE commission_rules DROP CONSTRAINT IF EXISTS commission_rules_percentage_check');
        DB::statement('ALTER TABLE commission_rules DROP CONSTRAINT IF EXISTS commission_rules_value_check');
        DB::statement('ALTER TABLE commission_rules DROP CONSTRAINT IF EXISTS commission_rules_calculation_type_check');
        DB::statement("ALTER TABLE commission_rules ADD CONSTRAINT commission_rules_value_check CHECK ((calculation_type = 'percentage' AND percentage IS NOT NULL AND percentage BETWEEN 0 AND 100 AND fixed_amount IS NULL) OR (calculation_type = 'fixed_amount' AND percentage IS NULL AND fixed_amount IS NOT NULL AND fixed_amount > 0))");
        DB::statement("ALTER TABLE commission_rules ALTER COLUMN calculation_type SET DEFAULT 'percentage'");
        DB::statement('ALTER TABLE commission_rules ALTER COLUMN calculation_type SET NOT NULL');

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
        DB::statement('ALTER TABLE commission_rules DROP CONSTRAINT IF EXISTS commission_rules_value_check');
        DB::statement('ALTER TABLE commission_periods DROP CONSTRAINT IF EXISTS commission_periods_status_check');

        if (Schema::hasColumn('commission_rules', 'calculation_type')) {
            if (DB::table('commission_rules')->where('calculation_type', 'fixed_amount')->exists()) {
                throw new RuntimeException(
                    'Cannot remove commission calculation types while fixed-amount rules exist.'
                );
            }

            Schema::table('commission_rules', function ($table): void {
                $table->dropColumn('calculation_type');
            });
        }
    }
};
