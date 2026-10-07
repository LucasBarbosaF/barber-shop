<x-app-layout title="Agenda" :assets="['resources/css/agenda.css', 'resources/js/agenda.js']">
    @php
        $today = now(config('app.timezone'))->startOfDay();
        $rangeLabel = $date === $endDate
            ? \Illuminate\Support\Carbon::parse($date)->format('d/m/Y')
            : \Illuminate\Support\Carbon::parse($date)->format('d/m/Y').' a '.\Illuminate\Support\Carbon::parse($endDate)->format('d/m/Y');
        $selectedBarber = request('barber_id');
        $selectedService = request('service_id');
        $selectedStatus = request('status');
        $searchTerm = trim((string) request('search', ''));
        $activeFilterCount = collect([$selectedBarber, $selectedService, $selectedStatus, $searchTerm])
            ->filter(fn ($value) => filled($value))
            ->count();
        $additionalFiltersOpen = $activeFilterCount > 0;
        $isTodayShortcut = $view === 'list'
            ? $date === $today->toDateString() && $endDate === $today->toDateString()
            : $anchorDate === $today->toDateString();
        $weekStart = $today->copy()->startOfWeek();
        $isWeekShortcut = $view === 'week'
            || ($date === $weekStart->toDateString() && $endDate === $weekStart->copy()->endOfWeek()->toDateString());

        $visibleDays = [];
        if ($view !== 'list') {
            $cursor = \Illuminate\Support\Carbon::parse($date);
            $lastDay = \Illuminate\Support\Carbon::parse($endDate);
            while ($cursor->lte($lastDay)) {
                $visibleDays[] = [
                    'date' => $cursor->toDateString(),
                    'day' => $cursor->day,
                    'weekday' => $weekdaysShort[($cursor->dayOfWeek + 6) % 7],
                    'isToday' => $cursor->isToday(),
                    'isWeekend' => $cursor->isSaturday() || $cursor->isSunday(),
                    'label' => $cursor->copy()->locale('pt_BR')->translatedFormat('l, d \d\e F'),
                ];
                $cursor->addDay();
            }
        }

        $anchorCarbon = \Illuminate\Support\Carbon::parse($anchorDate);
        $miniStart = $anchorCarbon->copy()->startOfMonth()->startOfWeek();
        $miniEnd = $anchorCarbon->copy()->endOfMonth()->endOfWeek();
        $currentViewQuery = $view === 'list'
            ? ['view' => 'list', 'start_date' => $date, 'end_date' => $endDate]
            : ['view' => $view, 'date' => $anchorDate];
        $withoutBarber = array_filter($filterQuery, fn ($key) => $key !== 'barber_id', ARRAY_FILTER_USE_KEY);
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

    @include('app.agenda._toolbar')

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
                <a href="{{ $nav['today'] }}"
                   class="btn btn-sm {{ $isTodayShortcut ? 'btn-primary' : 'btn-outline-secondary' }}">
                    Hoje
                </a>
                <a href="{{ $nav['next7'] }}" class="btn btn-sm btn-outline-secondary">
                    Próximos 7 dias
                </a>
                <a href="{{ $nav['week'] }}"
                   class="btn btn-sm {{ $isWeekShortcut ? 'btn-primary' : 'btn-outline-secondary' }}">
                    Esta semana
                </a>
                @if ($activeFilterCount > 0)
                    <a href="{{ $nav['clearFilters'] }}" class="small ms-sm-auto">Limpar filtros</a>
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

    @if ($view === 'list')
        @include('app.agenda._list')
    @else
        <div class="card agenda-calendar mb-4"
             data-agenda-calendar
             data-view="{{ $view }}"
             data-date="{{ $anchorDate }}"
             data-start="{{ $date }}"
             data-end="{{ $endDate }}"
             data-today="{{ $today->toDateString() }}"
             data-now="{{ now(config('app.timezone'))->format('Y-m-d\TH:i:s') }}"
             data-day-url="{{ $dayUrlTemplate }}">
            <div class="agenda-calendar-body">
                <aside class="agenda-sidebar" aria-label="Mini calendário e legenda">
                    <div class="agenda-mini">
                        <div class="agenda-mini-title">
                            {{ $anchorCarbon->copy()->locale('pt_BR')->translatedFormat('F \d\e Y') }}
                        </div>
                        <div class="agenda-mini-head" aria-hidden="true">
                            @foreach ($weekdaysShort as $weekday)
                                <span>{{ mb_strtolower($weekday) }}</span>
                            @endforeach
                        </div>
                        <div class="agenda-mini-grid">
                            @for ($miniDay = $miniStart->copy(); $miniDay->lte($miniEnd); $miniDay->addDay())
                                <a href="{{ route('app.agenda.index', [...$filterQuery, 'view' => 'day', 'date' => $miniDay->toDateString()]) }}"
                                   class="agenda-mini-day
                                       @if (! $miniDay->isSameMonth($anchorCarbon)) is-out @endif
                                       @if ($miniDay->isToday()) is-today @endif
                                       @if ($miniDay->toDateString() === $anchorDate) is-selected @endif"
                                   aria-label="{{ $miniDay->copy()->locale('pt_BR')->translatedFormat('l, d \d\e F') }}">
                                    {{ $miniDay->day }}
                                </a>
                            @endfor
                        </div>
                    </div>

                    <div class="agenda-legend">
                        <div class="agenda-legend-title">Status</div>
                        @foreach ($statusOptions as $statusValue => $statusLabel)
                            <span class="agenda-legend-item">
                                <span class="agenda-legend-dot" data-status="{{ $statusValue }}"></span>
                                {{ $statusLabel }}
                            </span>
                        @endforeach
                        <span class="agenda-legend-item">
                            <span class="agenda-legend-dot" data-status="blocked"></span>
                            Indisponível
                        </span>
                    </div>

                    @if (! $isBarber && $barbers->isNotEmpty())
                        <div class="agenda-legend">
                            <div class="agenda-legend-title">Barbeiros</div>
                            <a href="{{ route('app.agenda.index', [...$withoutBarber, ...$currentViewQuery]) }}"
                               class="agenda-barber-item @if (! isset($filterQuery['barber_id'])) is-active @endif">
                                Todos
                            </a>
                            @foreach ($barbers as $barber)
                                <a href="{{ route('app.agenda.index', [...$withoutBarber, ...$currentViewQuery, 'barber_id' => $barber->id]) }}"
                                   class="agenda-barber-item @if ((string) ($filterQuery['barber_id'] ?? '') === (string) $barber->id) is-active @endif">
                                    {{ $barber->name }}
                                </a>
                            @endforeach
                        </div>
                    @endif
                </aside>

                <div class="agenda-main">
                    @if ($view === 'month')
                        <div class="agenda-weekhead" aria-hidden="true">
                            @foreach ($weekdaysShort as $weekday)
                                <span class="agenda-weekhead-cell">{{ $weekday }}</span>
                            @endforeach
                        </div>
                        <div class="agenda-month" role="group" aria-label="Calendário mensal">
                            <div class="agenda-month-body" data-agenda-month-body></div>
                        </div>
                    @else
                        <div class="agenda-timegrid" data-agenda-timegrid
                             style="--agenda-cols: {{ count($visibleDays) }};">
                            <div class="agenda-tg-corner agenda-tg-head" aria-hidden="true"></div>
                            @foreach ($visibleDays as $visibleDay)
                                <div class="agenda-tg-head agenda-daycol-head @if ($visibleDay['isToday']) is-today @endif @if ($visibleDay['isWeekend']) is-weekend @endif">
                                    <span class="agenda-daycol-wd">{{ $visibleDay['weekday'] }}</span>
                                    <span class="agenda-daycol-num">{{ $visibleDay['day'] }}</span>
                                </div>
                            @endforeach
                            <div class="agenda-tg-gutter" aria-hidden="true">
                                @for ($hour = 0; $hour < 24; $hour++)
                                    <span class="agenda-tg-hour">{{ sprintf('%02d:00', $hour) }}</span>
                                @endfor
                            </div>
                            @foreach ($visibleDays as $visibleDay)
                                <div class="agenda-daycol @if ($visibleDay['isToday']) is-today @endif @if ($visibleDay['isWeekend']) is-weekend @endif"
                                     data-day="{{ $visibleDay['date'] }}"
                                     aria-label="{{ $visibleDay['label'] }}"></div>
                            @endforeach
                        </div>
                    @endif

                    <p class="agenda-empty" data-agenda-empty hidden>
                        Nenhum agendamento neste período.
                    </p>
                </div>
            </div>

            <script type="application/json" data-agenda-events>@json($events, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG)</script>

            <noscript>
                @include('app.agenda._list')
            </noscript>
        </div>
    @endif

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
