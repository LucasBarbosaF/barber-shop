<?php

namespace App\Http\Controllers\App;

use App\Application\Recurrence\Actions\ActivateSubscription;
use App\Application\Recurrence\Actions\CancelSubscription;
use App\Application\Recurrence\Actions\CreateRecurrencePlan;
use App\Application\Recurrence\Actions\CreateSubscription;
use App\Application\Recurrence\Actions\DeleteRecurrencePlan;
use App\Application\Recurrence\Actions\ListRecurrencePlans;
use App\Application\Recurrence\Actions\ListSubscriptions;
use App\Application\Recurrence\Actions\PostSubscriptionCash;
use App\Application\Recurrence\Actions\RecordSubscriptionPayment;
use App\Application\Recurrence\Actions\ScheduleSubscriptionCut;
use App\Application\Recurrence\Actions\UpdateRecurrencePlan;
use App\Application\Recurrence\RecurrenceQuota;
use App\Application\Shared\Tenancy\TenantContext;
use App\Domain\Payments\Enums\PaymentMethod;
use App\Domain\Recurrence\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\Barber;
use App\Models\Customer;
use App\Models\RecurrencePlan;
use App\Models\RecurrenceSubscription;
use App\Models\Service;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class RecurrenceController extends Controller
{
    public function index(
        Request $request,
        ListRecurrencePlans $listPlans,
        ListSubscriptions $listSubscriptions,
    ): View {
        $this->authorize('viewAny', RecurrencePlan::class);

        $tab = $request->query('tab') === 'assinaturas' ? 'assinaturas' : 'planos';

        $plans = $listPlans->execute([
            'search' => $request->query('plan_search'),
        ])['plans'];

        $subscriptions = $listSubscriptions->execute([
            'status' => $request->query('status'),
            'search' => $request->query('subscription_search'),
        ])['subscriptions'];

        return view('app.recurrence.index', [
            'tab' => $tab,
            'plans' => $plans,
            'subscriptions' => $subscriptions,
            'activePlans' => $this->activePlans(),
            'customers' => $this->customers(),
            'barbers' => $this->bookableBarbers(),
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', RecurrencePlan::class);

        $services = Service::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'price']);

        return view('app.recurrence.create', ['services' => $services]);
    }

    public function store(
        Request $request,
        CreateRecurrencePlan $createPlan,
        TenantContext $tenantContext,
    ): RedirectResponse {
        $this->authorize('create', RecurrencePlan::class);

        $plan = $createPlan->execute($this->validatedPlan($request, $tenantContext))['plan'];

        return redirect()
            ->route('app.recurrence.edit', $plan)
            ->with('status', 'Plano de recorrência cadastrado com sucesso.');
    }

    public function edit(RecurrencePlan $plan, Request $request): View
    {
        $this->authorize('update', $plan);

        $services = Service::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'price']);

        return view('app.recurrence.edit', [
            'plan' => $plan,
            'services' => $services,
            'tab' => $request->query('tab'),
        ]);
    }

    public function update(
        Request $request,
        RecurrencePlan $plan,
        UpdateRecurrencePlan $updatePlan,
        TenantContext $tenantContext,
    ): RedirectResponse {
        $this->authorize('update', $plan);

        $updatePlan->execute([
            'plan' => $plan,
            'attributes' => $this->validatedPlan($request, $tenantContext, $plan),
        ]);

        return redirect()
            ->route('app.recurrence.index')
            ->with('status', 'Plano de recorrência atualizado com sucesso.');
    }

    public function destroy(RecurrencePlan $plan, DeleteRecurrencePlan $deletePlan): RedirectResponse
    {
        $this->authorize('delete', $plan);

        $deletePlan->execute(['plan' => $plan]);

        return redirect()
            ->route('app.recurrence.index')
            ->with('status', 'Plano de recorrência excluído com sucesso.');
    }

    public function showSubscription(
        RecurrenceSubscription $subscription,
        RecurrenceQuota $quota,
    ): View {
        $this->authorize('view', $subscription);

        $subscription->load(['customer', 'plan', 'barber', 'service']);

        $appointments = $subscription->appointments()
            ->with('barber:id,name')
            ->orderByDesc('starts_at')
            ->limit(30)
            ->get();

        $today = CarbonImmutable::now(config('app.timezone'));

        return view('app.recurrence.subscription', [
            'subscription' => $subscription,
            'appointments' => $appointments,
            'barbers' => $this->bookableBarbers(),
            'paymentMethods' => PaymentMethod::cases(),
            'contractMonths' => $subscription->contractMonths(),
            'unpostedPayments' => $subscription->payments()
                ->where('status', PaymentStatus::Paid)
                ->whereNull('cash_posted_at')
                ->orderBy('period_month')
                ->get(),
            'usedInMonth' => $quota->usedInMonth($subscription, $today),
            'usedInWeek' => $quota->usedInWeek($subscription, $today),
            'usedInTerm' => $quota->usedInTerm($subscription),
            'monthLabel' => $today->locale('pt_BR')->isoFormat('MMMM'),
        ]);
    }

    public function storeSubscription(
        Request $request,
        CreateSubscription $createSubscription,
    ): RedirectResponse {
        $this->authorize('create', RecurrenceSubscription::class);

        $attributes = $request->validate([
            'plan_id' => [
                'required',
                'integer',
                Rule::exists('recurrence_plans', 'id')
                    ->where(fn ($query) => $query->where('is_active', true)),
            ],
            'customer_id' => ['nullable', 'integer', Rule::exists('customers', 'id')],
            'customer_name' => ['required_without:customer_id', 'nullable', 'string', 'max:150'],
            'customer_phone' => [
                'required_without:customer_id',
                'nullable',
                'regex:/^\d{10,11}$/',
            ],
            'barber_id' => ['nullable', 'integer', Rule::exists('barbers', 'id')],
            'starts_at' => ['required', 'date'],
            'first_appointment_date' => ['required_with:first_appointment_time', 'nullable', 'date', 'after_or_equal:starts_at'],
            'first_appointment_time' => ['nullable', 'date_format:H:i'],
        ], [
            'customer_phone.regex' => 'Informe um telefone com DDD válido.',
            'first_appointment_time.date_format' => 'Selecione um horário válido.',
        ]);

        $result = $createSubscription->execute([
            'plan_id' => (int) $attributes['plan_id'],
            'starts_at' => $attributes['starts_at'],
            'customer_id' => isset($attributes['customer_id']) ? (int) $attributes['customer_id'] : null,
            'customer_name' => $attributes['customer_name'] ?? null,
            'customer_phone' => $attributes['customer_phone'] ?? null,
            'barber_id' => isset($attributes['barber_id']) ? (int) $attributes['barber_id'] : null,
            'first_appointment_date' => $attributes['first_appointment_date'] ?? null,
            'first_appointment_time' => $attributes['first_appointment_time'] ?? null,
            'created_by' => $request->user()?->getKey(),
        ]);

        return redirect()
            ->route('app.recurrence.subscriptions.show', $result['subscription'])
            ->with('status', 'Assinatura criada. Confirme o pagamento para ativá-la.');
    }

    public function activate(
        Request $request,
        RecurrenceSubscription $subscription,
        ActivateSubscription $activate,
    ): RedirectResponse {
        $this->authorize('update', $subscription);

        $attributes = $request->validate([
            'amount' => ['required', 'numeric', 'decimal:0,2', 'min:0.01'],
            'method' => ['required', Rule::in(array_column(PaymentMethod::cases(), 'value'))],
            'obs' => ['nullable', 'string', 'max:255'],
        ]);

        $result = $activate->execute([
            'subscription' => $subscription,
            'amount' => $attributes['amount'],
            'method' => $attributes['method'],
            'obs' => $attributes['obs'] ?? null,
            'actor_id' => $request->user()?->getKey(),
        ]);

        $message = $result['cash_posted']
            ? 'Pagamento confirmado. Assinatura ativa e valor lançado no caixa.'
            : 'Pagamento confirmado. Assinatura ativa — não há caixa aberto para lançar a entrada agora.';

        return redirect()
            ->route('app.recurrence.subscriptions.show', $result['subscription'])
            ->with('status', $message);
    }

    public function storeCash(
        Request $request,
        RecurrenceSubscription $subscription,
        PostSubscriptionCash $postCash,
    ): RedirectResponse {
        $this->authorize('update', $subscription);

        $result = $postCash->execute([
            'subscription' => $subscription,
            'actor_id' => $request->user()?->getKey(),
        ]);

        return redirect()
            ->route('app.recurrence.subscriptions.show', $result['subscription'])
            ->with('status', 'Pagamento lançado no caixa.');
    }

    public function storePayment(
        Request $request,
        RecurrenceSubscription $subscription,
        RecordSubscriptionPayment $recordPayment,
    ): RedirectResponse {
        $this->authorize('update', $subscription);

        $attributes = $request->validate([
            'period_month' => ['required', 'date', 'date_format:Y-m-d'],
            'amount' => ['required', 'numeric', 'decimal:0,2', 'min:0.01'],
            'method' => ['required', Rule::in(array_column(PaymentMethod::cases(), 'value'))],
            'obs' => ['nullable', 'string', 'max:255'],
        ]);

        $result = $recordPayment->execute([
            'subscription' => $subscription,
            'period_month' => $attributes['period_month'],
            'amount' => $attributes['amount'],
            'method' => $attributes['method'],
            'obs' => $attributes['obs'] ?? null,
            'actor_id' => $request->user()?->getKey(),
        ]);

        $message = $result['cash_posted']
            ? 'Pagamento do mês registrado e lançado no caixa.'
            : 'Pagamento do mês registrado. Não há caixa aberto para lançar a entrada agora.';

        return redirect()
            ->route('app.recurrence.subscriptions.show', $subscription)
            ->with('status', $message);
    }

    public function storeCut(
        Request $request,
        RecurrenceSubscription $subscription,
        ScheduleSubscriptionCut $scheduleCut,
    ): RedirectResponse {
        $this->authorize('update', $subscription);

        $attributes = $request->validate([
            'barber_id' => ['required', 'integer', Rule::exists('barbers', 'id')],
            'date' => ['required', 'date_format:Y-m-d'],
            'time' => ['required', 'date_format:H:i'],
        ]);

        $scheduleCut->execute([
            'subscription' => $subscription,
            'barber_id' => (int) $attributes['barber_id'],
            'date' => $attributes['date'],
            'time' => $attributes['time'],
            'actor_id' => $request->user()?->getKey(),
        ]);

        return redirect()
            ->route('app.recurrence.subscriptions.show', $subscription)
            ->with('status', 'Corte agendado.');
    }

    public function cancel(
        Request $request,
        RecurrenceSubscription $subscription,
        CancelSubscription $cancel,
    ): RedirectResponse {
        $this->authorize('update', $subscription);

        $attributes = $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
            'cancel_future_appointments' => ['sometimes', 'boolean'],
        ]);

        $result = $cancel->execute([
            'subscription' => $subscription,
            'reason' => $attributes['reason'] ?? null,
            'cancel_future_appointments' => (bool) ($attributes['cancel_future_appointments'] ?? false),
        ]);

        return redirect()
            ->route('app.recurrence.index', ['tab' => 'assinaturas'])
            ->with('status', sprintf(
                'Assinatura cancelada.%s',
                $result['canceled_appointments'] > 0
                    ? sprintf(' %d corte(s) futuro(s) também cancelado(s).', $result['canceled_appointments'])
                    : '',
            ));
    }

    /**
     * @return Collection<int, RecurrencePlan>
     */
    private function activePlans()
    {
        return RecurrencePlan::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'price', 'duration_months', 'sessions_per_month']);
    }

    /**
     * @return Collection<int, Customer>
     */
    private function customers()
    {
        return Customer::query()
            ->orderBy('name')
            ->get(['id', 'name', 'phone']);
    }

    /**
     * @return Collection<int, Barber>
     */
    private function bookableBarbers()
    {
        return Barber::query()
            ->where('is_active', true)
            ->whereNotNull('user_id')
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedPlan(Request $request, TenantContext $tenantContext, ?RecurrencePlan $plan = null): array
    {
        $uniqueName = Rule::unique('recurrence_plans', 'name')
            ->where('tenant_id', $tenantContext->id());

        if ($plan !== null) {
            $uniqueName = $uniqueName->ignore($plan->getKey());
        }

        return $request->validate([
            'name' => ['required', 'string', 'max:150', $uniqueName],
            'description' => ['nullable', 'string', 'max:10000'],
            'service_id' => [
                'required',
                'integer',
                Rule::exists('services', 'id')->where('tenant_id', $tenantContext->id()),
            ],
            'duration_months' => ['required', 'integer', 'between:1,60'],
            'sessions_per_month' => ['required', 'integer', 'between:1,4'],
            'max_sessions_per_week' => ['sometimes', 'required', 'integer', 'between:1,2'],
            'price' => ['required', 'numeric', 'decimal:0,2', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }
}
