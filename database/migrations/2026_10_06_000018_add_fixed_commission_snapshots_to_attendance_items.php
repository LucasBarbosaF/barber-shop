<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE attendance_items ALTER COLUMN commission_percentage_snapshot DROP NOT NULL');

        Schema::table('attendance_items', function (Blueprint $table) {
            $table->string('commission_calculation_type_snapshot', 16)->default('percentage');
            $table->decimal('commission_fixed_amount_snapshot', 10, 2)->nullable();
        });
        DB::statement("ALTER TABLE attendance_items ADD CONSTRAINT attendance_items_commission_snapshot_check CHECK ((commission_calculation_type_snapshot = 'percentage' AND commission_percentage_snapshot BETWEEN 0 AND 100 AND commission_fixed_amount_snapshot IS NULL) OR (commission_calculation_type_snapshot = 'fixed_amount' AND commission_percentage_snapshot IS NULL AND commission_fixed_amount_snapshot > 0))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE attendance_items DROP CONSTRAINT IF EXISTS attendance_items_commission_snapshot_check');

        if (DB::table('attendance_items')->where('commission_calculation_type_snapshot', 'fixed_amount')->exists()) {
            throw new RuntimeException(
                'Cannot remove fixed commission snapshots while attendance items use fixed-amount rules.'
            );
        }

        DB::statement('ALTER TABLE attendance_items ALTER COLUMN commission_percentage_snapshot SET NOT NULL');

        Schema::table('attendance_items', function (Blueprint $table) {
            $table->dropColumn(['commission_calculation_type_snapshot', 'commission_fixed_amount_snapshot']);
        });
    }
};
