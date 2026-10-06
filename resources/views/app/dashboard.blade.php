<x-app-layout title="Dashboard">
    @php
        $appointmentStatusLabels = [
            'pending' => 'Pendente',
            'confirmed' => 'Confirmado',
            'arrived' => 'Cliente chegou',
            'in_service' => 'Em atendimento',
            'completed' => 'Concluído',
            'canceled' => 'Cancelado',
            'no_show' => 'Não compareceu',
        ];
        $weekDays = collect(range(0, 6))->map(fn (int $offset) => $today->copy()->startOfDay()->addDays($offset));
        $maxAppointmentsInDay = max(1, (int) $dailyAppointmentCounts->max());
    @endphp

    <div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-4">
        <div>
            <p class="text-primary fw-semibold small text-uppercase mb-1">
                {{ $today->translatedFormat('l, d \d\e F \d\e Y') }}
            </p>
            <h1 class="h3 mb-1">Olá, {{ auth()->user()->name }}</h1>
            <p class="text-secondary mb-0">
                @if ($tenant)
                    Aqui está o resumo de hoje na {{ $tenant->name }}.
                @else
                    Selecione uma barbearia para consultar os indicadores e a agenda.
                @endif
            </p>
        </div>
        @if ($tenant && $canViewSchedule)
            <a href="{{ route('app.agenda.index', ['date' => $today->toDateString()]) }}"
               class="btn btn-primary">
                <i class="fas fa-calendar-day me-1" aria-hidden="true"></i>
                Abrir agenda de hoje
            </a>
        @endif
    </div>

    @if (! $tenant)
        <section class="card border-0 shadow-sm mb-4">
            <div class="card-body d-flex flex-wrap align-items-center gap-3 p-4">
                <span class="d-inline-flex align-items-center justify-content-center rounded-circle bg-primary-subtle text-primary"
                      style="width: 52px; height: 52px;">
                    <i class="fas fa-store fa-lg" aria-hidden="true"></i>
                </span>
                <div class="flex-grow-1">
                    <h2 class="h5 mb-1">Escolha o contexto da barbearia</h2>
                    <p class="text-secondary mb-0">
                        Os dados do dashboard são carregados separadamente para cada barbearia.
                        Use o seletor no topo da página para continuar.
                    </p>
                </div>
                @if (auth()->user()->isSuperadmin())
                    <a href="{{ route('admin.barbershops.index') }}" class="btn btn-outline-primary">
                        Gerenciar barbearias
                    </a>
                @endif
            </div>
        </section>
    @else
        <section class="row g-3 mb-4" aria-label="Indicadores de hoje">
            @if ($canViewSchedule)
                <div class="col-sm-6 col-xl-3">
                    <article class="card h-100 border-0 shadow-sm">
                        <div class="card-body d-flex align-items-center gap-3">
                            <span class="d-inline-flex align-items-center justify-content-center rounded-3 bg-primary-subtle text-primary"
                                  style="width: 48px; height: 48px;">
                                <i class="fas fa-calendar-check fa-lg" aria-hidden="true"></i>
                            </span>
                            <div>
                                <p class="text-secondary small mb-1">Agendamentos hoje</p>
                                <p class="h3 mb-0">{{ $todayAppointmentCount }}</p>
                            </div>
                        </div>
                        <div class="card-footer bg-transparent border-0 pt-0">
                            <a class="small text-decoration-none" href="{{ route('app.agenda.index', ['date' => $today->toDateString()]) }}">
                                Ver agenda <i class="fas fa-arrow-right ms-1" aria-hidden="true"></i>
                            </a>
                        </div>
                    </article>
                </div>
            @endif

            @if ($canViewAttendance)
                <div class="col-sm-6 col-xl-3">
                    <article class="card h-100 border-0 shadow-sm">
                        <div class="card-body d-flex align-items-center gap-3">
                            <span class="d-inline-flex align-items-center justify-content-center rounded-3 bg-warning-subtle text-warning-emphasis"
                                  style="width: 48px; height: 48px;">
                                <i class="fas fa-scissors fa-lg" aria-hidden="true"></i>
                            </span>
                            <div>
                                <p class="text-secondary small mb-1">Atendimentos em aberto</p>
                                <p class="h3 mb-0">{{ $openAttendanceCount }}</p>
                            </div>
                        </div>
                        <div class="card-footer bg-transparent border-0 pt-0">
                            @can('attendance.manage')
                                <a class="small text-decoration-none" href="{{ route('app.agenda.index') }}">
                                    Acessar atendimentos <i class="fas fa-arrow-right ms-1" aria-hidden="true"></i>
                                </a>
                            @else
                                <span class="small text-secondary">Em andamento</span>
                            @endcan
                        </div>
                    </article>
                </div>
            @endif

            @if ($canViewCustomers)
                <div class="col-sm-6 col-xl-3">
                    <article class="card h-100 border-0 shadow-sm">
                        <div class="card-body d-flex align-items-center gap-3">
                            <span class="d-inline-flex align-items-center justify-content-center rounded-3 bg-success-subtle text-success"
                                  style="width: 48px; height: 48px;">
                                <i class="fas fa-user-plus fa-lg" aria-hidden="true"></i>
                            </span>
                            <div>
                                <p class="text-secondary small mb-1">Novos clientes hoje</p>
                                <p class="h3 mb-0">{{ $newCustomersToday }}</p>
                            </div>
                        </div>
                        <div class="card-footer bg-transparent border-0 pt-0">
                            <a class="small text-decoration-none" href="{{ route('app.customers.index') }}">
                                Ver clientes <i class="fas fa-arrow-right ms-1" aria-hidden="true"></i>
                            </a>
                        </div>
                    </article>
                </div>
            @endif

            @if ($canViewFinance)
                <div class="col-sm-6 col-xl-3">
                    <article class="card h-100 border-0 shadow-sm">
                        <div class="card-body d-flex align-items-center gap-3">
                            <span class="d-inline-flex align-items-center justify-content-center rounded-3 bg-info-subtle text-info-emphasis"
                                  style="width: 48px; height: 48px;">
                                <i class="fas fa-sack-dollar fa-lg" aria-hidden="true"></i>
                            </span>
                            <div>
                                <p class="text-secondary small mb-1">Recebido hoje</p>
                                <p class="h3 mb-0">R$ {{ number_format((float) $revenueToday, 2, ',', '.') }}</p>
                            </div>
                        </div>
                        <div class="card-footer bg-transparent border-0 pt-0">
                            <a class="small text-decoration-none" href="{{ route('app.payments.index') }}">
                                Ver vendas <i class="fas fa-arrow-right ms-1" aria-hidden="true"></i>
                            </a>
                        </div>
                    </article>
                </div>
            @endif
        </section>

        <div class="row g-4">
            @if ($canViewSchedule)
                <div class="col-xl-7">
                    <section class="card h-100 border-0 shadow-sm">
                        <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2 py-3">
                            <div>
                                <h2 class="h5 mb-0">Agenda de hoje</h2>
                                <small class="text-secondary">{{ $todayAppointmentCount }} {{ $todayAppointmentCount === 1 ? 'horário reservado' : 'horários reservados' }}</small>
                            </div>
                            <a class="btn btn-sm btn-outline-primary"
                               href="{{ route('app.agenda.index', ['date' => $today->toDateString()]) }}">Ver todos</a>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th scope="col">Horário</th>
                                        <th scope="col">Cliente</th>
                                        <th scope="col">Serviço</th>
                                        <th scope="col">Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse ($appointmentsToday->take(6) as $appointment)
                                        @php($statusValue = $appointment->status->value)
                                        <tr>
                                            <td class="fw-semibold text-nowrap">
                                                {{ $appointment->starts_at->setTimezone(config('app.timezone'))->format('H:i') }}
                                            </td>
                                            <td>
                                                <div>{{ $appointment->customer->name }}</div>
                                                @if (! $isBarber)
                                                    <small class="text-secondary">{{ $appointment->barber->name }}</small>
                                                @endif
                                            </td>
                                            <td>{{ $appointment->service->name }}</td>
                                            <td>
                                                <span class="badge {{ in_array($statusValue, ['confirmed', 'arrived', 'in_service'], true) ? 'text-bg-primary' : 'text-bg-secondary' }}">
                                                    {{ $appointmentStatusLabels[$statusValue] }}
                                                </span>
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="4" class="text-center py-5">
                                                <i class="far fa-calendar-check fa-2x text-secondary mb-2" aria-hidden="true"></i>
                                                <p class="mb-0">Nenhum agendamento para hoje.</p>
                                            </td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </section>
                </div>

                <div class="col-xl-5">
                    <section class="card h-100 border-0 shadow-sm">
                        <div class="card-header bg-white py-3">
                            <h2 class="h5 mb-0">Próximos 7 dias</h2>
                            <small class="text-secondary">Agendamentos confirmados ou aguardando atendimento</small>
                        </div>
                        <div class="card-body">
                            <div class="d-flex flex-column gap-3">
                                @foreach ($weekDays as $day)
                                    @php($count = (int) ($dailyAppointmentCounts[$day->toDateString()] ?? 0))
                                    <div>
                                        <div class="d-flex justify-content-between align-items-center small mb-1">
                                            <span class="text-capitalize">{{ $day->translatedFormat('D, d/m') }}</span>
                                            <span class="text-secondary">{{ $count }} {{ $count === 1 ? 'agendamento' : 'agendamentos' }}</span>
                                        </div>
                                        <div class="progress" role="progressbar"
                                             aria-label="Agendamentos em {{ $day->translatedFormat('d/m') }}"
                                             aria-valuenow="{{ $count }}" aria-valuemin="0"
                                             aria-valuemax="{{ $maxAppointmentsInDay }}" style="height: 7px;">
                                            <div class="progress-bar" style="width: {{ $count ? max(4, (int) round($count / $maxAppointmentsInDay * 100)) : 0 }}%"></div>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                            <div class="border-top mt-4 pt-3">
                                <h3 class="h6 mb-3">Próximos atendimentos</h3>
                                @forelse ($upcomingAppointments->take(3) as $appointment)
                                    <div class="d-flex align-items-start justify-content-between gap-3 mb-3">
                                        <div class="d-flex align-items-start gap-2">
                                            <span class="badge text-bg-light border text-primary">
                                                {{ $appointment->starts_at->setTimezone(config('app.timezone'))->format('d/m') }}
                                            </span>
                                            <div>
                                                <div class="small fw-semibold">{{ $appointment->customer->name }}</div>
                                                <div class="small text-secondary">{{ $appointment->service->name }}</div>
                                            </div>
                                        </div>
                                        <span class="small fw-semibold text-nowrap">
                                            {{ $appointment->starts_at->setTimezone(config('app.timezone'))->format('H:i') }}
                                        </span>
                                    </div>
                                @empty
                                    <p class="small text-secondary mb-0">Nenhum atendimento futuro confirmado nos próximos dias.</p>
                                @endforelse
                            </div>
                            <a class="btn btn-outline-primary btn-sm w-100 mt-4"
                               href="{{ route('app.agenda.index', [
                                    'start_date' => $today->toDateString(),
                                    'end_date' => $today->copy()->addDays(6)->toDateString(),
                                ]) }}">
                                Abrir agenda da semana
                            </a>
                        </div>
                    </section>
                </div>
            @endif

            @if ($canViewFinance && $revenueToday < 0)
                <div class="col-12">
                    <div class="alert alert-info mb-0" role="status">
                        O total líquido está negativo hoje porque os estornos registrados superam os recebimentos do dia.
                    </div>
                </div>
            @endif
        </div>
    @endif

    @if (auth()->user()->isSuperadmin())
        <section class="card border-0 shadow-sm mt-4">
            <div class="card-body d-flex flex-wrap align-items-center justify-content-between gap-3">
                <div>
                    <span class="badge text-bg-dark mb-2">PLATAFORMA</span>
                    <h2 class="h5 mb-1">Administração global</h2>
                    <p class="text-secondary mb-0">Gerencie as barbearias cadastradas na plataforma.</p>
                </div>
                <a href="{{ route('admin.barbershops.index') }}" class="btn btn-outline-dark">
                    Gerenciar barbearias
                </a>
            </div>
        </section>
    @endif
</x-app-layout>
