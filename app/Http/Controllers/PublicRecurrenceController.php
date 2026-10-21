<?php

namespace App\Http\Controllers;

use App\Application\Agenda\Actions\ListAvailableSlots;
use App\Application\Recurrence\Actions\CreateSubscription;
use App\Application\Recurrence\Actions\ScheduleSubscriptionCut;
use App\Application\Recurrence\RecurrenceQuota;
use App\Domain\Tenant\Models\Tenant;
use App\Models\Barber;
use App\Models\RecurrencePlan;
use App\Models\RecurrenceSubscription;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Recorrência no site público.
 *
 * O portal do cliente é o link com o `public_id` da assinatura — não existe
 * login de cliente no MVP. O lookup sai do escopo do tenant declarado pelo
 * `SetPublicBookingTenant`, então um uuid de outra barbearia responde 404, no
 * mesmo padrão do restante do booking.
 *
 * ATENÇÃO: o Laravel entrega os parâmetros de rota aos métodos do controller
 * **posicionalmente** (`array_values`), não pelo nome. Como as rotas daqui
 * herdam o prefixo `/agendar/{tenant}`, todo método com um id escalar precisa
 * declarar `string $tenantSlug` antes dele — sem isso, o primeiro escalar recebe
 * o slug do tenant e o id real é ignorado (`WHERE public_id = '<slug>'`).
 */
class PublicRecurrenceController extends Controller
{
    public function __construct(private readonly RecurrenceQuota $quota) {}

    public function plans(Request $request): View
    {
        $tenant = $this->tenant($request);

        $plans = RecurrencePlan::query()
            ->where('is_active', true)
            ->with('service:id,name,duration_minutes,price')
            ->orderBy('price')
            ->get();

        return view('booking.plans', compact('tenant', 'plans'));
    }

    public function show(Request $request, string $tenantSlug, string $planId): View
    {
        abort_unless(ctype_digit($planId), 404);

        $plan = RecurrencePlan::query()->findOrFail($planId);
        abort_unless($plan->is_active, 404);

        $tenant = $this->tenant($request);

        $barbers = $this->bookableBarbers($plan->service_id);

        return view('booking.plan-show', compact('tenant', 'plan', 'barbers'));
    }

    public function subscribe(
        Request $request,
        string $tenantSlug,
        string $planId,
        CreateSubscription $createSubscription,
    ): RedirectResponse {
        abort_unless(ctype_digit($planId), 404);

        $plan = RecurrencePlan::query()->findOrFail($planId);
        abort_unless($plan->is_active, 404);

        $tenant = $this->tenant($request);

        $phone = preg_replace('/\D+/', '', (string) $request->input('customer_phone'));
        $request->merge(['customer_phone' => $phone]);

        $attributes = $request->validate([
            'barber_id' => ['required', 'integer', Rule::exists('barbers', 'id')],
            'date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'time' => ['required', 'date_format:H:i'],
            'customer_name' => ['required', 'string', 'max:150'],
            'customer_phone' => ['required', 'regex:/^\d{10,11}$/'],
        ], [
            'customer_phone.regex' => 'Informe um telefone com DDD válido.',
        ]);

        $result = $createSubscription->execute([
            'plan_id' => $plan->getKey(),
            'starts_at' => $attributes['date'],
            'customer_name' => $attributes['customer_name'],
            'customer_phone' => $attributes['customer_phone'],
            'barber_id' => (int) $attributes['barber_id'],
            'first_appointment_date' => $attributes['date'],
            'first_appointment_time' => $attributes['time'],
        ]);

        return redirect()
            ->route('booking.subscription.manage', [
                'tenant' => $tenant->slug,
                'subscriptionPublicId' => $result['subscription']->public_id,
            ])
            ->with('booking_status', 'Plano criado! Assim que a barbearia confirmar seu pagamento, seu plano fica ativo.');
    }

