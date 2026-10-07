{{--
    Lista de agendamentos do período — a visão "Lista" da agenda e, nas visões
    de calendário, o conteúdo do <noscript>: sem JavaScript o calendário não se
    desenha, e esta tabela é o retorno legível (e testável) para o mesmo
    intervalo de datas.
--}}
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
                                    $statusValue = $appointment->status->value;
                                    $statusTargets = $appointment->status->targets();
                                @endphp
                                <span class="badge {{ in_array($appointment->status->value, ['confirmed', 'arrived', 'in_service'], true) ? 'text-bg-primary' : 'text-bg-secondary' }}">
                                    {{ $appointment->status->label() }}
                                </span>
                                @can('schedule.manage')
                                    @if ($statusTargets !== [])
                                        {{--
                                            <details> em vez de dropdown do Bootstrap: esta tabela também
                                            é o <noscript> das visões de calendário, e sem JS o menu
                                            precisa abrir sozinho.
                                        --}}
                                        <details class="agenda-status-menu">
                                            <summary class="btn btn-sm btn-outline-secondary mt-1">
                                                <i class="fas fa-ellipsis-v me-1" aria-hidden="true"></i>
                                                Mudar status
                                            </summary>
                                            <ul class="agenda-status-menu-list">
                                                @foreach ($statusTargets as $target)
                                                    <li>
                                                        <form method="POST"
                                                              action="{{ route('app.agenda.appointments.status', $appointment) }}">
                                                            @csrf
                                                            @method('PATCH')
                                                            <input type="hidden" name="status"
                                                                   value="{{ $target->value }}">
                                                            <button class="dropdown-item" type="submit">
                                                                {{ $target->label() }}
                                                            </button>
                                                        </form>
                                                    </li>
                                                @endforeach
                                            </ul>
                                        </details>
                                    @endif
                                @endcan
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
                                    <a href="{{ $nav['clearFilters'] }}" class="btn btn-sm btn-outline-primary">
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
