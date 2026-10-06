<x-app-layout title="Agenda">
    @php
        $today = now(config('app.timezone'))->startOfDay();
        $rangeLabel = $date === $endDate
            ? \Illuminate\Support\Carbon::parse($date)->format('d/m/Y')
            : \Illuminate\Support\Carbon::parse($date)->format('d/m/Y').' a '.\Illuminate\Support\Carbon::parse($endDate)->format('d/m/Y');
        $selectedBarber = request('barber_id');
        $selectedService = request('service_id');
        $selectedStatus = request('status');
        $searchTerm = trim((string) request('search', ''));
        $shortcutFilters = array_filter([
            'barber_id' => $selectedBarber,
            'service_id' => $selectedService,
            'status' => $selectedStatus,
            'search' => $searchTerm,
        ], fn ($value) => filled($value));
        $activeFilterCount = collect([$selectedBarber, $selectedService, $selectedStatus, $searchTerm])
            ->filter(fn ($value) => filled($value))
            ->count();
        $additionalFiltersOpen = $activeFilterCount > 0;
    @endphp

    <div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-4">
        <div>
            <h1 class="h3 mb-0">Agenda</h1>
            <p class="text-secondary mb-0">Consulte os atendimentos e horários indisponíveis por período.</p>
        </div>
        <a href="{{ route('booking.show', $tenant->slug) }}" target="_blank" rel="noopener"
           class="btn btn-outline-primary">
            <i class="fas fa-arrow-up-right-from-square me-1" aria-hidden="true"></i>
            Abrir página pública de agendamento
        </a>
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <form method="GET" action="{{ route('app.agenda.index') }}">
                <div class="row align-items-end g-3">
                    <div class="col-12 col-sm-6 col-md-3 col-xl-2">
                        <label for="start_date" class="form-label">Data inicial</label>
                        <input id="start_date" name="start_date" type="date" value="{{ $date }}"
                               class="form-control @error('start_date') is-invalid @enderror"
                               data-agenda-start-date required>
                        @error('start_date')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="col-12 col-sm-6 col-md-3 col-xl-2">
                        <label for="end_date" class="form-label">Data final</label>
                        <input id="end_date" name="end_date" type="date" value="{{ $endDate }}"
                               min="{{ $date }}" class="form-control @error('end_date') is-invalid @enderror"
                               data-agenda-end-date required>
                        @error('end_date')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="col-12 col-md-6 col-xl">
                        <label for="search" class="form-label">Encontrar cliente</label>
                        <div class="input-group">
                            <span class="input-group-text" aria-hidden="true">
                                <i class="fas fa-magnifying-glass"></i>
                            </span>
                            <input id="search" name="search" type="search" maxlength="100"
                                   value="{{ $searchTerm }}" class="form-control"
                                   placeholder="Digite nome ou telefone">
                        </div>
                    </div>
                    @if ($isBarber)
                        <input type="hidden" name="barber_id" value="{{ $ownBarber?->getKey() }}">
                    @else
                        @if ($selectedBarber)
                            <input type="hidden" name="barber_id" value="{{ $selectedBarber }}">
                        @endif
                    @endif
                    @if ($selectedService)
                        <input type="hidden" name="service_id" value="{{ $selectedService }}">
                    @endif
                    @if ($selectedStatus)
                        <input type="hidden" name="status" value="{{ $selectedStatus }}">
                    @endif
                    <div class="col-12 col-md-auto">
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="fas fa-magnifying-glass me-1" aria-hidden="true"></i> Buscar agenda
                        </button>
                    </div>
                </div>
            </form>

            <div class="d-flex flex-wrap align-items-center gap-2 mt-3">
                <span class="small text-secondary me-1">Ver:</span>
                <a href="{{ route('app.agenda.index', array_filter([
                    'start_date' => $today->toDateString(),
                    'end_date' => $today->toDateString(),
                    ...$shortcutFilters,
                ])) }}" class="btn btn-sm {{ $date === $today->toDateString() && $endDate === $today->toDateString() ? 'btn-primary' : 'btn-outline-secondary' }}">
                    Hoje
                </a>
                <a href="{{ route('app.agenda.index', array_filter([
                    'start_date' => $today->toDateString(),
                    'end_date' => $today->copy()->addDays(6)->toDateString(),
                    ...$shortcutFilters,
                ])) }}" class="btn btn-sm btn-outline-secondary">
                    Próximos 7 dias
                </a>
                <a href="{{ route('app.agenda.index', array_filter([
                    'start_date' => $today->copy()->startOfWeek()->toDateString(),
                    'end_date' => $today->copy()->endOfWeek()->toDateString(),
                    ...$shortcutFilters,
                ])) }}" class="btn btn-sm btn-outline-secondary">
                    Esta semana
                </a>
                @if ($activeFilterCount > 0)
                    <a href="{{ route('app.agenda.index', ['start_date' => $date, 'end_date' => $endDate]) }}"
                       class="small ms-sm-auto">Limpar filtros</a>
                @endif
            </div>

            <details class="agenda-extra-filters mt-3" @if ($additionalFiltersOpen) open @endif>
                <summary class="agenda-extra-filters-toggle">
                    <span>
                        <i class="fas fa-sliders me-1" aria-hidden="true"></i>
                        Mais filtros
                        @if ($activeFilterCount > 0)
                            <span class="badge rounded-pill text-bg-primary ms-1">{{ $activeFilterCount }}</span>
                        @endif
                    </span>
                    <i class="fas fa-chevron-down agenda-extra-filters-chevron" aria-hidden="true"></i>
                </summary>
                <div class="agenda-extra-filters-content">
                    <form method="GET" action="{{ route('app.agenda.index') }}"
                          class="row align-items-end g-3">
                        <input type="hidden" name="start_date" value="{{ $date }}">
                        <input type="hidden" name="end_date" value="{{ $endDate }}">
                        <input type="hidden" name="search" value="{{ $searchTerm }}">
                        @if ($isBarber)
                            <input type="hidden" name="barber_id" value="{{ $ownBarber?->getKey() }}">
                        @else
                            <div class="col-sm-6 col-lg-4">
                                <label for="barber_id" class="form-label">Barbeiro</label>
                                <select id="barber_id" name="barber_id" class="form-select">
                                    <option value="">Todos os barbeiros</option>
                                    @foreach ($barbers as $barber)
                                        <option value="{{ $barber->id }}"
                                            @selected((string) $selectedBarber === (string) $barber->id)>
                                            {{ $barber->name }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                        @endif
                        <div class="col-sm-6 col-lg-4">
                            <label for="service_id" class="form-label">Serviço</label>
                            <select id="service_id" name="service_id" class="form-select">
                                <option value="">Todos os serviços</option>
                                @foreach ($services as $service)
                                    <option value="{{ $service->id }}" @selected((string) $selectedService === (string) $service->id)>
                                        {{ $service->name }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-sm-6 col-lg-4">
                            <label for="status" class="form-label">Status</label>
                            <select id="status" name="status" class="form-select">
                                <option value="">Todos os status</option>
                                @foreach ($statusOptions as $statusValue => $statusLabel)
                                    <option value="{{ $statusValue }}" @selected($selectedStatus === $statusValue)>
                                        {{ $statusLabel }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-12 d-flex flex-wrap justify-content-end gap-2">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-filter me-1" aria-hidden="true"></i> Aplicar filtros
                            </button>
                        </div>
                    </form>
                </div>
            </details>
        </div>
    </div>

    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3 px-1">
        <div>
            <h2 class="h5 mb-0">Agendamentos</h2>
            <span class="small text-secondary">{{ $rangeLabel }} · {{ $appointments->count() }}
                {{ $appointments->count() === 1 ? 'resultado' : 'resultados' }}</span>
        </div>
        @if ($selectedBarber)
            <span class="small text-secondary">
                <i class="fas fa-user-tie me-1" aria-hidden="true"></i>
                {{ $barbers->firstWhere('id', (int) $selectedBarber)?->name ?? 'Barbeiro selecionado' }}
            </span>
        @endif
    </div>

    <div class="card mb-4">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 table-stack">
                <thead class="table-light">
                    <tr>
                        <th scope="col">Horário</th>
                        @if (! $isBarber)
                            <th scope="col">Barbeiro</th>
                        @endif
                        <th scope="col">Cliente</th>
                        <th scope="col">Serviço</th>
                        <th scope="col">Status</th>
                        <th scope="col" class="text-end">Atendimento</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($appointmentGroups as $appointmentDate => $dayAppointments)
                        <tr class="table-light">
                            <th colspan="{{ $isBarber ? 5 : 6 }}" scope="rowgroup" class="py-2">
                                <i class="far fa-calendar me-1 text-primary" aria-hidden="true"></i>
                                {{ \Illuminate\Support\Carbon::parse($appointmentDate)->translatedFormat('l, d/m/Y') }}
                                <span class="badge text-bg-secondary ms-1">
                                    {{ $dayAppointments->count() }} {{ $dayAppointments->count() === 1 ? 'agendamento' : 'agendamentos' }}
                                </span>
                            </th>
                        </tr>
                        @foreach ($dayAppointments as $appointment)
                            <tr>
                                <td class="fw-semibold">
                                    {{ $appointment->starts_at->setTimezone(config('app.timezone'))->format('H:i') }}–{{ $appointment->ends_at->setTimezone(config('app.timezone'))->format('H:i') }}
                                </td>
                                @if (! $isBarber)
                                    <td>{{ $appointment->barber->name }}</td>
                                @endif
                                <td>
                                    <div>{{ $appointment->customer->name }}</div>
                                    <small class="text-secondary">{{ $appointment->customer->phone }}</small>
                                </td>
                                <td>{{ $appointment->service->name }}</td>
                                <td>
                                    @php
                                        $statusLabels = [
                                            'pending' => 'Pendente',
                                            'confirmed' => 'Confirmado',
                                            'arrived' => 'Cliente chegou',
                                            'in_service' => 'Em atendimento',
                                            'completed' => 'Concluído',
                                            'canceled' => 'Cancelado',
                                            'no_show' => 'Não compareceu',
                                        ];
                                        $statusValue = $appointment->status->value;
                                    @endphp
                                    <span class="badge {{ in_array($statusValue, ['confirmed', 'arrived', 'in_service'], true) ? 'text-bg-primary' : 'text-bg-secondary' }}">
                                        {{ $statusLabels[$statusValue] }}
                                    </span>
                                </td>
                                <td class="text-end">
                                    @if ($appointment->attendance !== null)
                                        @can('attendance.view')
                                            <a href="{{ route('app.attendances.show', $appointment->attendance) }}"
                                               class="btn btn-sm btn-outline-primary">
                                                {{ $appointment->attendance->status->value === 'open' ? 'Abrir atendimento' : 'Ver atendimento' }}
                                            </a>
                                        @endcan
                                    @elseif (in_array($statusValue, ['confirmed', 'arrived'], true))
                                        @can('attendance.manage')
                                            <form method="POST" action="{{ route('app.attendances.open', $appointment) }}" class="d-inline">
                                                @csrf
                                                <button class="btn btn-sm btn-primary" type="submit">Iniciar atendimento</button>
                                            </form>
                                        @endcan
                                    @else
                                        <span class="text-secondary">—</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    @empty
                        <tr>
                            <td colspan="{{ $isBarber ? 5 : 6 }}" class="text-center text-secondary py-5">
                                <i class="fas fa-calendar-day fa-2x mb-3 d-block" aria-hidden="true"></i>
                                Não há clientes agendados neste período.
                                @if ($searchTerm !== '' || $selectedService || $selectedStatus || $selectedBarber)
                                    <div class="mt-3">
                                        <a href="{{ route('app.agenda.index', [
                                            'start_date' => $date,
                                            'end_date' => $endDate,
                                        ]) }}" class="btn btn-sm btn-outline-primary">
                                            Limpar filtros adicionais
                                        </a>
                                    </div>
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @if ($isBarber && $ownBarber === null)
        <div class="alert alert-warning" role="status">
            Esta conta ainda não está vinculada a um cadastro de barbeiro. Peça ao administrador para vinculá-la
            em <a href="{{ route('app.barbers.index') }}">Cadastros &gt; Barbeiros</a>.
        </div>
    @endif

    @can('schedule.manage')
        <details class="card mb-4" @if ($errors->has('barber_id') || $errors->has('starts_at') || $errors->has('ends_at') || $errors->has('reason')) open @endif>
            <summary class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                <span class="h5 mb-0">Gerenciar indisponibilidades</span>
                <span class="small text-secondary">
                    {{ $blockedPeriods->count() }} {{ $blockedPeriods->count() === 1 ? 'bloqueio no período' : 'bloqueios no período' }}
                    <i class="fas fa-chevron-down ms-1" aria-hidden="true"></i>
                </span>
            </summary>
            <div class="card-body border-bottom">
                <h3 class="h6 mb-1">Adicionar bloqueio</h3>
                <p class="small text-secondary mb-3">Use para pausas, folgas ou outros horários em que não haverá atendimento.</p>
                <form method="POST" action="{{ route('app.agenda.blocked-periods.store') }}"
                      class="row g-3 align-items-end">
                    @csrf
                    <div class="col-md-3">
                        <label for="blocked_barber_id" class="form-label">Barbeiro</label>
                        <select id="blocked_barber_id" name="barber_id"
                                class="form-select @error('barber_id') is-invalid @enderror" required>
                            <option value="">Selecione</option>
                            @foreach ($barbers as $barber)
                                <option value="{{ $barber->id }}" @selected(old('barber_id') == $barber->id)>
                                    {{ $barber->name }}
                                </option>
                            @endforeach
                        </select>
                        @error('barber_id')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="col-md-3">
                        <label for="blocked_starts_at" class="form-label">Início</label>
                        <input id="blocked_starts_at" name="starts_at" type="datetime-local"
                               value="{{ old('starts_at') }}"
                               class="form-control @error('starts_at') is-invalid @enderror" required>
                        @error('starts_at')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="col-md-3">
                        <label for="blocked_ends_at" class="form-label">Fim</label>
                        <input id="blocked_ends_at" name="ends_at" type="datetime-local"
                               value="{{ old('ends_at') }}"
                               class="form-control @error('ends_at') is-invalid @enderror" required>
                        @error('ends_at')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="col-md-3">
                        <label for="blocked_reason" class="form-label">Motivo (opcional)</label>
                        <input id="blocked_reason" name="reason" type="text" maxlength="255"
                               value="{{ old('reason') }}"
                               class="form-control @error('reason') is-invalid @enderror">
                        @error('reason')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="col-12">
                        <button type="submit" class="btn btn-outline-primary">Bloquear período</button>
                    </div>
                </form>
            </div>

            <div class="table-responsive">
                <table class="table align-middle mb-0 table-stack">
                    <thead class="table-light">
                        <tr>
                            <th scope="col">Período</th>
                            @if (! $isBarber)
                                <th scope="col">Barbeiro</th>
                            @endif
                            <th scope="col">Motivo</th>
                            <th scope="col" class="text-end">Ação</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($blockedPeriods as $period)
                            <tr>
                                <td>{{ $period->starts_at->format('d/m H:i') }}–{{ $period->ends_at->format('d/m H:i') }}</td>
                                @if (! $isBarber)
                                    <td>{{ $period->barber->name }}</td>
                                @endif
                                <td>{{ $period->reason ?: '—' }}</td>
                                <td class="text-end">
                                    <form method="POST" action="{{ route('app.agenda.blocked-periods.destroy', $period) }}"
                                          onsubmit="return confirm('Remover esta indisponibilidade?')">
                                        @csrf
                                        @method('DELETE')
                                        <button class="btn btn-sm btn-outline-danger" type="submit">Remover</button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="{{ $isBarber ? 3 : 4 }}" class="text-center text-secondary py-4">
                                    Nenhum período bloqueado neste período.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </details>
    @endcan
</x-app-layout>