    public function manage(Request $request, string $tenantSlug, string $subscriptionPublicId): View
    {
        $subscription = $this->subscription($subscriptionPublicId);

        $tenant = $this->tenant($request);

        $subscription->load(['customer:id,name', 'service:id,name,duration_minutes,price', 'barber:id,name']);

        $appointments = $subscription->appointments()
            ->with('barber:id,name')
            ->orderByDesc('starts_at')
            ->limit(12)
            ->get();

        $today = CarbonImmutable::now(config('app.timezone'));

        return view('booking.manage', [
            'tenant' => $tenant,
            'subscription' => $subscription,
            'appointments' => $appointments,
            'barbers' => $this->bookableBarbers($subscription->service_id),
            'contractMonths' => $subscription->contractMonths(),
            'usedInMonth' => $this->quota->usedInMonth($subscription, $today),
            'remainingInMonth' => $this->quota->remainingInMonth($subscription, $today),
            'usedInTerm' => $this->quota->usedInTerm($subscription),
            'remainingInTerm' => $this->quota->remainingInTerm($subscription),
            'monthLabel' => $today->locale('pt_BR')->isoFormat('MMMM'),
            'canSchedule' => $subscription->isSchedulable(),
        ]);
    }

    public function availability(
        Request $request,
        string $tenantSlug,
        string $subscriptionPublicId,
        ListAvailableSlots $listAvailableSlots,
    ): JsonResponse {
        $subscription = $this->subscription($subscriptionPublicId);

        $attributes = $request->validate([
            'barber_id' => ['required', 'integer', 'min:1'],
            'date' => ['required', 'date_format:Y-m-d'],
        ]);

        try {
            $date = CarbonImmutable::createFromFormat(
                '!Y-m-d',
                $attributes['date'],
                config('app.timezone'),
            );
        } catch (InvalidFormatException) {
            return response()->json(['data' => [], 'reason' => 'Selecione uma data válida.']);
        }

        try {
            $this->quota->assertCanSchedule($subscription, $date);
        } catch (ValidationException $exception) {
            return response()->json([
                'data' => [],
                'reason' => collect($exception->errors())->flatten()->first(),
            ]);
        }

        $slots = $listAvailableSlots->execute([
            'barber_id' => (int) $attributes['barber_id'],
            'service_id' => (int) $subscription->service_id,
            'date' => $attributes['date'],
        ])['slots'];

        return response()->json(['data' => $slots, 'reason' => null]);
    }

    public function storeCut(
        Request $request,
        string $tenantSlug,
        string $subscriptionPublicId,
        ScheduleSubscriptionCut $scheduleCut,
    ): RedirectResponse {
        $subscription = $this->subscription($subscriptionPublicId);

        $tenant = $this->tenant($request);

        $attributes = $request->validate([
            'barber_id' => ['required', 'integer', 'min:1'],
            'date' => ['required', 'date_format:Y-m-d'],
            'time' => ['required', 'date_format:H:i'],
        ]);

        $scheduleCut->execute([
            'subscription' => $subscription,
            'barber_id' => (int) $attributes['barber_id'],
            'date' => $attributes['date'],
            'time' => $attributes['time'],
        ]);

        return redirect()
            ->route('booking.subscription.manage', [
                'tenant' => $tenant->slug,
                'subscriptionPublicId' => $subscription->public_id,
            ])
            ->with('booking_status', 'Corte agendado!');
    }

    /**
     * @return Collection<int, Barber>
     */
    private function bookableBarbers(int $serviceId): Collection
    {
        return Barber::query()
            ->where('is_active', true)
            ->whereNotNull('user_id')
            ->whereHas('businessHours')
            ->whereHas('services', function ($query) use ($serviceId): void {
                $query->where('services.id', $serviceId)
                    ->where('services.is_active', true);
            })
            ->orderBy('name')
            ->get(['id', 'name', 'bio']);
    }

    /**
     * O portal é identificado por uuid. Um id malformado (link digitado à mão)
     * precisa cair em 404 — sem isso o Postgres estoura com QueryException
     * (`invalid input syntax for type uuid`) antes de qualquer lookup.
     */
    private function subscription(string $subscriptionPublicId): RecurrenceSubscription
    {
        abort_unless(Str::isUuid($subscriptionPublicId), 404);

        return RecurrenceSubscription::query()
            ->where('public_id', $subscriptionPublicId)
            ->firstOrFail();
    }

    private function tenant(Request $request): Tenant
    {
        $tenant = $request->attributes->get('public_booking_tenant');
        abort_unless($tenant instanceof Tenant, 404);

        return $tenant;
    }
}
