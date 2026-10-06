<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('attendance_id');
            $table->decimal('amount', 10, 2);
            $table->string('method', 16);
            $table->string('idempotency_key', 100);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->foreign(['tenant_id', 'attendance_id'])
                ->references(['tenant_id', 'id'])
                ->on('attendances')
                ->restrictOnDelete();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'idempotency_key']);
            $table->index(['tenant_id', 'attendance_id', 'created_at']);
        });
        DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_amount_check CHECK (amount > 0)');
        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_method_check CHECK (method IN ('cash', 'pix', 'card'))");

        Schema::create('payment_refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_id');
            $table->decimal('amount', 10, 2);
            $table->string('reason', 500);
            $table->string('idempotency_key', 100);
            $table->foreignId('refunded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('refunded_at');
            $table->timestamps();

            $table->foreign(['tenant_id', 'payment_id'])
                ->references(['tenant_id', 'id'])
                ->on('payments')
                ->restrictOnDelete();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'idempotency_key']);
            $table->index(['tenant_id', 'payment_id', 'refunded_at']);
        });
        DB::statement('ALTER TABLE payment_refunds ADD CONSTRAINT payment_refunds_amount_check CHECK (amount > 0)');

        Schema::create('payment_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_id');
            $table->foreignId('refund_id')->nullable();
            $table->string('type', 24);
            $table->decimal('amount', 10, 2);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('idempotency_key', 100);
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign(['tenant_id', 'payment_id'])
                ->references(['tenant_id', 'id'])
                ->on('payments')
                ->restrictOnDelete();
            $table->foreign(['tenant_id', 'refund_id'])
                ->references(['tenant_id', 'id'])
                ->on('payment_refunds')
                ->restrictOnDelete();
            $table->index(['tenant_id', 'payment_id', 'created_at']);
        });
        DB::statement('ALTER TABLE payment_events ADD CONSTRAINT payment_events_amount_check CHECK (amount > 0)');
        DB::statement("ALTER TABLE payment_events ADD CONSTRAINT payment_events_type_check CHECK (type IN ('payment_received', 'payment_refunded'))");
        DB::statement("ALTER TABLE payment_events ADD CONSTRAINT payment_events_reference_check CHECK ((type = 'payment_received' AND refund_id IS NULL) OR (type = 'payment_refunded' AND refund_id IS NOT NULL))");

        Schema::create('cash_registers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('status', 16)->default('open');
            $table->decimal('opening_amount', 10, 2);
            $table->decimal('closing_amount', 10, 2)->nullable();
            $table->decimal('expected_amount', 10, 2)->nullable();
            $table->decimal('difference_amount', 10, 2)->nullable();
            $table->timestampTz('opened_at');
            $table->timestampTz('closed_at')->nullable();
            $table->foreignId('opened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'status', 'opened_at']);
        });
        DB::statement("ALTER TABLE cash_registers ADD CONSTRAINT cash_registers_status_check CHECK (status IN ('open', 'closed'))");
        DB::statement('ALTER TABLE cash_registers ADD CONSTRAINT cash_registers_amount_check CHECK (opening_amount >= 0 AND (closing_amount IS NULL OR closing_amount >= 0) AND (expected_amount IS NULL OR expected_amount >= 0))');
        DB::statement('ALTER TABLE cash_registers ADD CONSTRAINT cash_registers_close_state_check CHECK ((status = \'open\' AND closed_at IS NULL AND closing_amount IS NULL) OR (status = \'closed\' AND closed_at IS NOT NULL AND closing_amount IS NOT NULL AND expected_amount IS NOT NULL AND difference_amount IS NOT NULL))');
        DB::statement("CREATE UNIQUE INDEX cash_registers_one_open_per_tenant ON cash_registers (tenant_id) WHERE status = 'open'");

        Schema::create('cash_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cash_register_id');
            $table->foreignId('payment_id')->nullable();
            $table->foreignId('refund_id')->nullable();
            $table->string('type', 16);
            $table->decimal('amount', 10, 2);
            $table->string('description', 500);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->foreign(['tenant_id', 'cash_register_id'])
                ->references(['tenant_id', 'id'])
                ->on('cash_registers')
                ->restrictOnDelete();
            $table->foreign(['tenant_id', 'payment_id'])
                ->references(['tenant_id', 'id'])
                ->on('payments')
                ->restrictOnDelete();
            $table->foreign(['tenant_id', 'refund_id'])
                ->references(['tenant_id', 'id'])
                ->on('payment_refunds')
                ->restrictOnDelete();
            $table->unique(['tenant_id', 'payment_id']);
            $table->unique(['tenant_id', 'refund_id']);
            $table->index(['tenant_id', 'cash_register_id', 'created_at']);
        });
        DB::statement("ALTER TABLE cash_transactions ADD CONSTRAINT cash_transactions_type_check CHECK (type IN ('cash_in', 'cash_out', 'payment', 'refund'))");
        DB::statement('ALTER TABLE cash_transactions ADD CONSTRAINT cash_transactions_amount_check CHECK (amount > 0)');
        DB::statement("ALTER TABLE cash_transactions ADD CONSTRAINT cash_transactions_source_check CHECK ((type IN ('cash_in', 'cash_out') AND payment_id IS NULL AND refund_id IS NULL) OR (type = 'payment' AND payment_id IS NOT NULL AND refund_id IS NULL) OR (type = 'refund' AND payment_id IS NULL AND refund_id IS NOT NULL))");
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_transactions');
        DB::statement('DROP INDEX IF EXISTS cash_registers_one_open_per_tenant');
        Schema::dropIfExists('cash_registers');
        Schema::dropIfExists('payment_events');
        Schema::dropIfExists('payment_refunds');
        Schema::dropIfExists('payments');
    }
};
