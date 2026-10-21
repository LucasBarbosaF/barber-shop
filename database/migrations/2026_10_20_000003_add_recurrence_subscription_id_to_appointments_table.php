<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->unsignedBigInteger('recurrence_subscription_id')->nullable()->after('service_id');

            $table->foreign(['tenant_id', 'recurrence_subscription_id'])
                ->references(['tenant_id', 'id'])
                ->on('recurrence_subscriptions')
                ->restrictOnDelete();
            $table->index(
                ['tenant_id', 'recurrence_subscription_id', 'starts_at'],
                'appointments_recurrence_subscription_starts_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropIndex('appointments_recurrence_subscription_starts_index');
            $table->dropForeign(['tenant_id', 'recurrence_subscription_id']);
            $table->dropColumn('recurrence_subscription_id');
        });
    }
};
