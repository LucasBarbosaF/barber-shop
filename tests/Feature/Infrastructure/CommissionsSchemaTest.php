<?php

use App\Domain\Tenant\Enums\MembershipRole;
use App\Models\Barber;
use App\Models\CommissionPeriod;
use App\Models\CommissionRule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('applies tenant constraints and forced row-level security to commission tables', function (): void {
    foreach (['commission_rules', 'commission_periods', 'commission_payments', 'commissions'] as $table) {
        $rls = DB::selectOne('SELECT relrowsecurity, relforcerowsecurity FROM pg_class WHERE relname = ?', [$table]);
        $policies = DB::select('SELECT cmd FROM pg_policies WHERE tablename = ?', [$table]);

        expect($rls->relrowsecurity)->toBeTrue()
            ->and($rls->relforcerowsecurity)->toBeTrue()
            ->and(array_map(fn (object $policy): string => strtoupper($policy->cmd), $policies))
            ->toContain('SELECT', 'INSERT', 'UPDATE', 'DELETE');

        $tenantForeignKey = DB::selectOne(
            "SELECT pg_get_constraintdef(oid) AS definition
             FROM pg_constraint
             WHERE conrelid = ?::regclass AND contype = 'f'
               AND pg_get_constraintdef(oid) LIKE 'FOREIGN KEY (tenant_id) REFERENCES tenants(id)%'",
            [$table],
        );
        expect($tenantForeignKey?->definition)->toContain('FOREIGN KEY (tenant_id) REFERENCES tenants(id)');
    }

    $commissionIndexes = DB::select('SELECT indexdef FROM pg_indexes WHERE tablename = ?', ['commissions']);
    expect(implode(' ', array_map(fn (object $index): string => $index->indexdef, $commissionIndexes)))
        ->toContain('(tenant_id, attendance_item_id)');

    $ruleIndexes = DB::select('SELECT indexdef FROM pg_indexes WHERE tablename = ?', ['commission_rules']);
    expect(implode(' ', array_map(fn (object $index): string => $index->indexdef, $ruleIndexes)))
        ->toContain('commission_rules_one_default_per_barber')
        ->toContain('commission_rules_one_service_rule_per_barber');

    $calculationType = DB::selectOne(
        "SELECT is_nullable, column_default
         FROM information_schema.columns
         WHERE table_name = 'commission_rules' AND column_name = 'calculation_type'"
    );
    expect($calculationType->is_nullable)->toBe('NO')
        ->and($calculationType->column_default)->toContain('percentage');

    $periodStatus = DB::selectOne(
        "SELECT is_nullable, column_default
         FROM information_schema.columns
         WHERE table_name = 'commission_periods' AND column_name = 'status'"
    );
    expect($periodStatus->is_nullable)->toBe('NO')
        ->and($periodStatus->column_default)->toContain('closed');
});

it('repairs legacy commission periods when the status column is missing', function (): void {
    [$tenant, $admin] = tenantWithUser(MembershipRole::Admin);
    actingInTenant($tenant);
    $period = CommissionPeriod::query()->create([
        'period_start' => '2026-10-01',
        'period_end' => '2026-10-07',
        'closed_by' => $admin->getKey(),
        'closed_at' => now(),
    ]);

    DB::statement('ALTER TABLE commission_periods DROP CONSTRAINT commission_periods_status_check');
    DB::statement('ALTER TABLE commission_periods DROP COLUMN status');

    $migration = require database_path('migrations/2026_10_06_000020_repair_commission_period_status.php');
    $migration->up();

    expect(DB::table('commission_periods')->where('id', $period->getKey())->value('status'))
        ->toBe('closed');
});

it('repairs legacy commission rules when the calculation type column is missing', function (): void {
    [$tenant] = tenantWithUser(MembershipRole::Admin);
    actingInTenant($tenant);
    $barber = Barber::factory()->create([
        'tenant_id' => $tenant->getKey(),
        'commission_percentage' => '25.00',
    ]);
    CommissionRule::query()->create([
        'barber_id' => $barber->getKey(),
        'calculation_type' => 'percentage',
        'percentage' => '25.00',
    ]);

    DB::statement('ALTER TABLE commission_rules DROP CONSTRAINT commission_rules_value_check');
    DB::statement('ALTER TABLE commission_rules DROP COLUMN calculation_type');
    DB::statement('ALTER TABLE commission_rules DROP COLUMN fixed_amount');

    $migration = require database_path('migrations/2026_10_06_000019_require_commission_rule_calculation_type.php');
    $migration->up();

    $rule = DB::table('commission_rules')->first();
    $column = DB::selectOne(
        "SELECT is_nullable, column_default
         FROM information_schema.columns
         WHERE table_name = 'commission_rules' AND column_name = 'calculation_type'"
    );

    expect($rule->calculation_type)->toBe('percentage')
        ->and($rule->fixed_amount)->toBeNull()
        ->and($column->is_nullable)->toBe('NO')
        ->and($column->column_default)->toContain('percentage');
});

it('repairs missing calculation snapshot columns on legacy commissions', function (): void {
    DB::statement('ALTER TABLE commissions DROP CONSTRAINT commissions_snapshot_check');
    DB::statement('ALTER TABLE commissions DROP COLUMN calculation_type_snapshot');
    DB::statement('ALTER TABLE commissions DROP COLUMN fixed_amount_snapshot');

    $migration = require database_path('migrations/2026_10_06_000021_repair_commission_calculation_snapshots.php');
    $migration->up();

    $calculationType = DB::selectOne(
        "SELECT is_nullable, column_default
         FROM information_schema.columns
         WHERE table_name = 'commissions' AND column_name = 'calculation_type_snapshot'"
    );
    expect($calculationType->is_nullable)->toBe('NO')
        ->and($calculationType->column_default)->toContain('percentage')
        ->and(Schema::hasColumn('commissions', 'fixed_amount_snapshot'))->toBeTrue();

    $constraint = DB::selectOne(
        "SELECT pg_get_constraintdef(oid) AS definition
         FROM pg_constraint
         WHERE conrelid = 'commissions'::regclass AND conname = 'commissions_snapshot_check'"
    );
    expect($constraint->definition)->toContain('calculation_type_snapshot');
});
