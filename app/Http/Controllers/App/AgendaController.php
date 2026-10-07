<?php

namespace App\Http\Controllers\App;

use App\Application\Agenda\Actions\UpdateAppointmentStatus;
use App\Application\Shared\Tenancy\TenantContext;
use App\Domain\Agenda\Enums\AppointmentStatus;
use App\Domain\Tenant\Enums\MembershipRole;
use App\Domain\Tenant\Models\Tenant;
use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Barber;
use App\Models\BlockedPeriod;
use App\Models\Service;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AgendaController extends Controller
{
    /**
     * As quatro visões da tela. `list` é a tabela por período (comportamento
     * original); as demais são o calendário e carregam uma faixa de datas
     * calculada a partir da data âncora, nunca dos parâmetros start/end.
     */
    private const VIEWS = ['day', 'week', 'month', 'list'];

    public function __construct(private readonly TenantContext $tenantContext) {}

    public function index(Request $request): View
    {
        $this->authorize('schedule.view');

        $statusOptions = collect(AppointmentStatus::cases())
            ->mapWithKeys(fn (AppointmentStatus $status): array => [$status->value => $status->label()])
            ->all();
        $filters = $request->validate([
            'start_date' => ['sometimes', 'date_format:Y-m-d'],
            'end_date' => ['sometimes', 'date_format:Y-m-d'],
            'date' => ['sometimes', 'date_format:Y-m-d'],
            'barber_id' => ['sometimes', 'nullable', 'integer'],
            'service_id' => ['sometimes', 'nullable', 'integer'],
            'status' => ['sometimes', 'nullable', Rule::in(array_keys($statusOptions))],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
        ]);

        $timezone = config('app.timezone');
        $today = now($timezone)->toDateString();
        $requestedStart = $filters['start_date'] ?? $filters['date'] ?? $today;
        $requestedEnd = $filters['end_date'] ?? $requestedStart;

        if ($requestedEnd < $requestedStart) {
            throw ValidationException::withMessages([
                'end_date' => 'A data final deve ser igual ou posterior à data inicial.',
            ]);
        }

        $view = $this->resolveView($request);
        $anchor = Carbon::parse($filters['date'] ?? $filters['start_date'] ?? $today, $timezone);

        /*
         * Nas visões de calendário o intervalo é derivado da data âncora: é o
         * que garante que "próximo"/"anterior" naveguem um período inteiro por
         * vez sem depender de start_date/end_date, que valem só para a lista.
         */
        [$date, $endDate] = match ($view) {
            'day' => [$anchor->toDateString(), $anchor->toDateString()],
            'week' => [
                $anchor->copy()->startOfWeek()->toDateString(),
                $anchor->copy()->endOfWeek()->toDateString(),
            ],
            'month' => [
                $anchor->copy()->startOfMonth()->startOfWeek()->toDateString(),
                $anchor->copy()->endOfMonth()->endOfWeek()->toDateString(),
            ],
            default => [$requestedStart, $requestedEnd],
        };

        $rangeStartsAt = Carbon::parse($date, $timezone)->startOfDay();
        $rangeEndsAt = Carbon::parse($endDate, $timezone)->addDay()->startOfDay();
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
                ->setTimezone($timezone)
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

        $filterQuery = array_filter([
            'barber_id' => $filters['barber_id'] ?? null,
            'service_id' => $filters['service_id'] ?? null,
            'status' => $filters['status'] ?? null,
            'search' => $filters['search'] ?? null,
        ], fn ($value): bool => filled($value));

        $events = $this->buildEvents($appointments, $blockedPeriods, $statusOptions, $user, $timezone);

        return view('app.agenda.index', [
            'appointments' => $appointments,
            'appointmentGroups' => $appointmentGroups,
            'barbers' => $barbers,
            'blockedPeriods' => $blockedPeriods,
            'date' => $date,
            'endDate' => $endDate,
            'events' => $events,
            'filterQuery' => $filterQuery,
            'isBarber' => $isBarber,
            'ownBarber' => $ownBarber,
            'nav' => $this->buildNavigation($view, $date, $endDate, $anchor, $filterQuery, $timezone),
            'services' => $services,
            'statusOptions' => $statusOptions,
            'tenant' => $tenant,
            'title' => $this->buildTitle($view, $date, $endDate),
            'view' => $view,
            'weekdaysShort' => ['SEG', 'TER', 'QUA', 'QUI', 'SEX', 'SÁB', 'DOM'],
            'anchorDate' => $anchor->toDateString(),
            'dayUrlTemplate' => route('app.agenda.index', [...$filterQuery, 'view' => 'day', 'date' => '__DATE__']),
        ]);
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

    /**
     * Muda o status de um agendamento pela agenda. O menu só oferece os alvos
     * da matriz do enum; o que não está nela (em atendimento e concluído)
     * nasce do fluxo de atendimento e volta para cá como erro da action.
     */
    public function updateStatus(
        Request $request,
        Appointment $appointment,
        UpdateAppointmentStatus $updateStatus,
    ): JsonResponse|RedirectResponse {
        $this->authorize('schedule.manage');
        $this->authorizeBarberOwnsAppointment($request, $appointment);

        $attributes = $request->validate([
            'status' => ['required', Rule::enum(AppointmentStatus::class)],
        ]);
        $target = AppointmentStatus::from($attributes['status']);

        $result = $updateStatus->execute([
            'appointment' => $appointment,
            'status' => $target,
        ]);

        if ($request->expectsJson()) {
            return response()->json(['data' => $result['appointment']]);
        }

        return redirect()
            ->back()
            ->with('status', sprintf('Status do agendamento alterado para "%s".', $target->label()));
    }

    /**
     * Visão pedida na URL, com dois fallbacks: sem parâmetro, um intervalo
     * explícito (start/end) significa "quero a lista daquele período"; sem nenhum
     * dos dois, a tela abre no dia — é a leitura padrão de quem chega para
     * conferir a agenda de hoje.
     */
    private function resolveView(Request $request): string
    {
        $requested = $request->query('view');

        if (is_string($requested) && in_array($requested, self::VIEWS, true)) {
            return $requested;
        }

        return ($request->filled('start_date') || $request->filled('end_date')) ? 'list' : 'day';
    }

    /**
     * @param  array<string, mixed>  $filterQuery
     * @return array<string, mixed>
     */
    private function buildNavigation(
        string $view,
        string $date,
        string $endDate,
        Carbon $anchor,
        array $filterQuery,
        string $timezone,
    ): array {
        $start = Carbon::parse($date, $timezone);
        $end = Carbon::parse($endDate, $timezone);
        $spanDays = max(1, (int) round($start->diffInDays($end)) + 1);

        $calendarUrl = fn (Carbon $day): string => route('app.agenda.index', [
            ...$filterQuery,
            'view' => $view,
            'date' => $day->toDateString(),
        ]);
        $listUrl = fn (Carbon $day): string => route('app.agenda.index', [
            ...$filterQuery,
            'start_date' => $day->toDateString(),
            'end_date' => $day->copy()->addDays($spanDays - 1)->toDateString(),
        ]);
        $isList = $view === 'list';

        [$previous, $next] = match ($view) {
            'day' => [$start->copy()->subDay(), $start->copy()->addDay()],
            'week' => [$start->copy()->subWeek(), $start->copy()->addWeek()],
            'month' => [$start->copy()->subMonth(), $start->copy()->addMonth()],
            default => [$start->copy()->subDays($spanDays), $start->copy()->addDays($spanDays)],
        };

        $today = Carbon::parse(now($timezone)->toDateString(), $timezone);
        $weekStart = $today->copy()->startOfWeek();

        return [
            'prev' => $isList ? $listUrl($previous) : $calendarUrl($previous),
            'next' => $isList ? $listUrl($next) : $calendarUrl($next),
            'today' => $isList ? $listUrl($today) : $calendarUrl($today),
            'week' => $isList
                ? route('app.agenda.index', [...$filterQuery, 'start_date' => $weekStart->toDateString(), 'end_date' => $weekStart->copy()->endOfWeek()->toDateString()])
                : route('app.agenda.index', [...$filterQuery, 'view' => 'week', 'date' => $today->toDateString()]),
            'next7' => route('app.agenda.index', [
                ...$filterQuery,
                'start_date' => $today->toDateString(),
                'end_date' => $today->copy()->addDays(6)->toDateString(),
            ]),
            'views' => [
                'day' => route('app.agenda.index', [...$filterQuery, 'view' => 'day', 'date' => $anchor->toDateString()]),
                'week' => route('app.agenda.index', [...$filterQuery, 'view' => 'week', 'date' => $anchor->toDateString()]),
                'month' => route('app.agenda.index', [...$filterQuery, 'view' => 'month', 'date' => $anchor->toDateString()]),
                'list' => route('app.agenda.index', [
                    ...$filterQuery,
                    'start_date' => $date,
                    'end_date' => $endDate,
                ]),
            ],
            'clearFilters' => $isList
                ? route('app.agenda.index', ['start_date' => $date, 'end_date' => $endDate])
                : route('app.agenda.index', ['view' => $view, 'date' => $anchor->toDateString()]),
        ];
    }

    private function buildTitle(string $view, string $date, string $endDate): string
    {
        $start = Carbon::parse($date);
        $end = Carbon::parse($endDate);

        return match ($view) {
            'day' => $start->locale('pt_BR')->translatedFormat('l, d \d\e F'),
            'week' => $start->isSameMonth($end)
                ? $start->format('d').'–'.$end->locale('pt_BR')->translatedFormat('d \d\e F \d\e Y')
                : $start->locale('pt_BR')->translatedFormat('d \d\e F').' – '.$end->locale('pt_BR')->translatedFormat('d \d\e F \d\e Y'),
            'month' => $start->locale('pt_BR')->translatedFormat('F \d\e Y'),
            default => $start->format('d/m/Y').' a '.$end->format('d/m/Y'),
        };
    }

    /**
     * Os eventos viram JSON puro para o calendário (agenda.js) desenhar mês,
     * semana e dia. As ações já vêm prontas e autorizadas: a mesma regra da
     * tabela (ver atendimento, iniciar atendimento, remover bloqueio), só que
     * como dados em vez de HTML, porque quem monta o botão é o JS.
     *
     * @param  array<string, string>  $statusOptions
     * @return list<array<string, mixed>>
     */
    private function buildEvents(
        Collection $appointments,
        Collection $blockedPeriods,
        array $statusOptions,
        ?Authenticatable $user,
        string $timezone,
    ): array {
        $canViewAttendance = $user !== null && Gate::forUser($user)->allows('attendance.view');
        $canManageAttendance = $user !== null && Gate::forUser($user)->allows('attendance.manage');
        $canManageSchedule = $user !== null && Gate::forUser($user)->allows('schedule.manage');

        $events = [];

        foreach ($appointments as $appointment) {
            $startsAt = $appointment->starts_at->setTimezone($timezone);
            $endsAt = $appointment->ends_at->setTimezone($timezone);
            $status = $appointment->status->value;
            $actions = [];

            if ($appointment->attendance !== null) {
                if ($canViewAttendance) {
                    $actions[] = [
                        'label' => $appointment->attendance->status->value === 'open' ? 'Abrir atendimento' : 'Ver atendimento',
                        'url' => route('app.attendances.show', $appointment->attendance),
                        'method' => 'GET',
                        'icon' => 'fa-arrow-up-right-from-square',
                    ];
                }
            } elseif (in_array($status, ['confirmed', 'arrived'], true) && $canManageAttendance) {
                $actions[] = [
                    'label' => 'Iniciar atendimento',
                    'url' => route('app.attendances.open', $appointment),
                    'method' => 'POST',
                    'icon' => 'fa-play',
                ];
            }

            $events[] = [
                'id' => 'appointment-'.$appointment->id,
                'kind' => 'appointment',
                'start' => $startsAt->format('Y-m-d\TH:i:s'),
                'end' => $endsAt->format('Y-m-d\TH:i:s'),
                'time' => $this->formatRange($startsAt, $endsAt),
                'title' => $appointment->customer->name,
                'phone' => $appointment->customer->phone,
                'service' => $appointment->service->name,
                'barber' => $appointment->barber->name,
                'status' => $status,
                'statusLabel' => $statusOptions[$status],
                'actions' => $actions,
                'statusUrl' => route('app.agenda.appointments.status', $appointment),
                'statusTargets' => $canManageSchedule
                    ? array_map(
                        fn (AppointmentStatus $target): array => [
                            'value' => $target->value,
                            'label' => $target->label(),
                        ],
                        $appointment->status->targets(),
                    )
                    : [],
            ];
        }

        foreach ($blockedPeriods as $period) {
            $startsAt = $period->starts_at->setTimezone($timezone);
            $endsAt = $period->ends_at->setTimezone($timezone);

            $events[] = [
                'id' => 'blocked-'.$period->id,
                'kind' => 'blocked',
                'start' => $startsAt->format('Y-m-d\TH:i:s'),
                'end' => $endsAt->format('Y-m-d\TH:i:s'),
                'time' => $this->formatRange($startsAt, $endsAt),
                'title' => $period->reason ?: 'Indisponibilidade',
                'phone' => null,
                'service' => null,
                'barber' => $period->barber->name,
                'status' => 'blocked',
                'statusLabel' => 'Indisponível',
                'statusTargets' => [],
                'actions' => $canManageSchedule ? [[
                    'label' => 'Remover bloqueio',
                    'url' => route('app.agenda.blocked-periods.destroy', $period),
                    'method' => 'DELETE',
                    'icon' => 'fa-trash',
                ]] : [],
            ];
        }

        return $events;
    }

    /**
     * `CarbonInterface` e não `Illuminate\Support\Carbon`: o cast de data do
     * modelo devolve CarbonImmutable, e os dois implementam a interface.
     */
    private function formatRange(CarbonInterface $startsAt, CarbonInterface $endsAt): string
    {
        if ($startsAt->isSameDay($endsAt)) {
            return $startsAt->format('H:i').'–'.$endsAt->format('H:i');
        }

        return $startsAt->format('d/m H:i').'–'.$endsAt->format('d/m H:i');
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

    /**
     * Mesmo guard de AttendanceController: barbeiro só mexe nos agendamentos
     * do próprio barbeiro (o admin/gerente passa direto).
     */
    private function authorizeBarberOwnsAppointment(Request $request, Appointment $appointment): void
    {
        $membership = $request->user()?->memberships()
            ->where('tenant_id', $this->tenantContext->id())
            ->where('is_active', true)
            ->first();

        if ($membership?->role === MembershipRole::Barber) {
            $barber = Barber::query()
                ->where('user_id', $request->user()?->getKey())
                ->first();

            abort_unless(
                $barber !== null && (int) $appointment->barber_id === (int) $barber->getKey(),
                403,
            );
        }
    }
}
