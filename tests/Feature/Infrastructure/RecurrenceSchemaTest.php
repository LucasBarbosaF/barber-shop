<?php

use App\Domain\Recurrence\Enums\SubscriptionStatus;
use App\Domain\Tenant\Enums\MembershipRole;
use App\Models\Customer;
use App\Models\RecurrencePlan;
use App\Models\RecurrenceSubscription;
use App\Models\Service;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/*
 * Schema das tabelas de recorrência: colunas, FKs compostas (tenant_id, x_id),
 * constraints CHECK, RLS forçado com uma policy por operação e isolamento
 * cross-tenant — o mesmo contrato das demais tabelas tenant-scoped.
 */

describe('schema de recurrence_plans e recurrence_subscriptions', function () {
    it('cria os campos e as foreign keys compostas', function () {
        expect(Schema::hasColumns('recurrence_plans', [
            'id',
            'tenant_id',
            'name',
            'description',
            'service_id',
            'duration_months',
            'sessions_per_month',
            'max_sessions_per_week',
            'price',
            'is_active',
        ]))->toBeTrue();

        expect(Schema::hasColumns('recurrence_subscriptions', [
            'id',
            'tenant_id',
            'public_id',
            'customer_id',
            'plan_id',
            'barber_id',
            'service_id',
            'plan_name',
            'price',
            'duration_months',
            'sessions_per_month',
            'max_sessions_per_week',
            'starts_at',
            'ends_at',
            'status',
            'paid_amount',
            'paid_at',
            'paid_method',
            'paid_obs',
            'created_by',
            'activated_by',
            'cash_posted_at',
            'canceled_at',
            'cancelation_reason',
            'cancel_future_appointments',
        ]))->toBeTrue();

        expect(Schema::hasColumn('appointments', 'recurrence_subscription_id'))->toBeTrue();

        $planFks = DB::select(
            "SELECT pg_get_constraintdef(oid) AS definition
             FROM pg_constraint
             WHERE conrelid = 'recurrence_plans'::regclass AND contype = 'f'
             ORDER BY conname"
        );
        $planFkDefs = implode(' ', array_map(fn (object $row): string => $row->definition, $planFks));

        expect($planFkDefs)
            ->toContain('FOREIGN KEY (tenant_id, service_id) REFERENCES services(tenant_id, id)');

        $subscriptionFks = DB::select(
            "SELECT pg_get_constraintdef(oid) AS definition
             FROM pg_constraint
             WHERE conrelid = 'recurrence_subscriptions'::regclass AND contype = 'f'
             ORDER BY conname"
        );
        $subscriptionFkDefs = implode(' ', array_map(fn (object $row): string => $row->definition, $subscriptionFks));

        expect($subscriptionFkDefs)
            ->toContain('FOREIGN KEY (tenant_id, customer_id) REFERENCES customers(tenant_id, id)')
            ->toContain('FOREIGN KEY (tenant_id, plan_id) REFERENCES recurrence_plans(tenant_id, id)')
            ->toContain('FOREIGN KEY (tenant_id, barber_id) REFERENCES barbers(tenant_id, id)')
            ->toContain('FOREIGN KEY (tenant_id, service_id) REFERENCES services(tenant_id, id)');

        $appointmentFks = DB::select(
            "SELECT pg_get_constraintdef(oid) AS definition
             FROM pg_constraint
             WHERE conrelid = 'appointments'::regclass AND contype = 'f'
               AND conname LIKE '%recurrence_subscription%'"
        );

        expect($appointmentFks)->not->toBeEmpty()
            ->and($appointmentFks[0]->definition)
            ->toContain('FOREIGN KEY (tenant_id, recurrence_subscription_id) REFERENCES recurrence_subscriptions(tenant_id, id)');

        $indexes = DB::select(
            "SELECT indexdef FROM pg_indexes WHERE tablename = 'appointments'"
        );
        $indexDefs = implode(' ', array_map(fn (object $index): string => $index->indexdef, $indexes));

        expect($indexDefs)->toContain('appointments_recurrence_subscription_starts_index');
    });

    it('mantem RLS ativo e forcado com uma policy por operação nas duas tabelas', function () {
        foreach (['recurrence_plans', 'recurrence_subscriptions'] as $table) {
            $state = DB::selectOne(
                "SELECT relrowsecurity, relforcerowsecurity
                 FROM pg_class WHERE relname = ?",
                [$table]
            );

            expect($state->relrowsecurity)->toBeTrue()
                ->and($state->relforcerowsecurity)->toBeTrue();

            $policies = DB::select(
                'SELECT policyname, cmd FROM pg_policies
                 WHERE tablename = ? ORDER BY cmd',
                [$table]
            );

            expect($policies)->toHaveCount(4)
                ->and(array_map(fn (object $policy): string => strtoupper($policy->cmd), $policies))
                ->toBe(['DELETE', 'INSERT', 'SELECT', 'UPDATE']);
        }
    });

    it('impede planos e assinaturas com violações de check', function () {
        [$tenant] = tenantWithUser(MembershipRole::Admin);
        actingInTenant($tenant);

        $service = Service::factory()->create(['tenant_id' => $tenant->getKey()]);

        // duration_months fora da faixa 1-60.
        expect(failedWrite(fn () => RecurrencePlan::factory()->create([
            'tenant_id' => $tenant->getKey(),
            'service_id' => $service->getKey(),
            'duration_months' => 0,
        ])))->toBeInstanceOf(QueryException::class);

        // sessions_per_month > 4.
        expect(failedWrite(fn () => RecurrencePlan::factory()->create([
            'tenant_id' => $tenant->getKey(),
            'service_id' => $service->getKey(),
            'sessions_per_month' => 5,
        ])))->toBeInstanceOf(QueryException::class);

        // max_sessions_per_week > 2.
        expect(failedWrite(fn () => RecurrencePlan::factory()->create([
            'tenant_id' => $tenant->getKey(),
            'service_id' => $service->getKey(),
            'max_sessions_per_week' => 3,
        ])))->toBeInstanceOf(QueryException::class);

        // preço negativo.
        expect(failedWrite(fn () => RecurrencePlan::factory()->create([
            'tenant_id' => $tenant->getKey(),
            'service_id' => $service->getKey(),
            'price' => '-1.00',
        ])))->toBeInstanceOf(QueryException::class);

        // status fora do enum: insere via query builder para pular o cast do model.
        $plan = RecurrencePlan::factory()->create([
            'tenant_id' => $tenant->getKey(),
            'service_id' => $service->getKey(),
        ]);
        $customer = Customer::factory()->create(['tenant_id' => $tenant->getKey()]);
        $baseSubscription = [
            'public_id' => (string) Str::uuid(),
            'tenant_id' => $tenant->getKey(),
            'customer_id' => $customer->getKey(),
            'plan_id' => $plan->getKey(),
            'service_id' => $service->getKey(),
            'plan_name' => $plan->name,
            'price' => $plan->price,
            'duration_months' => 6,
            'sessions_per_month' => 2,
            'max_sessions_per_week' => 2,
            'starts_at' => now()->toDateString(),
            'ends_at' => now()->addMonths(6)->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ];

        expect(failedWrite(fn () => DB::table('recurrence_subscriptions')->insert(
            array_merge($baseSubscription, ['status' => 'qualquer'])
        )))->toBeInstanceOf(QueryException::class);

        // ends_at anterior a starts_at.
        expect(failedWrite(fn () => DB::table('recurrence_subscriptions')->insert(
            array_merge($baseSubscription, [
                'public_id' => (string) Str::uuid(),
                'starts_at' => now()->addMonths(6)->toDateString(),
                'ends_at' => now()->toDateString(),
                'status' => SubscriptionStatus::PendingPayment->value,
            ])
        )))->toBeInstanceOf(QueryException::class);

        actingWithoutTenant();
    });

    it('isola leitura e escrita por tenant no eloquent e no rls', function () {
        [$tenantA] = tenantWithUser(MembershipRole::Admin);
        [$tenantB] = tenantWithUser(MembershipRole::Admin);

        actingInTenant($tenantA);
        $serviceA = Service::factory()->create(['tenant_id' => $tenantA->getKey()]);
        $planA = RecurrencePlan::factory()->create([
            'tenant_id' => $tenantA->getKey(),
            'service_id' => $serviceA->getKey(),
            'name' => 'Plano A',
        ]);
        $customerA = Customer::factory()->create(['tenant_id' => $tenantA->getKey(), 'phone' => '11999000001']);
        $subscriptionA = RecurrenceSubscription::query()->create([
            'public_id' => (string) Str::uuid(),
            'customer_id' => $customerA->getKey(),
            'plan_id' => $planA->getKey(),
            'service_id' => $serviceA->getKey(),
            'plan_name' => $planA->name,
            'price' => $planA->price,
            'duration_months' => 6,
            'sessions_per_month' => 2,
            'max_sessions_per_week' => 2,
            'starts_at' => now()->toDateString(),
            'ends_at' => now()->addMonths(6)->toDateString(),
            'status' => SubscriptionStatus::Active,
        ]);

        actingInTenant($tenantB);
        $serviceB = Service::factory()->create(['tenant_id' => $tenantB->getKey()]);
        $planB = RecurrencePlan::factory()->create([
            'tenant_id' => $tenantB->getKey(),
            'service_id' => $serviceB->getKey(),
            'name' => 'Plano B',
        ]);

        actingInTenant($tenantA);

        expect(RecurrencePlan::query()->pluck('id')->all())->toBe([$planA->getKey()])
            ->and(DB::table('recurrence_plans')->pluck('tenant_id')->unique()->values()->all())
            ->toBe([$tenantA->getKey()])
            ->and(RecurrenceSubscription::query()->pluck('id')->all())->toBe([$subscriptionA->getKey()]);

        actingWithoutTenant();

        expect(DB::table('recurrence_plans')->count())->toBe(0)
            ->and(DB::table('recurrence_subscriptions')->count())->toBe(0);

        actingInTenant($tenantA);

        expect(DB::table('recurrence_plans')->where('id', $planB->getKey())->count())->toBe(0)
            ->and(DB::table('recurrence_subscriptions')->where('public_id', $subscriptionA->public_id)->count())->toBe(1);

        // UPDATE/DELETE invisíveis afetam zero linhas (silêncio RLS).
        expect(DB::table('recurrence_plans')
            ->where('id', $planB->getKey())
            ->update(['name' => 'Invadido']))->toBe(0)
            ->and(DB::table('recurrence_plans')->where('id', $planB->getKey())->delete())->toBe(0);

        actingInTenant($tenantB);
        expect(RecurrencePlan::query()->findOrFail($planB->getKey())->name)->not->toBe('Invadido');
        actingWithoutTenant();
    });

    it('recusa inserir plano com tenant diferente do contexto', function () {
        [$tenantA] = tenantWithUser(MembershipRole::Admin);
        [$tenantB] = tenantWithUser(MembershipRole::Admin);

        actingInTenant($tenantA);
        $service = Service::factory()->create(['tenant_id' => $tenantA->getKey()]);

        expect(failedWrite(fn () => RecurrencePlan::factory()->create([
            'tenant_id' => $tenantB->getKey(),
            'service_id' => $service->getKey(),
        ])))->toBeInstanceOf(QueryException::class);

        actingWithoutTenant();
    });

    it('usa public_id como chave unica apenas dentro do tenant', function () {
        [$tenantA] = tenantWithUser(MembershipRole::Admin);
        [$tenantB] = tenantWithUser(MembershipRole::Admin);
        $sharedUuid = (string) Str::uuid();

        actingInTenant($tenantA);
        $serviceA = Service::factory()->create(['tenant_id' => $tenantA->getKey()]);
        $planA = RecurrencePlan::factory()->create([
            'tenant_id' => $tenantA->getKey(),
            'service_id' => $serviceA->getKey(),
        ]);
        $customerA = Customer::factory()->create(['tenant_id' => $tenantA->getKey(), 'phone' => '11999000002']);
        RecurrenceSubscription::query()->create([
            'public_id' => $sharedUuid,
            'customer_id' => $customerA->getKey(),
            'plan_id' => $planA->getKey(),
            'service_id' => $serviceA->getKey(),
            'plan_name' => $planA->name,
            'price' => $planA->price,
            'duration_months' => 6,
            'sessions_per_month' => 2,
            'max_sessions_per_week' => 2,
            'starts_at' => now()->toDateString(),
            'ends_at' => now()->addMonths(6)->toDateString(),
            'status' => SubscriptionStatus::Active,
        ]);

        // Mesmo tenant, mesmo uuid: viola (tenant_id, public_id).
        $customerA2 = Customer::factory()->create(['tenant_id' => $tenantA->getKey(), 'phone' => '11999000003']);
        expect(failedWrite(fn () => RecurrenceSubscription::query()->create([
            'public_id' => $sharedUuid,
            'customer_id' => $customerA2->getKey(),
            'plan_id' => $planA->getKey(),
            'service_id' => $serviceA->getKey(),
            'plan_name' => $planA->name,
            'price' => $planA->price,
            'duration_months' => 6,
            'sessions_per_month' => 2,
            'max_sessions_per_week' => 2,
            'starts_at' => now()->toDateString(),
            'ends_at' => now()->addMonths(6)->toDateString(),
            'status' => SubscriptionStatus::Active,
        ])))->toBeInstanceOf(QueryException::class);

        // Outro tenant, mesmo uuid: permitido (unique é composta).
        actingInTenant($tenantB);
        $serviceB = Service::factory()->create(['tenant_id' => $tenantB->getKey()]);
        $planB = RecurrencePlan::factory()->create([
            'tenant_id' => $tenantB->getKey(),
            'service_id' => $serviceB->getKey(),
        ]);
        $customerB = Customer::factory()->create(['tenant_id' => $tenantB->getKey(), 'phone' => '11999000004']);
        expect(RecurrenceSubscription::query()->create([
            'public_id' => $sharedUuid,
            'customer_id' => $customerB->getKey(),
            'plan_id' => $planB->getKey(),
            'service_id' => $serviceB->getKey(),
            'plan_name' => $planB->name,
            'price' => $planB->price,
            'duration_months' => 6,
            'sessions_per_month' => 2,
            'max_sessions_per_week' => 2,
            'starts_at' => now()->toDateString(),
            'ends_at' => now()->addMonths(6)->toDateString(),
            'status' => SubscriptionStatus::Active,
        ])->exists)->toBeTrue();

        actingWithoutTenant();
    });

    it('exige nome unico do plano apenas dentro do tenant', function () {
        [$tenantA] = tenantWithUser(MembershipRole::Admin);
        [$tenantB] = tenantWithUser(MembershipRole::Admin);

        actingInTenant($tenantA);
        $serviceA = Service::factory()->create(['tenant_id' => $tenantA->getKey()]);
        RecurrencePlan::factory()->create([
            'tenant_id' => $tenantA->getKey(),
            'service_id' => $serviceA->getKey(),
            'name' => 'Clube Repetido',
        ]);

        expect(failedWrite(fn () => RecurrencePlan::factory()->create([
            'tenant_id' => $tenantA->getKey(),
            'service_id' => $serviceA->getKey(),
            'name' => 'Clube Repetido',
        ])))->toBeInstanceOf(QueryException::class);

        actingInTenant($tenantB);
        $serviceB = Service::factory()->create(['tenant_id' => $tenantB->getKey()]);
        expect(RecurrencePlan::factory()->create([
            'tenant_id' => $tenantB->getKey(),
            'service_id' => $serviceB->getKey(),
            'name' => 'Clube Repetido',
        ])->exists)->toBeTrue();

        actingWithoutTenant();
    });
});
