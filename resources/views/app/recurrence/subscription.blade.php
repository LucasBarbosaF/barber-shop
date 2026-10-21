<x-app-layout title="Assinatura de {{ $subscription->customer?->name ?? 'cliente' }}">
    <div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-4">
        <div>
            <a href="{{ route('app.recurrence.index', ['tab' => 'assinaturas']) }}" class="small">
                <i class="fas fa-arrow-left me-1" aria-hidden="true"></i>
                Assinaturas
            </a>
            <h1 class="h3 mb-1 mt-1">{{ $subscription->plan_name }}</h1>
            <p class="text-secondary mb-0">
                {{ $subscription->customer?->name }}
                · {{ $subscription->customer?->phone }}
                @if ($subscription->barber)
                    · Preferência: {{ $subscription->barber->name }}
                @endif
            </p>
        </div>
        <div>
            @include('app.recurrence.partials.status-badge', ['subscription' => $subscription])
        </div>
    </div>

    @if (session('status'))
        <div class="alert alert-success" role="status">
            <i class="fas fa-circle-check me-1" aria-hidden="true"></i>
            {{ session('status') }}
        </div>
    @endif

    <div class="row g-3 mb-4">
        <div class="col-6 col-lg-3">
            <div class="card h-100">
                <div class="card-body">
                    <div class="small text-secondary">Vigência</div>
                    <div class="fs-6 fw-semibold">
                        {{ $subscription->starts_at->format('d/m/Y') }} – {{ $subscription->ends_at->format('d/m/Y') }}
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card h-100">
                <div class="card-body">
                    <div class="small text-secondary">Cortes usados</div>
                    <div class="fs-6 fw-semibold">{{ $usedInTerm }} de {{ $subscription->total_sessions }}</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card h-100">
                <div class="card-body">
                    <div class="small text-secondary">{{ ucfirst($monthLabel) }}</div>
                    <div class="fs-6 fw-semibold">{{ $usedInMonth }} de {{ $subscription->sessions_per_month }}</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card h-100">
                <div class="card-body">
                    <div class="small text-secondary">Semana atual</div>
                    <div class="fs-6 fw-semibold">{{ $usedInWeek }} de {{ $subscription->max_sessions_per_week }}</div>
                </div>
            </div>
        </div>
    </div>

    @if ($subscription->status->value === 'pending_payment')
        @can('recurrence.manage')
            <section class="card mb-4">
                <div class="card-header">
                    <h2 class="h5 mb-0">Confirmar pagamento</h2>
                    <div class="small text-secondary">
                        Ativa a assinatura, registra a mensalidade do primeiro mês e lança o valor no caixa,
                        se houver caixa aberto.
                    </div>
                </div>
                <div class="card-body">
                    <form method="POST" action="{{ route('app.recurrence.subscriptions.activate', $subscription) }}"
                          class="row g-3">
                        @csrf
                        <div class="col-sm-4">
                            <label for="amount" class="form-label">Valor recebido (R$)</label>
                            <input id="amount" name="amount" type="text" inputmode="decimal" maxlength="15"
                                   data-mask="currency" placeholder="0,00"
                                   value="{{ old('amount', number_format((float) $subscription->price, 2, ',', '.')) }}"
                                   class="form-control @error('amount') is-invalid @enderror" required>
                            @error('amount')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                        <div class="col-sm-4">
                            <label for="method" class="form-label">Forma de pagamento</label>
                            <select id="method" name="method"
                                    class="form-select @error('method') is-invalid @enderror" required>
                                @foreach ($paymentMethods as $paymentMethod)
                                    <option value="{{ $paymentMethod->value }}"
                                        @selected(old('method') === $paymentMethod->value)>
                                        {{ ucfirst($paymentMethod->value) }}
                                    </option>
                                @endforeach
                            </select>
                            @error('method')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                        <div class="col-sm-4">
                            <label for="obs" class="form-label">Observação</label>
                            <input id="obs" name="obs" type="text" maxlength="255" value="{{ old('obs') }}"
                                   class="form-control @error('obs') is-invalid @enderror">
                            @error('obs')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                        <div class="col-12">
                            <button type="submit" class="btn btn-success">
                                <i class="fas fa-check me-1" aria-hidden="true"></i>
                                Confirmar pagamento e ativar
                            </button>
                        </div>
                    </form>
                </div>
            </section>
        @endcan
    @elseif ($subscription->status->value === 'active')
        @if ($unpostedPayments->isNotEmpty())
            @can('recurrence.manage')
                <div class="card border-warning mb-4">
                    <div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-2">
                        <div>
                            <i class="fas fa-triangle-exclamation text-warning me-1" aria-hidden="true"></i>
                            {{ $unpostedPayments->count() === 1 ? 'Um pagamento confirmado' : $unpostedPayments->count() . ' pagamentos confirmados' }},
                            ainda sem lançamento no caixa.
                        </div>
                        <form method="POST" action="{{ route('app.recurrence.subscriptions.cash', $subscription) }}">
                            @csrf
                            <button type="submit" class="btn btn-sm btn-warning">
                                <i class="fas fa-cash-register me-1" aria-hidden="true"></i>
                                Lançar no caixa
                            </button>
                        </form>
                    </div>
                </div>
            @endcan
        @elseif ($subscription->payments->whereNotNull('cash_posted_at')->isNotEmpty())
            <div class="card border-success mb-4">
                <div class="card-body small text-secondary">
                    <i class="fas fa-check-circle text-success me-1" aria-hidden="true"></i>
                    Pagamentos lançados no caixa em
                    {{ $subscription->payments->whereNotNull('cash_posted_at')->max('cash_posted_at')->format('d/m/Y H:i') }}.
                </div>
            </div>
        @endif
    @endif

    <section class="card mb-4">
        <div class="card-header">
            <h2 class="h5 mb-0">Mensalidades</h2>
            <div class="small text-secondary">
                Valor mensal de R$ {{ number_format((float) $subscription->price, 2, ',', '.') }} —
                o corte só é liberado em mês com pagamento confirmado.
            </div>
        </div>
        @if ($errors->any())
            <div class="card-body py-2 border-bottom">
                <div class="text-danger small mb-0">{{ $errors->first() }}</div>
            </div>
        @endif
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 table-stack">
                <thead class="table-light">
                    <tr>
                        <th scope="col">Mês</th>
                        <th scope="col">Valor</th>
                        <th scope="col">Status</th>
                        <th scope="col">Pagamento</th>
                        <th scope="col">Caixa</th>
                        @if ($subscription->status->value === 'active')
                            @can('recurrence.manage')
                                <th scope="col"><span class="visually-hidden">Ações</span></th>
                            @endcan
                        @endif
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
                                <span class="badge {{ $payment !== null && $payment->status->value === 'paid' ? 'text-bg-success' : 'text-bg-secondary' }}">
                                    {{ $payment?->status->label() ?? 'Aguardando pagamento' }}
                                </span>
                            </td>
                            <td class="text-nowrap">
                                @if ($payment?->paid_at)
                                    {{ $payment->paid_at->format('d/m/Y') }}
                                    ({{ ucfirst($payment->method ?? '—') }})
                                @else
                                    —
                                @endif
                            </td>
                            <td class="text-nowrap">
                                {{ $payment?->cash_posted_at?->format('d/m/Y H:i') ?? '—' }}
                            </td>
                            @if ($subscription->status->value === 'active')
                                @can('recurrence.manage')
                                    <td>
                                        @if ($payment === null)
                                            <form method="POST"
                                                  action="{{ route('app.recurrence.subscriptions.payments.store', $subscription) }}"
                                                  class="d-flex flex-wrap gap-1 align-items-center">
                                                @csrf
                                                <input type="hidden" name="period_month" value="{{ $month->toDateString() }}">
                                                <input type="text" name="amount" inputmode="decimal" maxlength="15"
                                                       data-mask="currency" placeholder="0,00"
                                                       value="{{ old('amount', number_format((float) $subscription->price, 2, ',', '.')) }}"
                                                       class="form-control form-control-sm @error('amount') is-invalid @enderror"
                                                       style="width: 6.5rem" aria-label="Valor recebido" required>
                                                <select name="method"
                                                        class="form-select form-select-sm @error('method') is-invalid @enderror"
                                                        style="width: auto" aria-label="Forma de pagamento" required>
                                                    @foreach ($paymentMethods as $paymentMethod)
                                                        <option value="{{ $paymentMethod->value }}"
                                                            @selected(old('method') === $paymentMethod->value)>
                                                            {{ ucfirst($paymentMethod->value) }}
                                                        </option>
                                                    @endforeach
                                                </select>
                                                <button type="submit" class="btn btn-sm btn-outline-success text-nowrap">
                                                    Registrar
                                                </button>
                                            </form>
                                        @endif
                                    </td>
                                @endcan
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>

    <div class="row g-4 mb-4">
        <div class="col-lg-6">
            <section class="card h-100">
                <div class="card-header">
                    <h2 class="h5 mb-0">Dados do contrato</h2>
                    <div class="small text-secondary">Snapshot do plano no momento da assinatura.</div>
                </div>
                <div class="card-body">
                    <dl class="row mb-0">
                        <dt class="col-sm-6 text-secondary fw-normal">Serviço</dt>
                        <dd class="col-sm-6">{{ $subscription->service?->name ?? '—' }}</dd>

                        <dt class="col-sm-6 text-secondary fw-normal">Cortes por mês</dt>
                        <dd class="col-sm-6">{{ $subscription->sessions_per_month }}</dd>

                        <dt class="col-sm-6 text-secondary fw-normal">Máximo por semana</dt>
                        <dd class="col-sm-6">{{ $subscription->max_sessions_per_week }}</dd>

                        <dt class="col-sm-6 text-secondary fw-normal">Total do pacote</dt>
                        <dd class="col-sm-6">{{ $subscription->total_sessions }} cortes</dd>

                        <dt class="col-sm-6 text-secondary fw-normal">Valor mensal</dt>
                        <dd class="col-sm-6">R$ {{ number_format((float) $subscription->price, 2, ',', '.') }}</dd>

                        <dt class="col-sm-6 text-secondary fw-normal">Total do contrato</dt>
                        <dd class="col-sm-6">
                            R$ {{ number_format((float) $subscription->price * $subscription->duration_months, 2, ',', '.') }}
                            ({{ $subscription->duration_months }} meses)
                        </dd>

                        @if ($subscription->paid_at)
                            <dt class="col-sm-6 text-secondary fw-normal">Pago em</dt>
                            <dd class="col-sm-6">
                                {{ $subscription->paid_at->format('d/m/Y H:i') }}
                                ({{ ucfirst($subscription->paid_method ?? '—') }})
                            </dd>
                        @endif

                        @if ($subscription->status->value === 'canceled')
                            <dt class="col-sm-6 text-secondary fw-normal">Cancelada em</dt>
                            <dd class="col-sm-6">
                                {{ $subscription->canceled_at?->format('d/m/Y H:i') ?? '—' }}
                                @if ($subscription->cancelation_reason)
                                    <div class="small text-secondary">{{ $subscription->cancelation_reason }}</div>
                                @endif
                            </dd>
                        @endif
                    </dl>

                    @if ($subscription->status->value === 'pending_payment')
                        <p class="small text-secondary mb-0 mt-3">
                            <i class="fas fa-circle-info me-1" aria-hidden="true"></i>
                            O corte de abertura já foi criado. Os próximos cortes podem ser agendados após a
                            confirmação do pagamento.
                        </p>
                    @endif
                </div>
            </section>
        </div>

        <div class="col-lg-6">
            <section class="card h-100">
                <div class="card-header">
                    <h2 class="h5 mb-0">Link do cliente</h2>
                    <div class="small text-secondary">O cliente acompanha a cota e agenda sozinho por este link.</div>
                </div>
                <div class="card-body">
                    <div class="input-group">
                        <input type="text" class="form-control" readonly
                               value="{{ route('booking.subscription.manage', [$currentBarbershop?->slug, $subscription->public_id]) }}"
                               data-subscription-link onclick="this.select()">
                        <button type="button" class="btn btn-outline-primary"
                                onclick="navigator.clipboard.writeText(this.previousElementSibling.value)">
                            <i class="fas fa-copy me-1" aria-hidden="true"></i>
                            Copiar
                        </button>
                    </div>
                    <p class="small text-secondary mt-2 mb-0">
                        <i class="fas fa-link me-1" aria-hidden="true"></i>
                        <a href="{{ route('booking.subscription.manage', [$currentBarbershop?->slug, $subscription->public_id]) }}"
                           target="_blank" rel="noopener">
                            Abrir em nova aba
                        </a>
                    </p>
                </div>
            </section>
        </div>
    </div>

    @if ($subscription->status->value === 'active')
        @can('recurrence.manage')
            <section class="card mb-4">
                <div class="card-header">
                    <h2 class="h5 mb-0">Agendar corte</h2>
                    <div class="small text-secondary">
                        As cotas do plano são validadas antes de confirmar o agendamento.
                    </div>
                </div>
                <div class="card-body">
                    <form method="POST" action="{{ route('app.recurrence.subscriptions.cuts.store', $subscription) }}"
                          class="row g-3">
                        @csrf
                        <div class="col-sm-4">
                            <label for="cut_barber_id" class="form-label">Barbeiro</label>
                            <select id="cut_barber_id" name="barber_id"
                                    class="form-select @error('barber_id') is-invalid @enderror" required>
                                <option value="">Selecione</option>
                                @foreach ($barbers as $barber)
                                    <option value="{{ $barber->id }}"
                                        @selected((string) old('barber_id') === (string) ($subscription->barber_id ?? ''))>
                                        {{ $barber->name }}
                                    </option>
                                @endforeach
                            </select>
                            @error('barber_id')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                        <div class="col-sm-4">
                            <label for="cut_date" class="form-label">Data</label>
                            <input id="cut_date" name="date" type="date" value="{{ old('date') }}"
                                   min="{{ now()->toDateString() }}"
                                   class="form-control @error('date') is-invalid @enderror" required>
                            @error('date')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                        <div class="col-sm-4">
                            <label for="cut_time" class="form-label">Horário</label>
                            <input id="cut_time" name="time" type="time" value="{{ old('time') }}"
                                   class="form-control @error('time') is-invalid @enderror" required>
                            @error('time')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                        @error('subscription')
                            <div class="col-12 text-danger small">{{ $message }}</div>
                        @enderror
                        <div class="col-12">
                            <button type="submit" class="btn btn-outline-primary">
                                <i class="fas fa-calendar-check me-1" aria-hidden="true"></i>
                                Agendar corte
                            </button>
                        </div>
                    </form>
                </div>
            </section>
        @endcan
    @endif

    <section class="card mb-4">
        <div class="card-header">
            <h2 class="h5 mb-0">Cortes da assinatura</h2>
            <div class="small text-secondary">Até 30 agendamentos mais recentes.</div>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 table-stack">
                <thead class="table-light">
                    <tr>
                        <th scope="col">Data</th>
                        <th scope="col">Barbeiro</th>
                        <th scope="col">Status</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($appointments as $appointment)
                        <tr>
                            <td class="text-nowrap">
                                {{ $appointment->starts_at->format('d/m/Y') }}
                                às {{ $appointment->starts_at->format('H:i') }}
                            </td>
                            <td>{{ $appointment->barber?->name ?? '—' }}</td>
                            <td>
                                <span class="badge {{ in_array($appointment->status->value, ['confirmed', 'arrived', 'in_service'], true) ? 'text-bg-primary' : 'text-bg-secondary' }}">
                                    {{ $appointment->status->label() }}
                                </span>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="3" class="text-center text-secondary py-4">
                                Nenhum corte agendado ainda.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    @can('recurrence.manage')
        @if (in_array($subscription->status->value, ['pending_payment', 'active'], true))
            <section class="card border-danger">
                <div class="card-body">
                    <h2 class="h5">Cancelar assinatura</h2>
                    <p class="small text-secondary">
                        O cancelamento é definitivo. Opcionalmente, os cortes futuros já agendados também são
                        cancelados — os atendimentos realizados permanecem no histórico.
                    </p>
                    <form method="POST" action="{{ route('app.recurrence.subscriptions.cancel', $subscription) }}"
                          onsubmit="return confirm('Deseja cancelar esta assinatura?')">
                        @csrf
                        <div class="row g-3 align-items-end">
                            <div class="col-md-6">
                                <label for="cancel_reason" class="form-label">Motivo (opcional)</label>
                                <input id="cancel_reason" name="reason" type="text" maxlength="255"
                                       value="{{ old('reason') }}"
                                       class="form-control @error('reason') is-invalid @enderror">
                                @error('reason')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="col-md-4">
                                <div class="form-check">
                                    <input type="hidden" name="cancel_future_appointments" value="0">
                                    <input id="cancel_future_appointments" name="cancel_future_appointments"
                                           type="checkbox" value="1" class="form-check-input"
                                           @checked(old('cancel_future_appointments', true))>
                                    <label for="cancel_future_appointments" class="form-check-label">
                                        Cancelar cortes futuros
                                    </label>
                                </div>
                            </div>
                            <div class="col-md-2 d-grid">
                                <button type="submit" class="btn btn-outline-danger">
                                    <i class="fas fa-ban me-1" aria-hidden="true"></i>
                                    Cancelar
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </section>
        @endif
    @endcan
</x-app-layout>
