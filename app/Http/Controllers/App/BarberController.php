<?php

namespace App\Http\Controllers\App;

use App\Application\Barbers\Actions\CreateBarber;
use App\Application\Barbers\Actions\DeleteBarber;
use App\Application\Barbers\Actions\ListBarbers;
use App\Application\Barbers\Actions\UpdateBarber;
use App\Application\Shared\Tenancy\TenantContext;
use App\Domain\Commissions\Enums\CommissionCalculationType;
use App\Domain\Tenant\Enums\MembershipRole;
use App\Domain\Tenant\Models\Membership;
use App\Http\Controllers\Controller;
use App\Models\Barber;
use App\Models\CommissionRule;
use App\Models\Service;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class BarberController extends Controller
{
    public function index(Request $request, ListBarbers $listBarbers): JsonResponse|View
    {
        $this->authorize('viewAny', Barber::class);

        $barbers = $listBarbers->execute([])['barbers'];

        if ($request->expectsJson()) {
            return response()->json($barbers);
        }

        return view('app.barbers.index', ['barbers' => $barbers]);
    }

    public function create(): View
    {
        $this->authorize('create', Barber::class);

        return view('app.barbers.create', [
            'barberUsers' => $this->availableBarberUsers(),
            'businessHours' => collect(),
            'services' => Service::query()->where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    public function edit(Barber $barber): View
    {
        $this->authorize('update', $barber);

        return view('app.barbers.edit', [
            'barber' => $barber,
            'barberUsers' => $this->availableBarberUsers((int) $barber->getKey()),
            'businessHours' => $barber->businessHours()->get()->keyBy('weekday'),
            'services' => Service::query()->where('is_active', true)->orderBy('name')->get(),
            'selectedServiceIds' => $barber->services()
                ->pluck('services.id')
                ->map(fn ($id): string => (string) $id)
                ->all(),
        ]);
    }

    public function show(Barber $barber): JsonResponse
    {
        $this->authorize('view', $barber);

        return response()->json(['data' => $barber]);
    }

    public function store(
        Request $request,
        CreateBarber $createBarber,
        TenantContext $tenantContext,
    ): JsonResponse|RedirectResponse {
        $this->authorize('create', Barber::class);

        $attributes = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'phone' => [
                'required',
                'string',
                'max:32',
                Rule::unique('barbers', 'phone')->where('tenant_id', $tenantContext->id()),
            ],
            'email' => ['nullable', 'email', 'max:255'],
            'bio' => ['nullable', 'string', 'max:10000'],
            'is_active' => ['sometimes', 'boolean'],
            'commission_percentage' => ['nullable', 'numeric', 'decimal:0,2', 'between:0,100'],
            'user_id' => [
                'nullable',
                'integer',
                Rule::exists('memberships', 'user_id')
                    ->where('tenant_id', $tenantContext->id())
                    ->where('role', MembershipRole::Barber->value)
                    ->where('is_active', true),
                Rule::unique('barbers', 'user_id')->where('tenant_id', $tenantContext->id()),
            ],
            'business_hours' => ['sometimes', 'array'],
            'business_hours.*.opens_at' => ['nullable', 'date_format:H:i', 'required_with:business_hours.*.closes_at'],
            'business_hours.*.closes_at' => ['nullable', 'date_format:H:i', 'required_with:business_hours.*.opens_at'],
            'service_ids' => ['required', 'array', 'min:1'],
            'service_ids.*' => [
                'required',
                'integer',
                'distinct',
                Rule::exists('services', 'id')
                    ->where('tenant_id', $tenantContext->id())
                    ->where('is_active', true),
            ],
        ]);

        $businessHours = $attributes['business_hours'] ?? [];
        $serviceIds = array_map('intval', $attributes['service_ids']);
        unset($attributes['business_hours'], $attributes['service_ids']);
        $barber = $createBarber->execute($attributes)['barber'];
        $barber->services()->syncWithPivotValues($serviceIds, [
            'tenant_id' => $tenantContext->id(),
        ]);
        $this->saveBusinessHours($barber, $businessHours);
        $this->syncDefaultCommissionRule($barber, $attributes['commission_percentage'] ?? null);

        if (! $request->expectsJson()) {
            return redirect()
                ->route('app.barbers.index')
                ->with('status', 'Barbeiro cadastrado com sucesso.');
        }

        return response()->json(['data' => $barber], 201);
    }

    public function update(
        Request $request,
        Barber $barber,
        UpdateBarber $updateBarber,
        TenantContext $tenantContext,
    ): JsonResponse|RedirectResponse {
        $this->authorize('update', $barber);
        $barber = Barber::query()
            ->whereKey($barber->getKey())
            ->lockForUpdate()
            ->firstOrFail();
        if ($request->exists('services_present') && ! $request->exists('service_ids')) {
            $request->merge(['service_ids' => []]);
        }

        $attributes = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:150'],
            'phone' => [
                'sometimes',
                'required',
                'string',
                'max:32',
                Rule::unique('barbers', 'phone')
                    ->where('tenant_id', $tenantContext->id())
                    ->ignore($barber->getKey()),
            ],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'bio' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'is_active' => ['sometimes', 'boolean'],
            'commission_percentage' => ['sometimes', 'nullable', 'numeric', 'decimal:0,2', 'between:0,100'],
            'user_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('memberships', 'user_id')
                    ->where('tenant_id', $tenantContext->id())
                    ->where('role', MembershipRole::Barber->value)
                    ->where('is_active', true),
                Rule::unique('barbers', 'user_id')
                    ->where('tenant_id', $tenantContext->id())
                    ->ignore($barber->getKey()),
            ],
            'business_hours' => ['sometimes', 'array'],
            'business_hours.*.opens_at' => ['nullable', 'date_format:H:i', 'required_with:business_hours.*.closes_at'],
            'business_hours.*.closes_at' => ['nullable', 'date_format:H:i', 'required_with:business_hours.*.opens_at'],
            'service_ids' => ['sometimes', 'array', 'min:1'],
            'service_ids.*' => [
                'required',
                'integer',
                'distinct',
                Rule::exists('services', 'id')
                    ->where('tenant_id', $tenantContext->id())
                    ->where('is_active', true),
            ],
        ]);

        $businessHours = $attributes['business_hours'] ?? null;
        $serviceIds = isset($attributes['service_ids']) ? array_map('intval', $attributes['service_ids']) : null;
        unset($attributes['business_hours']);
        unset($attributes['service_ids']);
        $updated = $updateBarber->execute([
            'barber' => $barber,
            'attributes' => $attributes,
        ])['barber'];
        if (array_key_exists('commission_percentage', $attributes)) {
            $this->syncDefaultCommissionRule($updated, $attributes['commission_percentage']);
        }
        if ($serviceIds !== null) {
            $updated->services()->syncWithPivotValues($serviceIds, [
                'tenant_id' => $tenantContext->id(),
            ]);
        }
        if ($businessHours !== null) {
            $this->saveBusinessHours($updated, $businessHours);
        }

        if (! $request->expectsJson()) {
            return redirect()
                ->route('app.barbers.index')
                ->with('status', 'Barbeiro atualizado com sucesso.');
        }

        return response()->json(['data' => $updated]);
    }

    public function destroy(
        Request $request,
        Barber $barber,
        DeleteBarber $deleteBarber,
    ): JsonResponse|RedirectResponse {
        $this->authorize('delete', $barber);
        $deleteBarber->execute(['barber' => $barber]);

        if (! $request->expectsJson()) {
            return redirect()
                ->route('app.barbers.index')
                ->with('status', 'Barbeiro excluído com sucesso.');
        }

        return response()->json(status: 204);
    }

    /**
     * @return Collection<int, Membership>
     */
    private function availableBarberUsers(?int $currentBarberId = null): Collection
    {
        $assignedUserIds = Barber::query()
            ->whereNotNull('user_id')
            ->when($currentBarberId !== null, fn ($query) => $query->whereKeyNot($currentBarberId))
            ->pluck('user_id');

        return Membership::query()
            ->where('tenant_id', app(TenantContext::class)->id())
            ->where('role', MembershipRole::Barber->value)
            ->where('is_active', true)
            ->whereNotIn('user_id', $assignedUserIds)
            ->with('user')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  array<int, array{opens_at?: string|null, closes_at?: string|null}>  $hours
     */
    private function saveBusinessHours(Barber $barber, array $hours): void
    {
        if (array_diff(array_keys($hours), range(0, 6)) !== []) {
            throw ValidationException::withMessages([
                'business_hours' => 'Informe horários para os dias válidos da semana.',
            ]);
        }

        $barber->businessHours()->delete();

        foreach ($hours as $weekday => $period) {
            $opensAt = $period['opens_at'] ?? null;
            $closesAt = $period['closes_at'] ?? null;

            if ($opensAt === null && $closesAt === null) {
                continue;
            }

            if ($opensAt === null || $closesAt === null || $opensAt >= $closesAt) {
                throw ValidationException::withMessages([
                    "business_hours.{$weekday}.closes_at" => 'O horário de encerramento deve ser posterior ao de abertura.',
                ]);
            }

            $barber->businessHours()->create([
                'weekday' => $weekday,
                'opens_at' => $opensAt,
                'closes_at' => $closesAt,
            ]);
        }
    }

    private function syncDefaultCommissionRule(Barber $barber, ?string $percentage): void
    {
        $rule = CommissionRule::query()
            ->where('barber_id', $barber->getKey())
            ->whereNull('service_id')
            ->first();

        if ($percentage === null) {
            $rule?->delete();

            return;
        }

        if ($rule === null) {
            CommissionRule::query()->create([
                'barber_id' => $barber->getKey(),
                'calculation_type' => CommissionCalculationType::Percentage,
                'percentage' => $percentage,
            ]);

            return;
        }

        $rule->update([
            'calculation_type' => CommissionCalculationType::Percentage,
            'percentage' => $percentage,
            'fixed_amount' => null,
        ]);
    }
}
