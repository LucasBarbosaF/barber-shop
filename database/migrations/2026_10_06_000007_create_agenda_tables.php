<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->unique(['tenant_id', 'id']);
        });

        Schema::table('services', function (Blueprint $table) {
            $table->unique(['tenant_id', 'id']);
        });

        Schema::table('barbers', function (Blueprint $table) {
            $table->unique(['tenant_id', 'id']);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->unique(['tenant_id', 'user_id']);
        });

        Schema::create('business_hours', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('barber_id');
            $table->unsignedTinyInteger('weekday');
            $table->time('opens_at');
            $table->time('closes_at');
            $table->timestamps();

            $table->foreign(['tenant_id', 'barber_id'])
                ->references(['tenant_id', 'id'])
                ->on('barbers')
                ->cascadeOnDelete();
            $table->unique(['tenant_id', 'barber_id', 'weekday']);
        });
        DB::statement('ALTER TABLE business_hours ADD CONSTRAINT business_hours_weekday_check CHECK (weekday BETWEEN 0 AND 6)');
        DB::statement('ALTER TABLE business_hours ADD CONSTRAINT business_hours_time_check CHECK (opens_at < closes_at)');

        Schema::create('blocked_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('barber_id');
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->string('reason', 255)->nullable();
            $table->timestamps();

            $table->foreign(['tenant_id', 'barber_id'])
                ->references(['tenant_id', 'id'])
                ->on('barbers')
                ->cascadeOnDelete();
            $table->index(['tenant_id', 'barber_id', 'starts_at', 'ends_at']);
        });
        DB::statement('ALTER TABLE blocked_periods ADD CONSTRAINT blocked_periods_time_check CHECK (starts_at < ends_at)');

        Schema::create('appointments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id');
            $table->foreignId('barber_id');
            $table->foreignId('service_id');
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->string('status', 24)->default('confirmed');
            $table->timestamps();

            $table->foreign(['tenant_id', 'customer_id'])
                ->references(['tenant_id', 'id'])
                ->on('customers')
                ->restrictOnDelete();
            $table->foreign(['tenant_id', 'barber_id'])
                ->references(['tenant_id', 'id'])
                ->on('barbers')
                ->restrictOnDelete();
            $table->foreign(['tenant_id', 'service_id'])
                ->references(['tenant_id', 'id'])
                ->on('services')
                ->restrictOnDelete();
            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'barber_id', 'starts_at']);
            $table->index(['tenant_id', 'customer_id', 'starts_at']);
        });
        DB::statement('ALTER TABLE appointments ADD CONSTRAINT appointments_time_check CHECK (starts_at < ends_at)');
        DB::statement("ALTER TABLE appointments ADD CONSTRAINT appointments_status_check CHECK (status IN ('pending', 'confirmed', 'arrived', 'in_service', 'completed', 'canceled', 'no_show'))");

        Schema::create('appointment_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('appointment_id');
            $table->timestampTz('read_at')->nullable();
            $table->timestamps();

            $table->foreign(['tenant_id', 'appointment_id'])
                ->references(['tenant_id', 'id'])
                ->on('appointments')
                ->cascadeOnDelete();
            $table->unique(['tenant_id', 'user_id', 'appointment_id']);
            $table->index(['tenant_id', 'user_id', 'read_at', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointment_notifications');
        Schema::dropIfExists('appointments');
        Schema::dropIfExists('blocked_periods');
        Schema::dropIfExists('business_hours');

        Schema::table('barbers', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'user_id']);
            $table->dropForeign(['user_id']);
            $table->dropColumn('user_id');
            $table->dropUnique(['tenant_id', 'id']);
        });

        Schema::table('services', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'id']);
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'id']);
        });
    }
};
