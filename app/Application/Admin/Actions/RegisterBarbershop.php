<?php

namespace App\Application\Admin\Actions;

use App\Application\Shared\Actions\Action;
use App\Domain\Tenant\Enums\MembershipRole;
use App\Domain\Tenant\Models\Membership;
use App\Domain\Tenant\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Cadastra uma barbearia e o seu administrador de uma só vez.
 *
 * Única porta de entrada de novos tenants: só o superadmin executa esta action.
 * A senha do admin é gerada aqui e devolvida ao chamador para ser exibida
 * uma única vez — o admin é obrigado a trocá-la no primeiro acesso.
 */
final class RegisterBarbershop extends Action
{
    /**
     * As chaves `owner_*` do input são o contrato HTTP do formulário do
     * superadmin e não mudam nesta sprint; internamente a pessoa criada é o
     * admin da barbearia (MembershipRole::Admin).
     *
     * @param  array{name: string, owner_name: string, owner_email: string, document?: string|null, email?: string|null, phone?: string|null}  $input
     * @return array{tenant: Tenant, admin: User, membership: Membership, password: string}
     */
    protected function handle(array $input): array
    {
        return DB::transaction(function () use ($input): array {
            $password = $this->generatePassword();

            $tenant = Tenant::create([
                'name' => $input['name'],
                'slug' => Tenant::uniqueSlug($input['name']),
                'document' => $input['document'] ?? null,
                'email' => $input['email'] ?? null,
                'phone' => $input['phone'] ?? null,
                'is_active' => true,
                'trial_ends_at' => now()->addDays(30),
            ]);

            $admin = User::create([
                'name' => $input['owner_name'],
                'email' => $input['owner_email'],
                // Sem Hash::make(): o cast 'hashed' do User já hasha na escrita.
                // Hashear aqui produzia hash duplo (dívida D-01 do Sprint 0).
                'password' => $password,
                'email_verified_at' => now(),
                'is_superadmin' => false,
                'must_change_password' => true,
            ]);

            $membership = Membership::create([
                'user_id' => $admin->id,
                'tenant_id' => $tenant->id,
                'role' => MembershipRole::Admin,
                'is_active' => true,
            ]);

            return [
                'tenant' => $tenant,
                'admin' => $admin,
                'membership' => $membership,
                'password' => $password,
            ];
        });
    }

    /**
     * Senha provisória forte o bastante para não ser adivinhada,
     * legível o bastante para o superadmin repassar ao admin.
     */
    private function generatePassword(): string
    {
        return Str::password(16, symbols: false);
    }
}
