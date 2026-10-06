<?php

use App\Models\Customer;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

describe('schema de customers', function () {
    it('cria os campos, a foreign key e os indexes tenant-scoped', function () {
        expect(Schema::hasColumns('customers', [
            'id',
            'tenant_id',
            'name',
            'phone',
            'notes',
            'created_at',
            'updated_at',
        ]))->toBeTrue();

        $indexes = DB::select(
            "SELECT indexdef FROM pg_indexes WHERE tablename = 'customers'"
        );
        $definitions = implode(' ', array_map(fn (object $index): string => $index->indexdef, $indexes));

        expect($definitions)
            ->toContain('(tenant_id, phone)')
            ->toContain('(tenant_id, name)');

        $foreignKey = DB::selectOne(
            "SELECT pg_get_constraintdef(oid) AS definition
             FROM pg_constraint
             WHERE conrelid = 'customers'::regclass AND contype = 'f'"
        );

        expect($foreignKey->definition)->toContain('FOREIGN KEY (tenant_id) REFERENCES tenants(id)');
    });

    it('mantem RLS ativo e forcado com uma policy por operacao', function () {
        $table = DB::selectOne(
            "SELECT relrowsecurity, relforcerowsecurity
             FROM pg_class WHERE relname = 'customers'"
        );

        expect($table->relrowsecurity)->toBeTrue()
            ->and($table->relforcerowsecurity)->toBeTrue();

        $policies = DB::select(
            "SELECT policyname, cmd FROM pg_policies
             WHERE tablename = 'customers' ORDER BY cmd"
        );

        expect($policies)->toHaveCount(4)
            ->and(array_map(fn (object $policy): string => strtoupper($policy->cmd), $policies))
            ->toBe(['DELETE', 'INSERT', 'SELECT', 'UPDATE']);
    });

    it('isola leitura por tenant no escopo eloquent e no RLS', function () {
        [$tenantA] = tenantWithUser();
        [$tenantB] = tenantWithUser();

        actingInTenant($tenantA);
        $customerA = Customer::factory()->create([
            'tenant_id' => $tenantA->getKey(),
            'phone' => '5511999000001',
        ]);

        actingInTenant($tenantB);
        $customerB = Customer::factory()->create([
            'tenant_id' => $tenantB->getKey(),
            'phone' => '5511999000002',
        ]);

        actingInTenant($tenantA);

        expect(Customer::query()->pluck('id')->all())->toBe([$customerA->getKey()])
            ->and(DB::table('customers')->pluck('tenant_id')->unique()->values()->all())
            ->toBe([$tenantA->getKey()]);

        actingWithoutTenant();

        expect(DB::table('customers')->count())->toBe(0);

        actingInTenant($tenantA);
        expect(DB::table('customers')->where('id', $customerB->getKey())->count())->toBe(0);
    });

    it('recusa inserir customer com tenant diferente do contexto', function () {
        [$tenantA] = tenantWithUser();
        [$tenantB] = tenantWithUser();

        actingInTenant($tenantA);

        $exception = failedWrite(fn () => Customer::query()->create([
            'tenant_id' => $tenantB->getKey(),
            'name' => 'Cliente fora do tenant',
            'phone' => '5511999000003',
        ]));

        expect($exception)->toBeInstanceOf(QueryException::class);
    });

    it('impede update e delete de customers invisiveis pelo RLS', function () {
        [$tenantA] = tenantWithUser();
        [$tenantB] = tenantWithUser();

        actingInTenant($tenantB);
        $customerB = Customer::factory()->create([
            'tenant_id' => $tenantB->getKey(),
            'phone' => '5511999000004',
        ]);

        actingInTenant($tenantA);

        expect(DB::table('customers')
            ->where('id', $customerB->getKey())
            ->update(['name' => 'Tentativa indevida']))->toBe(0)
            ->and(DB::table('customers')->where('id', $customerB->getKey())->delete())->toBe(0);

        actingInTenant($tenantB);
        expect(Customer::query()->find($customerB->getKey())->name)->not->toBe('Tentativa indevida');
    });

    it('usa phone como chave unica apenas dentro do tenant', function () {
        [$tenantA] = tenantWithUser();
        [$tenantB] = tenantWithUser();

        actingInTenant($tenantA);
        Customer::factory()->create([
            'tenant_id' => $tenantA->getKey(),
            'phone' => '5511999000005',
        ]);

        expect(failedWrite(fn () => Customer::query()->create([
            'name' => 'Telefone duplicado',
            'phone' => '5511999000005',
        ])))->toBeInstanceOf(QueryException::class);

        actingInTenant($tenantB);
        expect(Customer::factory()->create([
            'tenant_id' => $tenantB->getKey(),
            'phone' => '5511999000005',
        ])->exists)->toBeTrue();
    });
});
