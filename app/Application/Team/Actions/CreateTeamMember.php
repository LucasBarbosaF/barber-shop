<?php

namespace App\Application\Team\Actions;

use App\Application\Shared\Actions\Action;
use App\Application\Shared\Tenancy\TenantContext;
use App\Domain\Commissions\Enums\CommissionCalculationType;
use App\Domain\Tenant\Enums\MembershipRole;
use App\Domain\Tenant\Models\Membership;
use App\Models\Barber;
use App\Models\BusinessHour;
use App\Models\CommissionRule;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CreateTeamMember extends Action
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    /**
     * @param  array{name: string, email: string, role: MembershipRole, phone?: string|null, service_ids?: array<int, int>, commission_percentage?: string|null, business_hours?: array<int, array{opens_at: string|null, closes_at: string|null}>}  $input
     * @return array{membership: Membership, password: string}
     */
    protected function handle(array $input): array
    {
        return DB::transaction(function () use ($input): array {
            $tenantId = $this->tenantContext->id();
            abort_if($tenantId === null, 403);

            $password = Str::password(16, symbols: false);
            $user = User::query()->create([
                'name' => $input['name'],
                'email' => $input['email'],
                'password' => $password,
                'email_verified_at' => now(),
                'is_superadmin' => false,
                'must_change_password' => true,
            ]);
            $membership = Membership::query()->create([
                'user_id' => $user->getKey(),
                'tenant_id' => $tenantId,
                'role' => $input['role'],
                'is_active' => true,
            ]);

            if ($input['role'] === MembershipRole::Barber) {
                $barber = Barber::query()->create([
                    'tenant_id' => $tenantId,
                    'user_id' => $user->getKey(),
                    'name' => $user->name,
                    'phone' => $input['phone'],
                    'is_active' => true,
                    'commission_percentage' => $input['commission_percentage'],
                ]);
                CommissionRule::query()->create([
                    'barber_id' => $barber->getKey(),
                    'calculation_type' => CommissionCalculationType::Percentage,
                    'percentage' => $input['commission_percentage'],
                ]);
                $barber->services()->syncWithPivotValues($input['service_ids'], [
                    'tenant_id' => $tenantId,
                ]);

                foreach ($input['business_hours'] as $weekday => $period) {
                    if ($period['opens_at'] === null || $period['closes_at'] === null) {
                        continue;
                    }

                    BusinessHour::query()->create([
                        'tenant_id' => $tenantId,
                        'barber_id' => $barber->getKey(),
                        'weekday' => $weekday,
                        'opens_at' => $period['opens_at'],
                        'closes_at' => $period['closes_at'],
                    ]);
                }
            }

            return [
                'membership' => $membership->load('user'),
                'password' => $password,
            ];
        });
    }
}
