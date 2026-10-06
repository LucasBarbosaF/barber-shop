<?php

use App\Models\Barber;
use App\Models\Service;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

describe('schema de barbers e services', function () {
    it('aplica tenant, foreign keys, indexes e unicidade por tenant', function () {
        foreach ([
            'barbers' => ['(tenant_id, phone)', '(tenant_id, name)', '(tenant_id, is_active)'],
            'services' => ['(tenant_id, name)', '(tenant_id, is_active)'],
        ] as $table => $expectedIndexes) {
            $indexes = DB::select('SELECT indexdef FROM pg_indexes WHERE tablename = ?', [$table]);
            $definitions = implode(' ', array_map(fn (object $index): string => $index->indexdef, $indexes));

            foreach ($expectedIndexes as $index) {
                expect($definitions)->toContain($index);
            }

            $foreignKey = DB::selectOne(
                "SELECT pg_get_constraintdef(oid) AS definition
                 FROM pg_constraint
                 WHERE conrelid = ?::regclass
                   AND contype = 'f'
                   AND pg_get_constraintdef(oid) LIKE 'FOREIGN KEY (tenant_id) REFERENCES tenants(id)%'",
                [$table]
            );

            expect($foreignKey?->definition)->toContain('FOREIGN KEY (tenant_id) REFERENCES tenants(id)');

            $rls = DB::selectOne(
                'SELECT relrowsecurity, relforcerowsecurity FROM pg_class WHERE relname = ?',
                [$table]
            );

            expect($rls->relrowsecurity)->toBeTrue()
                ->and($rls->relforcerowsecurity)->toBeTrue();

            $policies = DB::select('SELECT cmd FROM pg_policies WHERE tablename = ?', [$table]);

            expect($policies)->toHaveCount(4)
                ->and(array_map(fn (object $policy): string => strtoupper($policy->cmd), $policies))
                ->toContain('SELECT', 'INSERT', 'UPDATE', 'DELETE');
        }

        $pivotIndexes = DB::select('SELECT indexdef FROM pg_indexes WHERE tablename = ?', ['barber_services']);
        $pivotDefinitions = implode(' ', array_map(fn (object $index): string => $index->indexdef, $pivotIndexes));
        expect($pivotDefinitions)
            ->toContain('(tenant_id, barber_id, service_id)')
            ->toContain('(tenant_id, service_id)');

        $pivotRls = DB::selectOne(
            "SELECT relrowsecurity, relforcerowsecurity FROM pg_class WHERE relname = 'barber_services'"
        );
        $pivotPolicies = DB::select('SELECT cmd FROM pg_policies WHERE tablename = ?', ['barber_services']);
        expect($pivotRls->relrowsecurity)->toBeTrue()
            ->and($pivotRls->relforcerowsecurity)->toBeTrue()
            ->and($pivotPolicies)->toHaveCount(4);
    });

    it('protege os vínculos de serviços com foreign keys compostas e RLS', function () {
        $foreignKeys = DB::select(
            "SELECT pg_get_constraintdef(oid) AS definition
             FROM pg_constraint
             WHERE conrelid = 'barber_services'::regclass AND contype = 'f'"
        );
        $definitions = implode(' ', array_map(fn (object $foreignKey): string => $foreignKey->definition, $foreignKeys));

        expect($definitions)
            ->toContain('FOREIGN KEY (tenant_id, barber_id) REFERENCES barbers(tenant_id, id)')
            ->toContain('FOREIGN KEY (tenant_id, service_id) REFERENCES services(tenant_id, id)');

        $rls = DB::selectOne(
            "SELECT relrowsecurity, relforcerowsecurity FROM pg_class WHERE relname = 'barber_services'"
        );
        $policies = DB::select('SELECT cmd FROM pg_policies WHERE tablename = ?', ['barber_services']);

        expect($rls->relrowsecurity)->toBeTrue()
            ->and($rls->relforcerowsecurity)->toBeTrue()
            ->and($policies)->toHaveCount(4);
    });

    it('isola barbeiros e servicos entre tenants e sem contexto', function () {
        [$tenantA] = tenantWithUser();
        [$tenantB] = tenantWithUser();

        actingInTenant($tenantA);
        $barberA = Barber::factory()->create([
            'tenant_id' => $tenantA->getKey(),
            'phone' => '5511888000001',
        ]);
        $serviceA = Service::factory()->create([
            'tenant_id' => $tenantA->getKey(),
            'name' => 'Corte A',
        ]);

        actingInTenant($tenantB);
        $barberB = Barber::factory()->create([
            'tenant_id' => $tenantB->getKey(),
            'phone' => '5511888000002',
        ]);
        $serviceB = Service::factory()->create([
            'tenant_id' => $tenantB->getKey(),
            'name' => 'Corte B',
        ]);

        actingInTenant($tenantA);
        $barberA->services()->attach($serviceA->getKey(), ['tenant_id' => $tenantA->getKey()]);
        actingInTenant($tenantB);
        $barberB->services()->attach($serviceB->getKey(), ['tenant_id' => $tenantB->getKey()]);
        actingInTenant($tenantA);

        expect(Barber::query()->pluck('id')->all())->toBe([$barberA->getKey()])
            ->and(Service::query()->pluck('id')->all())->toBe([$serviceA->getKey()])
            ->and(DB::table('barbers')->where('id', $barberB->getKey())->count())->toBe(0)
            ->and(DB::table('services')->where('id', $serviceB->getKey())->count())->toBe(0)
            ->and(DB::table('barber_services')->count())->toBe(1)
            ->and($barberA->services()->pluck('services.id')->all())->toBe([$serviceA->getKey()]);

        actingWithoutTenant();

        expect(DB::table('barbers')->count())->toBe(0)
            ->and(DB::table('services')->count())->toBe(0)
            ->and(DB::table('barber_services')->count())->toBe(0);
    });

    it('nega insert e update de tenant divergente no RLS', function () {
        [$tenantA] = tenantWithUser();
        [$tenantB] = tenantWithUser();

        actingInTenant($tenantA);

        expect(failedWrite(fn () => Barber::query()->create([
            'tenant_id' => $tenantB->getKey(),
            'name' => 'Barbeiro de outra barbearia',
            'phone' => '5511888000003',
        ])))->toBeInstanceOf(QueryException::class);

        expect(failedWrite(fn () => Service::query()->create([
            'tenant_id' => $tenantB->getKey(),
            'name' => 'Serviço de outra barbearia',
            'duration_minutes' => 30,
            'price' => '35.00',
        ])))->toBeInstanceOf(QueryException::class);

        actingInTenant($tenantB);
        $barberB = Barber::factory()->create([
            'tenant_id' => $tenantB->getKey(),
            'phone' => '5511888000004',
        ]);
        $serviceB = Service::factory()->create([
            'tenant_id' => $tenantB->getKey(),
            'name' => 'Serviço B',
        ]);

        actingInTenant($tenantA);

        expect(DB::table('barbers')->where('id', $barberB->getKey())->update(['tenant_id' => $tenantA->getKey()]))
            ->toBe(0)
            ->and(DB::table('services')->where('id', $serviceB->getKey())->update(['tenant_id' => $tenantA->getKey()]))
            ->toBe(0);

        expect(DB::table('barbers')->where('id', $barberB->getKey())->delete())
            ->toBe(0)
            ->and(DB::table('services')->where('id', $serviceB->getKey())->delete())
            ->toBe(0);
    });

    it('enforce a foreign key para tenants inexistentes', function () {
        setDatabaseTenantId(999999);

        expect(failedWrite(fn () => Barber::query()->create([
            'tenant_id' => 999999,
            'name' => 'Tenant inexistente',
            'phone' => '5511888000010',
        ])))->toBeInstanceOf(QueryException::class)
            ->and(failedWrite(fn () => Service::query()->create([
                'tenant_id' => 999999,
                'name' => 'Tenant inexistente',
                'duration_minutes' => 30,
                'price' => '30.00',
            ])))->toBeInstanceOf(QueryException::class);
    });

    it('impede repetir telefone ou nome no mesmo tenant, mas permite em outro', function () {
        [$tenantA] = tenantWithUser();
        [$tenantB] = tenantWithUser();

        actingInTenant($tenantA);
        Barber::factory()->create([
            'tenant_id' => $tenantA->getKey(),
            'phone' => '5511888000005',
        ]);
        Service::factory()->create([
            'tenant_id' => $tenantA->getKey(),
            'name' => 'Corte Clássico',
        ]);

        expect(failedWrite(fn () => Barber::factory()->create([
            'tenant_id' => $tenantA->getKey(),
            'phone' => '5511888000005',
        ])))->toBeInstanceOf(QueryException::class)
            ->and(failedWrite(fn () => Service::factory()->create([
                'tenant_id' => $tenantA->getKey(),
                'name' => 'Corte Clássico',
            ])))->toBeInstanceOf(QueryException::class);

        actingInTenant($tenantB);
        expect(Barber::factory()->create([
            'tenant_id' => $tenantB->getKey(),
            'phone' => '5511888000005',
        ])->exists)->toBeTrue()
            ->and(Service::factory()->create([
                'tenant_id' => $tenantB->getKey(),
                'name' => 'Corte Clássico',
            ])->exists)->toBeTrue();
    });
});
