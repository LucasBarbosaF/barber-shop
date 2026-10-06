<?php

namespace App\Http\Controllers\App;

use App\Application\Shared\Tenancy\TenantContext;
use App\Domain\Agenda\Enums\AppointmentStatus;
use App\Domain\Tenant\Enums\MembershipRole;
use App\Domain\Tenant\Models\Tenant;
use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Barber;
use App\Models\BlockedPeriod;
use App\Models\Service;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AgendaController extends Controller
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    public function index(Request $request): View
    {
        $this->authorize('schedule.view');

        $statusOptions = [
            AppointmentStatus::Pending->value => 'Pendente',
            AppointmentStatus::Confirmed->value => 'Confirmado',
            AppointmentStatus::Arrived->value => 'Cliente chegou',
            AppointmentStatus::InService->value => 'Em atendimento',
            AppointmentStatus::Completed->value => 'Concluído',
            AppointmentStatus::Canceled->value => 'Cancelado',
            AppointmentStatus::NoShow->value => 'Não compareceu',
        ];
        $filters = $request->validate([
            'start_date' => ['sometimes', 'date_format:Y-m-d'],
            'end_date' => ['sometimes', 'date_format:Y-m-d'],
            'date' => ['sometimes', 'date_format:Y-m-d'],
            'barber_id' => ['sometimes', 'nullable', 'integer'],
            'service_id' => ['sometimes', 'nullable', 'integer'],
            'status' => ['sometimes', 'nullable', Rule::in(array_keys($statusOptions))],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
        ]);
        $date = $filters['start_date'] ?? $filters['date'] ?? now(config('app.timezone'))->toDateString();
        $endDate = $filters['end_date'] ?? $date;

        if ($endDate < $date) {
            throw ValidationException::withMessages([
                'end_date' => 'A data final deve ser igual ou posterior à data inicial.',
            ]);
        }

        $rangeStartsAt = Carbon::parse($date, config('app.timezone'))->startOfDay();
        $rangeEndsAt = Carbon::parse($endDate, config('app.timezone'))->addDay()->startOfDay();
        $user = $request->user();
        $membership = $user?->memberships()
            ->where('tenant_id', $this->tenantContext->id())
            ->where('is_active', true)
            ->first();
        $isBarber = $membership?->role === MembershipRole::Barber;
        $ownBarber = $isBarber
            ? Barber::query()->where('user_id', $user?->getKey())->first()
            : null;

        $appointments = Appointment::query()
            ->with(['customer', 'barber', 'service', 'attendance'])
            ->where('starts_at', '>=', $rangeStartsAt)
            ->where('starts_at', '<', $rangeEndsAt)
            ->when(
                $isBarber,
                fn ($query) => $query->where('barber_id', $ownBarber?->getKey() ?? 0),
                fn ($query) => $request->filled('barber_id')
                    ? $query->where('barber_id', $request->integer('barber_id'))
                    : null,
            )
            ->when(
                $request->filled('service_id'),
                fn ($query) => $query->where('service_id', $request->integer('service_id')),
            )
            ->when(
                $request->filled('status'),
                fn ($query) => $query->where('status', $filters['status']),
            )
            ->when($request->filled('search'), function ($query) use ($filters): void {
                $search = trim($filters['search']);
                $searchDigits = preg_replace('/\D+/', '', $search);
                $query->whereHas('customer', function ($customerQuery) use ($search, $searchDigits): void {
                    $customerQuery->where('name', 'ilike', "%{$search}%")
                        ->when(
                            $searchDigits !== '',
                            fn ($customerQuery) => $customerQuery->orWhere('phone', 'ilike', "%{$searchDigits}%"),
                        );
                });
            })
            ->orderBy('starts_at')
            ->get();
        $appointmentGroups = $appointments->groupBy(
            fn (Appointment $appointment): string => $appointment->starts_at
                ->setTimezone(config('app.timezone'))
                ->toDateString(),
        );

        $barbers = match (true) {
            $ownBarber !== null => collect([$ownBarber]),
            $isBarber => collect(),
            default => Barber::query()->where('is_active', true)->orderBy('name')->get(),
        };
        $services = Service::query()->orderBy('name')->get();
        $blockedPeriods = BlockedPeriod::query()
            ->with('barber')
            ->where('starts_at', '<', $rangeEndsAt)
            ->where('ends_at', '>', $rangeStartsAt)
            ->when($isBarber, fn ($query) => $query->where('barber_id', $ownBarber?->getKey() ?? 0))
            ->orderBy('starts_at')
            ->get();
        $tenant = Tenant::query()->findOrFail($this->tenantContext->id());

        return view('app.agenda.index', compact(
            'appointments',
            'appointmentGroups',
            'barbers',
            'blockedPeriods',
            'date',
            'endDate',
            'isBarber',
            'ownBarber',
            'services',
            'statusOptions',
            'tenant',
        ));
    }

    public function block(Request $request): RedirectResponse
    {
        $this->authorize('schedule.manage');

        $attributes = $request->validate([
            'barber_id' => ['required', 'integer', 'exists:barbers,id'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $barber = Barber::query()
            ->whereKey($attributes['barber_id'])
            ->lockForUpdate()
            ->firstOrFail();
        $this->authorizeBarberOwnership($request, $barber);
        $startsAt = Carbon::parse($attributes['starts_at'], config('app.timezone'));
        $endsAt = Carbon::parse($attributes['ends_at'], config('app.timezone'));

        $conflict = Appointment::query()
            ->where('barber_id', $barber->getKey())
            ->whereIn('status', AppointmentStatus::blockingValues())
            ->where('starts_at', '<', $endsAt)
            ->where('ends_at', '>', $startsAt)
            ->exists();

        $blocked = BlockedPeriod::query()
            ->where('barber_id', $barber->getKey())
            ->where('starts_at', '<', $endsAt)
            ->where('ends_at', '>', $startsAt)
            ->exists();

        if ($conflict || $blocked) {
            throw ValidationException::withMessages([
                'starts_at' => 'O período coincide com um agendamento ou outra indisponibilidade.',
            ]);
        }

        $period = BlockedPeriod::query()->create([
            'barber_id' => $barber->getKey(),
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'reason' => $attributes['reason'] ?? null,
        ]);

        return redirect()
            ->route('app.agenda.index', ['date' => $period->starts_at->toDateString()])
            ->with('status', 'Indisponibilidade adicionada à agenda.');
    }

    public function unblock(BlockedPeriod $blockedPeriod): RedirectResponse
    {
        $this->authorize('schedule.manage');
        $this->authorizeBarberOwnership(request(), $blockedPeriod->barber);
        $date = $blockedPeriod->starts_at->toDateString();
        $blockedPeriod->delete();

        return redirect()
            ->route('app.agenda.index', ['date' => $date])
            ->with('status', 'Indisponibilidade removida.');
    }

    private function authorizeBarberOwnership(Request $request, Barber $barber): void
    {
        $membership = $request->user()?->memberships()
            ->where('tenant_id', $this->tenantContext->id())
            ->where('is_active', true)
            ->first();

        if ($membership?->role === MembershipRole::Barber) {
            abort_unless((int) $barber->user_id === (int) $request->user()?->getKey(), 403);
        }
    }
}
