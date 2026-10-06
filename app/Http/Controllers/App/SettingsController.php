<?php

namespace App\Http\Controllers\App;

use App\Application\Shared\Tenancy\TenantContext;
use App\Domain\Authorization\Enums\Permission;
use App\Domain\Authorization\RolePermissions;
use App\Domain\Payments\Enums\PaymentMethod;
use App\Domain\Tenant\Enums\MembershipRole;
use App\Domain\Tenant\Models\Tenant;
use App\Http\Controllers\Controller;
use App\Models\Barber;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SettingsController extends Controller
{
    public function barbershop(): View
    {
        $this->authorize('settings.manage');

        return view('app.settings.barbershop');
    }

    public function updateBarbershop(Request $request): RedirectResponse
    {
        $this->authorize('settings.manage');
        $tenant = $this->currentTenant();
        $attributes = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'document' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'logo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'remove_logo' => ['sometimes', 'boolean'],
        ]);

        $previousLogo = $tenant->logo_path;
        if ($request->hasFile('logo')) {
            $logoPath = $request->file('logo')?->store('tenants/'.$tenant->getKey().'/branding', 'public');
            if (! is_string($logoPath) || $logoPath === '') {
                throw new \RuntimeException('Não foi possível salvar o logo da barbearia.');
            }
            $attributes['logo_path'] = $logoPath;
        } elseif (filter_var($attributes['remove_logo'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $attributes['logo_path'] = null;
        }
        unset($attributes['logo'], $attributes['remove_logo']);

        $tenant->update($attributes);

        if ($previousLogo !== null && $tenant->logo_path !== $previousLogo
            && ! Storage::disk('public')->delete($previousLogo)) {
            throw new \RuntimeException('As configurações foram salvas, mas não foi possível remover o logo anterior.');
        }

        return redirect()->route('app.settings.barbershop')->with('status', 'Dados da barbearia atualizados.');
    }

    public function permissions(): View
    {
        $this->authorize('settings.manage');

        $permissionsByResource = collect(Permission::cases())->groupBy(
            static fn (Permission $permission): string => $permission->resource(),
        );
        $rolePermissions = [];
        foreach (MembershipRole::cases() as $role) {
            $rolePermissions[$role->value] = array_map(
                static fn (Permission $permission): string => $permission->value,
                RolePermissions::for($role),
            );
        }

        return view('app.settings.permissions', [
            'roles' => MembershipRole::cases(),
            'permissionsByResource' => $permissionsByResource,
            'rolePermissions' => $rolePermissions,
        ]);
    }

    public function hours(): View
    {
        $this->authorize('settings.manage');

        return view('app.settings.hours', [
            'barbers' => Barber::query()
                ->where('is_active', true)
                ->with('businessHours')
                ->orderBy('name')
                ->get(),
            'weekdays' => [
                0 => 'Domingo',
                1 => 'Segunda-feira',
                2 => 'Terça-feira',
                3 => 'Quarta-feira',
                4 => 'Quinta-feira',
                5 => 'Sexta-feira',
                6 => 'Sábado',
            ],
        ]);
    }

    public function paymentMethods(): View
    {
        $this->authorize('settings.manage');

        return view('app.settings.payment-methods', [
            'methods' => PaymentMethod::cases(),
            'enabledMethods' => $this->currentTenant()->enabledPaymentMethodValues(),
        ]);
    }

    public function updatePaymentMethods(Request $request): RedirectResponse
    {
        $this->authorize('settings.manage');
        $attributes = $request->validate([
            'payment_methods' => ['required', 'array', 'min:1'],
            'payment_methods.*' => ['required', 'string', 'distinct', Rule::in(array_column(PaymentMethod::cases(), 'value'))],
        ], [
            'payment_methods.required' => 'Mantenha ao menos uma forma de pagamento ativa.',
            'payment_methods.min' => 'Mantenha ao menos uma forma de pagamento ativa.',
        ]);

        $this->currentTenant()->update(['payment_methods' => $attributes['payment_methods']]);

        return redirect()->route('app.settings.payment-methods')->with('status', 'Formas de pagamento atualizadas.');
    }

    private function currentTenant(): Tenant
    {
        $tenantId = app(TenantContext::class)->id();
        abort_if($tenantId === null, 403);
        $tenant = Tenant::query()->find($tenantId);
        abort_unless($tenant instanceof Tenant, 404);

        return $tenant;
    }
}
