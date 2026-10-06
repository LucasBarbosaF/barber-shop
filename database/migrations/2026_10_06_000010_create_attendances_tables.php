<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('appointment_id');
            $table->string('status', 16)->default('open');
            $table->timestampTz('opened_at');
            $table->timestampTz('closed_at')->nullable();
            $table->timestamps();

            $table->foreign(['tenant_id', 'appointment_id'])
                ->references(['tenant_id', 'id'])
                ->on('appointments')
                ->restrictOnDelete();
            $table->unique(['tenant_id', 'appointment_id']);
            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'status', 'opened_at']);
        });
        DB::statement("ALTER TABLE attendances ADD CONSTRAINT attendances_status_check CHECK (status IN ('open', 'closed'))");
        DB::statement('ALTER TABLE attendances ADD CONSTRAINT attendances_close_time_check CHECK (closed_at IS NULL OR closed_at >= opened_at)');

        Schema::create('attendance_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('attendance_id');
            $table->foreignId('service_id');
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->decimal('unit_price_snapshot', 10, 2);
            $table->decimal('commission_percentage_snapshot', 5, 2);
            $table->decimal('commission_amount_snapshot', 10, 2);
            $table->timestamps();

            $table->foreign(['tenant_id', 'attendance_id'])
                ->references(['tenant_id', 'id'])
                ->on('attendances')
                ->cascadeOnDelete();
            $table->foreign(['tenant_id', 'service_id'])
                ->references(['tenant_id', 'id'])
                ->on('services')
                ->restrictOnDelete();
            $table->index(['tenant_id', 'attendance_id']);
            $table->index(['tenant_id', 'service_id']);
        });
        DB::statement('ALTER TABLE attendance_items ADD CONSTRAINT attendance_items_quantity_check CHECK (quantity > 0)');
        DB::statement('ALTER TABLE attendance_items ADD CONSTRAINT attendance_items_commission_check CHECK (commission_percentage_snapshot BETWEEN 0 AND 100)');
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_items');
        Schema::dropIfExists('attendances');
    }
};
