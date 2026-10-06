<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $missingColumns = [];
        foreach ([
            'calculation_type_snapshot' => fn (Blueprint $table) => $table->string('calculation_type_snapshot', 16)->nullable(),
            'percentage_snapshot' => fn (Blueprint $table) => $table->decimal('percentage_snapshot', 5, 2)->nullable(),
            'fixed_amount_snapshot' => fn (Blueprint $table) => $table->decimal('fixed_amount_snapshot', 10, 2)->nullable(),
        ] as $column => $definition) {
            if (! Schema::hasColumn('commissions', $column)) {
                $missingColumns[] = $column;
                Schema::table('commissions', $definition);
            }
        }

        $hasItemCalculationType = Schema::hasColumn('attendance_items', 'commission_calculation_type_snapshot');
        $hasItemPercentage = Schema::hasColumn('attendance_items', 'commission_percentage_snapshot');
        $hasItemFixedAmount = Schema::hasColumn('attendance_items', 'commission_fixed_amount_snapshot');

        if ($hasItemCalculationType || $hasItemPercentage || $hasItemFixedAmount) {
            $updates = [];
            if ($hasItemCalculationType && in_array('calculation_type_snapshot', $missingColumns, true)) {
                $updates[] = 'calculation_type_snapshot = item.commission_calculation_type_snapshot';
            }
            if ($hasItemPercentage && in_array('percentage_snapshot', $missingColumns, true)) {
                $updates[] = 'percentage_snapshot = item.commission_percentage_snapshot';
            }
            if ($hasItemFixedAmount && in_array('fixed_amount_snapshot', $missingColumns, true)) {
                $updates[] = 'fixed_amount_snapshot = item.commission_fixed_amount_snapshot';
            }

            if ($updates !== []) {
                DB::statement('
                    UPDATE commissions AS commission
                    SET '.implode(', ', $updates).'
                    FROM attendance_items AS item
                    WHERE item.tenant_id = commission.tenant_id
                      AND item.id = commission.attendance_item_id
                ');
            }
        }

        DB::statement("
            UPDATE commissions
            SET calculation_type_snapshot = CASE
                WHEN percentage_snapshot IS NULL AND fixed_amount_snapshot IS NOT NULL THEN 'fixed_amount'
                ELSE 'percentage'
            END
            WHERE calculation_type_snapshot IS NULL
        ");

        $invalidSnapshotsExist = DB::table('commissions')
            ->where(function ($query): void {
                $query->whereNotIn('calculation_type_snapshot', ['percentage', 'fixed_amount'])
                    ->orWhere(function ($query): void {
                        $query->where('calculation_type_snapshot', 'percentage')
                            ->where(function ($query): void {
                                $query->whereNull('percentage_snapshot')
                                    ->orWhere('percentage_snapshot', '<', 0)
                                    ->orWhere('percentage_snapshot', '>', 100)
                                    ->orWhereNotNull('fixed_amount_snapshot');
                            });
                    })
                    ->orWhere(function ($query): void {
                        $query->where('calculation_type_snapshot', 'fixed_amount')
                            ->where(function ($query): void {
                                $query->whereNull('fixed_amount_snapshot')
                                    ->orWhere('fixed_amount_snapshot', '<=', 0)
                                    ->orWhereNotNull('percentage_snapshot');
                            });
                    });
            })
            ->exists();

        if ($invalidSnapshotsExist) {
            throw new RuntimeException(
                'Cannot repair commission snapshots because one or more rows have incomplete or conflicting calculation values.'
            );
        }

        DB::statement('ALTER TABLE commissions DROP CONSTRAINT IF EXISTS commissions_snapshot_check');
        DB::statement("ALTER TABLE commissions ADD CONSTRAINT commissions_snapshot_check CHECK ((calculation_type_snapshot = 'percentage' AND percentage_snapshot BETWEEN 0 AND 100 AND fixed_amount_snapshot IS NULL) OR (calculation_type_snapshot = 'fixed_amount' AND percentage_snapshot IS NULL AND fixed_amount_snapshot > 0))");
        DB::statement("ALTER TABLE commissions ALTER COLUMN calculation_type_snapshot SET DEFAULT 'percentage'");
        DB::statement('ALTER TABLE commissions ALTER COLUMN calculation_type_snapshot SET NOT NULL');
    }

    public function down(): void
    {
        throw new RuntimeException(
            'The commission snapshot repair cannot be safely reversed because it may have backfilled historical commission data.'
        );
    }
};
