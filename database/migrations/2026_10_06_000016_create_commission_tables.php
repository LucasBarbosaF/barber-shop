<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_items', function (Blueprint $table) {
            $table->unique(['tenant_id', 'id']);
        });

        Schema::create('commission_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('barber_id');
            $table->foreignId('service_id')->nullable();
            $table->string('calculation_type', 16)->default('percentage');
            $table->decimal('percentage', 5, 2)->nullable();
            $table->decimal('fixed_amount', 10, 2)->nullable();
            $table->timestamps();

            $table->foreign(['tenant_id', 'barber_id'])
                ->references(['tenant_id', 'id'])
                ->on('barbers')
                ->cascadeOnDelete();
            $table->foreign(['tenant_id', 'service_id'])
                ->references(['tenant_id', 'id'])
                ->on('services')
                ->cascadeOnDelete();
            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'barber_id', 'service_id']);
        });
        DB::statement("ALTER TABLE commission_rules ADD CONSTRAINT commission_rules_value_check CHECK ((calculation_type = 'percentage' AND percentage IS NOT NULL AND percentage BETWEEN 0 AND 100 AND fixed_amount IS NULL) OR (calculation_type = 'fixed_amount' AND percentage IS NULL AND fixed_amount IS NOT NULL AND fixed_amount > 0))");
        DB::statement('CREATE UNIQUE INDEX commission_rules_one_default_per_barber ON commission_rules (tenant_id, barber_id) WHERE service_id IS NULL');
        DB::statement('CREATE UNIQUE INDEX commission_rules_one_service_rule_per_barber ON commission_rules (tenant_id, barber_id, service_id) WHERE service_id IS NOT NULL');

        Schema::create('commission_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->string('status', 16)->default('closed');
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('closed_at');
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'period_start', 'period_end']);
        });
        DB::statement('ALTER TABLE commission_periods ADD CONSTRAINT commission_periods_dates_check CHECK (period_end >= period_start)');
        DB::statement("ALTER TABLE commission_periods ADD CONSTRAINT commission_periods_status_check CHECK (status IN ('closed', 'paid'))");

        Schema::create('commission_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('period_id');
            $table->foreignId('barber_id');
            $table->decimal('amount', 10, 2);
            $table->string('idempotency_key', 100);
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('paid_at');
            $table->timestamps();

            $table->foreign(['tenant_id', 'period_id'])
                ->references(['tenant_id', 'id'])
                ->on('commission_periods')
                ->restrictOnDelete();
            $table->foreign(['tenant_id', 'barber_id'])
                ->references(['tenant_id', 'id'])
                ->on('barbers')
                ->restrictOnDelete();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'period_id', 'barber_id']);
            $table->unique(['tenant_id', 'period_id', 'barber_id', 'id']);
            $table->unique(['tenant_id', 'idempotency_key']);
            $table->index(['tenant_id', 'barber_id', 'paid_at']);
        });
        DB::statement('ALTER TABLE commission_payments ADD CONSTRAINT commission_payments_amount_check CHECK (amount > 0)');

        Schema::create('commissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('period_id');
            $table->foreignId('attendance_item_id');
            $table->foreignId('barber_id');
            $table->foreignId('service_id');
            $table->foreignId('commission_payment_id')->nullable();
            $table->string('barber_name_snapshot', 150);
            $table->string('service_name_snapshot', 150);
            $table->string('calculation_type_snapshot', 16);
            $table->decimal('percentage_snapshot', 5, 2)->nullable();
            $table->decimal('fixed_amount_snapshot', 10, 2)->nullable();
            $table->decimal('amount', 10, 2);
            $table->timestamps();

            $table->foreign(['tenant_id', 'period_id'])
                ->references(['tenant_id', 'id'])
                ->on('commission_periods')
                ->restrictOnDelete();
            $table->foreign(['tenant_id', 'attendance_item_id'])
                ->references(['tenant_id', 'id'])
                ->on('attendance_items')
                ->restrictOnDelete();
            $table->foreign(['tenant_id', 'barber_id'])
                ->references(['tenant_id', 'id'])
                ->on('barbers')
                ->restrictOnDelete();
            $table->foreign(['tenant_id', 'service_id'])
                ->references(['tenant_id', 'id'])
                ->on('services')
                ->restrictOnDelete();
            $table->foreign(['tenant_id', 'period_id', 'barber_id', 'commission_payment_id'])
                ->references(['tenant_id', 'period_id', 'barber_id', 'id'])
                ->on('commission_payments')
                ->restrictOnDelete();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'attendance_item_id']);
            $table->index(['tenant_id', 'period_id', 'barber_id', 'commission_payment_id']);
        });
        DB::statement("ALTER TABLE commissions ADD CONSTRAINT commissions_snapshot_check CHECK ((calculation_type_snapshot = 'percentage' AND percentage_snapshot BETWEEN 0 AND 100 AND fixed_amount_snapshot IS NULL) OR (calculation_type_snapshot = 'fixed_amount' AND percentage_snapshot IS NULL AND fixed_amount_snapshot > 0))");
        DB::statement('ALTER TABLE commissions ADD CONSTRAINT commissions_amount_check CHECK (amount >= 0)');
        DB::statement('ALTER TABLE commissions ADD CONSTRAINT commissions_snapshot_names_check CHECK (length(barber_name_snapshot) > 0 AND length(service_name_snapshot) > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('commissions');
        Schema::dropIfExists('commission_payments');
        Schema::dropIfExists('commission_periods');
        Schema::dropIfExists('commission_rules');
        Schema::table('attendance_items', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'id']);
        });
    }
};
