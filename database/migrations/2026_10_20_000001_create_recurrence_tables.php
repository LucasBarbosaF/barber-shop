<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recurrence_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->unsignedBigInteger('service_id');
            $table->unsignedTinyInteger('duration_months');
            $table->unsignedTinyInteger('sessions_per_month');
            $table->unsignedTinyInteger('max_sessions_per_week')->default(2);
            $table->decimal('price', 10, 2);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'name']);
            $table->index(['tenant_id', 'is_active']);
            $table->foreign(['tenant_id', 'service_id'])
                ->references(['tenant_id', 'id'])
                ->on('services')
                ->restrictOnDelete();
        });
        DB::statement('ALTER TABLE recurrence_plans ADD CONSTRAINT recurrence_plans_duration_months_check CHECK (duration_months BETWEEN 1 AND 60)');
        DB::statement('ALTER TABLE recurrence_plans ADD CONSTRAINT recurrence_plans_sessions_per_month_check CHECK (sessions_per_month BETWEEN 1 AND 4)');
        DB::statement('ALTER TABLE recurrence_plans ADD CONSTRAINT recurrence_plans_max_sessions_per_week_check CHECK (max_sessions_per_week BETWEEN 1 AND 2)');
        DB::statement('ALTER TABLE recurrence_plans ADD CONSTRAINT recurrence_plans_price_check CHECK (price >= 0)');

        Schema::create('recurrence_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->uuid('public_id');
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('plan_id');
            $table->unsignedBigInteger('barber_id')->nullable();
            $table->unsignedBigInteger('service_id');
            $table->string('plan_name', 150);
            $table->decimal('price', 10, 2);
            $table->unsignedTinyInteger('duration_months');
            $table->unsignedTinyInteger('sessions_per_month');
            $table->unsignedTinyInteger('max_sessions_per_week');
            $table->date('starts_at');
            $table->date('ends_at');
            $table->string('status', 24)->default('pending_payment');
            $table->decimal('paid_amount', 10, 2)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->string('paid_method', 32)->nullable();
            $table->string('paid_obs', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('activated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cash_posted_at')->nullable();
            $table->timestamp('canceled_at')->nullable();
            $table->string('cancelation_reason', 255)->nullable();
            $table->boolean('cancel_future_appointments')->default(false);
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'public_id']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'customer_id']);
            $table->index(['tenant_id', 'ends_at']);
            $table->foreign(['tenant_id', 'customer_id'])
                ->references(['tenant_id', 'id'])
                ->on('customers')
                ->restrictOnDelete();
            $table->foreign(['tenant_id', 'plan_id'])
                ->references(['tenant_id', 'id'])
                ->on('recurrence_plans')
                ->restrictOnDelete();
            $table->foreign(['tenant_id', 'barber_id'])
                ->references(['tenant_id', 'id'])
                ->on('barbers')
                ->restrictOnDelete();
            $table->foreign(['tenant_id', 'service_id'])
                ->references(['tenant_id', 'id'])
                ->on('services')
                ->restrictOnDelete();
        });
        DB::statement("ALTER TABLE recurrence_subscriptions ADD CONSTRAINT recurrence_subscriptions_status_check CHECK (status IN ('pending_payment', 'active', 'canceled', 'expired'))");
        DB::statement('ALTER TABLE recurrence_subscriptions ADD CONSTRAINT recurrence_subscriptions_price_check CHECK (price >= 0)');
        DB::statement('ALTER TABLE recurrence_subscriptions ADD CONSTRAINT recurrence_subscriptions_paid_amount_check CHECK (paid_amount IS NULL OR paid_amount >= 0)');
        DB::statement('ALTER TABLE recurrence_subscriptions ADD CONSTRAINT recurrence_subscriptions_vigencia_check CHECK (ends_at >= starts_at)');
    }

    public function down(): void
    {
        Schema::dropIfExists('recurrence_subscriptions');
        Schema::dropIfExists('recurrence_plans');
    }
};
