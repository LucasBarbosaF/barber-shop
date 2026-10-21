<x-booking-layout>
    <main class="booking-page">
        <div class="booking-container">
            <header class="booking-brand text-center">
                @if (filled($tenant->logo_path))
                    <img src="{{ asset('storage/'.$tenant->logo_path) }}" alt="Logo de {{ $tenant->name }}"
                         class="booking-logo">
                @else
                    <div class="booking-brand-icon" aria-hidden="true">
                        <i class="fas fa-scissors"></i>
                    </div>
                @endif
                <h1>Minha recorrência</h1>
                <p>{{ $subscription->plan_name }} em {{ $tenant->name }}</p>
            </header>

            @if (session('booking_status'))
                <div class="alert alert-success booking-alert" role="status">
                    <i class="fas fa-circle-check me-1" aria-hidden="true"></i>
                    {{ session('booking_status') }}
                </div>
            @endif

            @if ($errors->any())
                <div class="alert alert-danger booking-alert" role="alert" tabindex="-1" data-booking-errors>
                    <strong>Não foi possível agendar o corte.</strong>
                    <ul class="mb-0 mt-2">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="row g-3 mb-3">
                <div class="col-md-8">
                    <div class="booking-card h-100">
                        <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
                            <div>
                                <span class="booking-eyebrow">SUA ASSINATURA</span>
                                <h2 class="h5 mt-1 mb-1">{{ $subscription->plan_name }}</h2>
                                <p class="text-secondary mb-0">
                                    {{ $subscription->customer?->name }}
                                    @if ($subscription->barber)
                                        · {{ $subscription->barber->name }}
                                    @endif
                                </p>
                            </div>
                            <span class="badge text-bg-{{ match ($subscription->status->value) {
                                'active' => 'success',
                                'pending_payment' => 'warning',
                                default => 'secondary',
                            } }}">
                                {{ $subscription->status->label() }}
                            </span>
                        </div>

                        <dl class="booking-summary-list mt-3">
                            <div>
                                <dt>Vigência</dt>
                                <dd>
                                    {{ $subscription->starts_at->format('d/m/Y') }}
                                    até {{ $subscription->ends_at->format('d/m/Y') }}
                                </dd>
                            </div>
                            <div>
                                <dt>Cortes usados</dt>
                                <dd>{{ $usedInTerm }} de {{ $subscription->total_sessions }}</dd>
                            </div>
                            <div>
                                <dt>{{ ucfirst($monthLabel) }}</dt>
                                <dd>{{ $usedInMonth }} de {{ $subscription->sessions_per_month }} cortes usados</dd>
                            </div>
                            <div>
                                <dt>Valor mensal</dt>
                                <dd>
                                    R$ {{ number_format((float) $subscription->price, 2, ',', '.') }}/mês
                                    <span class="text-secondary">
                                        · total R$ {{ number_format((float) $subscription->price * $subscription->duration_months, 2, ',', '.') }}
                                    </span>
                                </dd>
                            </div>
                        </dl>

                        @if ($subscription->status->value === 'pending_payment')
                            <div class="alert alert-warning booking-alert mb-0" role="status">
                                <i class="fas fa-hourglass-half me-1" aria-hidden="true"></i>
                                Assim que a barbearia confirmar o pagamento, seu plano fica ativo e você poderá
                                agendar os próximos cortes nesta página.
                            </div>
                        @elseif ($subscription->status->value === 'canceled')
                            <div class="alert alert-secondary booking-alert mb-0" role="status">
                                <i class="fas fa-ban me-1" aria-hidden="true"></i>
                                Esta assinatura foi cancelada.
                                @if ($subscription->canceled_at)
                                    Em {{ $subscription->canceled_at->format('d/m/Y') }}.
                                @endif
                            </div>
                        @elseif ($subscription->status->value === 'expired')
                            <div class="alert alert-secondary booking-alert mb-0" role="status">
                                <i class="fas fa-calendar-xmark me-1" aria-hidden="true"></i>
                                A vigência deste plano terminou. Fale com a barbearia para renovar.
                            </div>
                        @endif
                    </div>
                </div>

                <div class="col-md-4">
                    <div class="booking-card h-100">
                        <span class="booking-eyebrow">CORTES</span>
                        <h2 class="h5 mt-1">Próximos e recentes</h2>
                        @if ($appointments->isEmpty())
                            <p class="text-secondary">Nenhum corte agendado ainda.</p>
                        @else
                            <ul class="list-unstyled mb-0">
                                @foreach ($appointments as $appointment)
                                    <li class="mb-2 d-flex justify-content-between gap-2">
                                        <span>
                                            {{ $appointment->starts_at->format('d/m/Y') }}
                                            às {{ $appointment->starts_at->format('H:i') }}
                                            <span class="text-secondary d-block">{{ $appointment->barber?->name }}</span>
                                        </span>
                                        <span class="badge text-bg-{{ match ($appointment->status->value) {
                                            'canceled' => 'secondary',
                                            'completed' => 'success',
                                            'no_show' => 'danger',
                                            default => 'primary',
                                        } }}">
                                            {{ \App\Domain\Agenda\Enums\AppointmentStatus::tryFrom($appointment->status->value)?->label() }}
                                        </span>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                </div>
            </div>

            <section class="booking-card mb-4">
                <span class="booking-eyebrow">MENSALIDADES</span>
                <h2 class="h5 mt-1">Pagamentos do contrato</h2>
                <p class="text-secondary">
                    Os cortes deste plano são liberados mês a mês, conforme os pagamentos são confirmados
                    pela barbearia.
                </p>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                            <tr>
                                <th scope="col">Mês</th>
                                <th scope="col">Valor</th>
                                <th scope="col">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($contractMonths as $entry)
                                @php($month = $entry['month'])
                                @php($payment = $entry['payment'])
                                <tr>
                                    <td class="text-nowrap">{{ $month->locale('pt_BR')->isoFormat('MMMM/YYYY') }}</td>
                                    <td class="text-nowrap">
                                        R$ {{ number_format((float) ($payment?->amount ?? $subscription->price), 2, ',', '.') }}
                                    </td>
                                    <td>
                                        @if ($payment !== null && $payment->status->value === 'paid')
                                            <span class="badge text-bg-success">Pago</span>
                                        @else
                                            <span class="badge text-bg-secondary">Aguardando pagamento</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>

            @if ($canSchedule && $barbers->isNotEmpty())
                <div class="booking-layout">
                    <section class="booking-card">
                        <div class="booking-card-heading">
                            <div>
                                <span class="booking-eyebrow">AGENDAR</span>
                                <h2>Próximo corte</h2>
                                <p>
                                    Restam {{ $remainingInMonth }} corte(s) em {{ $monthLabel }}
                                    e {{ $remainingInTerm }} no plano.
                                </p>
                            </div>
                        </div>

                        <form method="POST"
                              action="{{ route('booking.subscription.cuts.store', [$tenant->slug, $subscription->public_id]) }}"
                              data-booking-form
                              data-booking-fixed-service="{{ $subscription->service_id }}"
                              data-availability-url="{{ route('booking.subscription.availability', [$tenant->slug, $subscription->public_id]) }}">
                            @csrf

                            <section class="booking-section">
                                <div class="booking-section-title">
                                    <span class="booking-step-number">1</span>
                                    <div>
                                        <h3>Profissional e data</h3>
                                        <p>Serviço do plano: {{ $subscription->service?->name }}.</p>
                                    </div>
                                </div>

                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label for="barber_id" class="form-label">Profissional</label>
                                        <select id="barber_id" name="barber_id"
                                                class="form-select @error('barber_id') is-invalid @enderror"
                                                data-booking-barber required>
                                            <option value="">Selecione o profissional</option>
                                            @foreach ($barbers as $barber)
                                                <option value="{{ $barber->id }}"
                                                    data-service-ids="{{ $subscription->service_id }}"
                                                    @selected((string) old('barber_id') === (string) ($subscription->barber_id ?? ''))>
                                                    {{ $barber->name }}
                                                </option>
                                            @endforeach
                                        </select>
                                        @error('barber_id')
                                            <div class="invalid-feedback">{{ $message }}</div>
                                        @enderror
                                    </div>

                                    <div class="col-md-6">
                                        <label for="appointment_date" class="form-label">Data do corte</label>
                                        <input id="appointment_date" type="date" name="date"
                                               min="{{ now(config('app.timezone'))->toDateString() }}"
                                               value="{{ old('date') }}"
                                               class="form-control @error('date') is-invalid @enderror"
                                               data-booking-date required>
                                        @error('date')
                                            <div class="invalid-feedback">{{ $message }}</div>
                                        @enderror
                                    </div>
                                </div>

                                <fieldset class="booking-slots-field mt-3">
                                    <legend class="form-label">Horários disponíveis</legend>
                                    <p class="booking-slot-hint">Toque em um horário para selecioná-lo.</p>
                                    <div data-booking-slots class="booking-slots" aria-live="polite" tabindex="-1">
                                        <div class="booking-slot-placeholder">
                                            <i class="far fa-clock" aria-hidden="true"></i>
                                            <span>Escolha o profissional e a data para ver os horários.</span>
                                        </div>
                                    </div>
                                    <input type="hidden" name="time" data-booking-time value="{{ old('time') }}">
                                    @error('time')
                                        <div class="text-danger small mt-2">{{ $message }}</div>
                                    @enderror
                                    @error('subscription')
                                        <div class="text-danger small mt-2">{{ $message }}</div>
                                    @enderror
                                </fieldset>
                            </section>

                            <button type="submit" class="btn btn-primary btn-lg w-100 booking-submit">
                                <i class="fas fa-calendar-check me-2" aria-hidden="true"></i>
                                Agendar corte
                            </button>
                        </form>
                    </section>
                </div>
            @elseif (! $canSchedule && $subscription->status->value === 'pending_payment')
                <div class="booking-card text-center">
                    <p class="mb-0 text-secondary">
                        <i class="fas fa-lock me-1" aria-hidden="true"></i>
                        Assim que o pagamento for confirmado, o agendamento dos próximos cortes aparece aqui.
                    </p>
                </div>
            @endif

            <footer class="booking-footer">
                Precisa de ajuda? Entre em contato com <strong>{{ $tenant->name }}</strong>.
            </footer>
        </div>
    </main>
</x-booking-layout>
