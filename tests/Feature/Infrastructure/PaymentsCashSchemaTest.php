<?php

use Illuminate\Support\Facades\DB;

it('applies tenant foreign keys, idempotency constraints and RLS to payments and cash', function (): void {
    foreach (['payments', 'payment_refunds', 'payment_events', 'cash_registers', 'cash_transactions'] as $table) {
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

    $paymentIndexes = DB::select('SELECT indexdef FROM pg_indexes WHERE tablename = ?', ['payments']);
    expect(implode(' ', array_map(fn (object $index): string => $index->indexdef, $paymentIndexes)))
        ->toContain('(tenant_id, idempotency_key)');

    $cashRegisterIndexes = DB::select('SELECT indexdef FROM pg_indexes WHERE tablename = ?', ['cash_registers']);
    expect(implode(' ', array_map(fn (object $index): string => $index->indexdef, $cashRegisterIndexes)))
        ->toContain('cash_registers_one_open_per_tenant');
});
