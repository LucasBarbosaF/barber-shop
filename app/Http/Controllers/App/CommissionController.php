<?php

namespace App\Http\Controllers\App;

use App\Application\Commissions\Actions\CloseCommissionPeriod;
use App\Application\Commissions\Actions\PayCommissions;
use App\Application\Shared\Tenancy\TenantContext;
use App\Domain\Commissions\Enums\CommissionCalculationType;
use App\Http\Controllers\Controller;
use App\Models\Barber;
use App\Models\Commission;
use App\Models\CommissionPeriod;
use App\Models\CommissionRule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class CommissionController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('commissions.view');

        $filters = $request->validate([
            'period_id' => ['nullable', 'integer', Rule::exists('commission_periods', 'id')],
            'barber_id' => ['nullable', 'integer', Rule::exists('barbers', 'id')],
            'status' => ['nullable', Rule::in(['pending', 'paid'])],
        ]);
        $selectedPeriodId = isset($filters['period_id']) ? (int) $filters['period_id'] : null;
        $selectedBarberId = isset($filters['barber_id']) ? (int) $filters['barber_id'] : null;
        $selectedStatus = $filters['status'] ?? null;

        $filterCommissions = static function (Builder $query) use ($selectedBarberId, $selectedStatus): void {
            if ($selectedBarberId !== null) {
                $query->where('barber_id', $selectedBarberId);
            }

            if ($selectedStatus === 'pending') {
                $query->whereNull('commission_payment_id')->where('amount', '>', 0);
            } elseif ($selectedStatus === 'paid') {
                $query->whereNotNull('commission_payment_id');
            }
        };

        $periodsQuery = CommissionPeriod::query()
            ->with(['commissions', 'payments.barber'])
            ->withSum('commissions', 'amount')
            ->latest('period_start');
        if ($selectedPeriodId !== null) {
            $periodsQuery->whereKey($selectedPeriodId);
        }
        if ($selectedBarberId !== null || $selectedStatus !== null) {
            $periodsQuery->whereHas('commissions', $filterCommissions);
        }
        $periods = $periodsQuery->limit(50)->get();

        $commissionsQuery = Commission::query()
            ->with(['period', 'payment'])
            ->when($selectedPeriodId !== null, fn (Builder $query) => $query->where('period_id', $selectedPeriodId));
        $filterCommissions($commissionsQuery);

        $pendingByPeriod = Commission::query()
            ->select('period_id', 'barber_id')
            ->selectRaw('MAX(barber_name_snapshot) AS barber_name_snapshot')
            ->selectRaw('SUM(amount) AS amount')
            ->whereIn('period_id', $periods->modelKeys())
            ->whereNull('commission_payment_id')
            ->where('amount', '>', 0)
            ->when($selectedBarberId !== null, fn (Builder $query) => $query->where('barber_id', $selectedBarberId))
            ->groupBy('period_id', 'barber_id')
            ->get()
            ->groupBy('period_id');

        $summary = (clone $commissionsQuery)
            ->selectRaw('COUNT(*) AS count, COALESCE(SUM(amount), 0.00)::numeric(12, 2) AS total')
            ->selectRaw('COALESCE(SUM(CASE WHEN commission_payment_id IS NULL AND amount > 0 THEN amount ELSE 0 END), 0.00)::numeric(12, 2) AS pending')
            ->first();

        return view('app.commissions.index', [
            'commissions' => $commissionsQuery->latest()->limit(100)->get(),
            'periods' => $periods,
            'pendingByPeriod' => $pendingByPeriod,
            'periodOptions' => CommissionPeriod::query()->latest('period_start')->limit(50)->get(['id', 'period_start', 'period_end']),
            'barbers' => Barber::query()->orderBy('name')->get(['id', 'name']),
            'filters' => [
                'period_id' => $selectedPeriodId,
                'barber_id' => $selectedBarberId,
                'status' => $selectedStatus,
            ],
            'summary' => $summary,
        ]);
    }

    public function storeRule(Request $request, TenantContext $tenantContext): JsonResponse|RedirectResponse
    {
        $this->authorize('commissions.manage');

        $tenantId = $tenantContext->id();
        abort_if($tenantId === null, 403);

        $attributes = $request->validate([
            'barber_id' => [
                'required',
                'integer',
                Rule::exists('barbers', 'id')->where('tenant_id', $tenantId)->where('is_active', true),
            ],
            'service_id' => [
                'required',
                'integer',
                Rule::exists('services', 'id')->where('tenant_id', $tenantId)->where('is_active', true),
            ],
            'calculation_type' => ['required', Rule::enum(CommissionCalculationType::class)],
            'percentage' => [
                Rule::requiredIf($request->input('calculation_type') === CommissionCalculationType::Percentage->value),
                'nullable',
                'numeric',
                'decimal:0,2',
                'between:0,100',
            ],
            'fixed_amount' => [
                Rule::requiredIf($request->input('calculation_type') === CommissionCalculationType::FixedAmount->value),
                'nullable',
                'numeric',
                'gt:0',
                'decimal:0,2',
            ],
        ]);

        $barber = Barber::query()->findOrFail($attributes['barber_id']);
        if (! $barber->services()->whereKey($attributes['service_id'])->exists()) {
            throw ValidationException::withMessages([
                'service_id' => 'O barbeiro não está habilitado para esse serviço.',
            ]);
        }

        $rule = CommissionRule::query()
            ->where('barber_id', $barber->getKey())
            ->where('service_id', $attributes['service_id'])
            ->first();

        if ($rule === null) {
            $rule = CommissionRule::query()->create([
                'barber_id' => $barber->getKey(),
                'service_id' => $attributes['service_id'],
                'calculation_type' => $attributes['calculation_type'],
                'percentage' => $attributes['calculation_type'] === CommissionCalculationType::Percentage->value
                    ? $attributes['percentage']
                    : null,
                'fixed_amount' => $attributes['calculation_type'] === CommissionCalculationType::FixedAmount->value
                    ? $attributes['fixed_amount']
                    : null,
            ]);
        } else {
            $rule->update([
                'calculation_type' => $attributes['calculation_type'],
                'percentage' => $attributes['calculation_type'] === CommissionCalculationType::Percentage->value
                    ? $attributes['percentage']
                    : null,
                'fixed_amount' => $attributes['calculation_type'] === CommissionCalculationType::FixedAmount->value
                    ? $attributes['fixed_amount']
                    : null,
            ]);
        }

        if (! $request->expectsJson()) {
            return redirect()->route('app.commissions.index')->with('status', 'Regra de comissão salva.');
        }

        return response()->json(['data' => $rule], 201);
    }

    public function destroyRule(CommissionRule $rule): RedirectResponse|JsonResponse
    {
        $this->authorize('commissions.manage');

        if ($rule->service_id === null) {
            throw ValidationException::withMessages([
                'rule' => 'A taxa padrão deve ser alterada pelo cadastro do barbeiro.',
            ]);
        }

        $rule->delete();

        if (! request()->expectsJson()) {
            return redirect()->route('app.commissions.index')->with('status', 'Regra específica removida.');
        }

        return response()->json(status: 204);
    }

    public function closePeriod(Request $request, CloseCommissionPeriod $closeCommissionPeriod): JsonResponse|RedirectResponse
    {
        $this->authorize('commissions.manage');

        $attributes = $request->validate([
            'period_start' => ['required', 'date_format:Y-m-d'],
            'period_end' => ['required', 'date_format:Y-m-d', 'after_or_equal:period_start'],
        ]);

        $result = $closeCommissionPeriod->execute([
            'period_start' => $attributes['period_start'],
            'period_end' => $attributes['period_end'],
            'actor_id' => $request->user()?->getKey(),
        ]);

        if (! $request->expectsJson()) {
            return redirect()->route('app.commissions.index')
                ->with('status', 'Período fechado; '.$result['commissions'].' comissão(ões) apurada(s).');
        }

        return response()->json(['data' => $result], 201);
    }

    public function pay(
        Request $request,
        CommissionPeriod $period,
        PayCommissions $payCommissions,
        TenantContext $tenantContext,
    ): JsonResponse|RedirectResponse {
        $this->authorize('commissions.manage');
        $tenantId = $tenantContext->id();
        abort_if($tenantId === null, 403);
        $request->merge(['idempotency_key' => $request->input('idempotency_key') ?? $request->header('Idempotency-Key')]);

        $attributes = $request->validate([
            'barber_id' => [
                'required',
                'integer',
                Rule::exists('barbers', 'id')->where('tenant_id', $tenantId),
            ],
            'idempotency_key' => ['required', 'string', 'max:100'],
        ]);

        $result = $payCommissions->execute([
            'period' => $period,
            'barber_id' => (int) $attributes['barber_id'],
            'idempotency_key' => $attributes['idempotency_key'],
            'actor_id' => $request->user()?->getKey(),
        ]);

        if (! $request->expectsJson()) {
            return redirect()->route('app.commissions.index')->with('status', 'Repasse registrado.');
        }

        return response()->json(['data' => $result['payment']], 201);
    }
}
