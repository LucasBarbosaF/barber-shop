<x-app-layout title="Atendimento">
    @php
        $appointment = $attendance->appointment;
        $balance = $totalDue - $totalPaid;
        $money = fn (int $amount): string => (new \App\Domain\Shared\ValueObjects\Money(
            \App\Application\Payments\PaymentBalance::fromMinorUnits($amount),
        ))->format();
        $statusLabel = $attendance->status->value === 'open' ? 'Em andamento' : 'Fechado';
    @endphp

    <div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-4">
        <div>
            <a href="{{ route('app.agenda.index', ['date' => $appointment->starts_at->toDateString()]) }}"
               class="text-decoration-none small">
                <i class="fas fa-arrow-left me-1" aria-hidden="true"></i>Voltar à agenda
            </a>
            <h1 class="h3 mt-2 mb-1">Atendimento #{{ $attendance->id }}</h1>
            <p class="text-secondary mb-0">
                {{ $appointment->customer->name }} · {{ $appointment->barber->name }} ·
                {{ $appointment->starts_at->format('d/m/Y H:i') }}
            </p>
        </div>
        <span class="badge {{ $attendance->status->value === 'open' ? 'text-bg-primary' : 'text-bg-success' }} fs-6">
            {{ $statusLabel }}
        </span>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="card h-100">
                <div class="card-body">
                    <div class="text-secondary small">Cliente</div>
                    <div class="h5 mb-1">{{ $appointment->customer->name }}</div>
                    <a href="tel:{{ preg_replace('/\D+/', '', $appointment->customer->phone) }}">
                        {{ $appointment->customer->phone }}
                    </a>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card h-100">
                <div class="card-body">
                    <div class="text-secondary small">Barbeiro</div>
                    <div class="h5 mb-1">{{ $appointment->barber->name }}</div>
                    <div>{{ $appointment->service->name }} · {{ $appointment->service->duration_minutes }} min</div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card h-100">
                <div class="card-body">
                    <div class="text-secondary small">Total dos serviços</div>
                    <div class="h4 mb-0">R$ {{ $money($totalDue) }}</div>
                    @if ($attendance->status->value === 'closed')
                        <div class="text-secondary small mt-1">Saldo a receber: R$ {{ $money($balance) }}</div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
            <h2 class="h5 mb-0">Serviços realizados</h2>
            <span class="badge text-bg-secondary">{{ $attendance->items->count() }} item(ns)</span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 table-stack">
                <thead class="table-light">
                    <tr>
                        <th>Serviço</th>
                        <th class="text-end">Unitário</th>
                        <th class="text-center">Qtd.</th>
                        <th class="text-end">Subtotal</th>
                        <th class="text-end">Comissão congelada</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($attendance->items as $item)
                        <tr>
                            <td>{{ $item->service->name }}</td>
                            <td class="text-end">R$ {{ $money(\App\Application\Payments\PaymentBalance::toMinorUnits($item->unit_price_snapshot)) }}</td>
                            <td class="text-center">{{ $item->quantity }}</td>
                            <td class="text-end">R$ {{ $money(\App\Application\Payments\PaymentBalance::toMinorUnits($item->unit_price_snapshot) * $item->quantity) }}</td>
                            <td class="text-end">R$ {{ $money(\App\Application\Payments\PaymentBalance::toMinorUnits($item->commission_amount_snapshot)) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center text-secondary py-4">
                                Ainda não há serviços neste atendimento.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
                @if ($attendance->items->isNotEmpty())
                    <tfoot class="table-light">
                        <tr>
                            <th colspan="3" class="text-end">Total</th>
                            <th class="text-end">R$ {{ $money($totalDue) }}</th>
                            <th></th>
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>
    </div>

    @if ($attendance->status->value === 'open')
        @can('attendance.manage')
            <div class="row g-3 mb-4">
                <div class="col-lg-7">
                    <div class="card h-100">
                        <div class="card-header"><h2 class="h5 mb-0">Adicionar serviço</h2></div>
                        <div class="card-body">
                            <form method="POST" action="{{ route('app.attendances.items.store', $attendance) }}"
                                  class="row align-items-end g-3">
                                @csrf
                                <div class="col-md-7">
                                    <label for="service_id" class="form-label">Serviço</label>
                                    <select id="service_id" name="service_id"
                                            class="form-select @error('service_id') is-invalid @enderror" required>
                                        <option value="">Selecione um serviço</option>
                                        @foreach ($services as $service)
                                            <option value="{{ $service->id }}" @selected(old('service_id') == $service->id)>
                                                {{ $service->name }} — R$ {{ $money(\App\Application\Payments\PaymentBalance::toMinorUnits($service->price)) }}
                                            </option>
                                        @endforeach
                                    </select>
                                    @error('service_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                </div>
                                <div class="col-md-2">
                                    <label for="quantity" class="form-label">Quantidade</label>
                                    <input id="quantity" name="quantity" type="number" min="1" max="1000"
                                           value="{{ old('quantity', 1) }}"
                                           class="form-control @error('quantity') is-invalid @enderror" required>
                                    @error('quantity')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                </div>
                                <div class="col-md-3">
                                    <button class="btn btn-primary w-100" type="submit">Adicionar</button>
                                </div>
                                @error('attendance')<div class="col-12 text-danger small">{{ $message }}</div>@enderror
                                @error('commission_percentage')<div class="col-12 text-danger small">{{ $message }}</div>@enderror
                            </form>
                        </div>
                    </div>
                </div>
                <div class="col-lg-5">
                    <div class="card border-primary h-100">
                        <div class="card-body d-flex flex-column justify-content-between">
                            <div>
                                <h2 class="h5">Finalizar atendimento</h2>
                                <p class="text-secondary">Ao fechar, os serviços não poderão mais ser alterados e os valores ficam registrados para o recebimento.</p>
                            </div>
                            <form method="POST" action="{{ route('app.attendances.close', $attendance) }}"
                                  onsubmit="return confirm('Fechar este atendimento? Os serviços não poderão mais ser alterados.')">
                                @csrf
                                @method('PATCH')
                                <button class="btn btn-success" type="submit"
                                        @disabled($attendance->items->isEmpty())>
                                    <i class="fas fa-check me-1" aria-hidden="true"></i>Fechar atendimento
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        @endcan
    @else
        @if ($payments->isNotEmpty() || auth()->user()?->can('cash.view'))
            <div class="card mb-4">
                <div class="card-header"><h2 class="h5 mb-0">Recebimentos</h2></div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 table-stack">
                        <thead class="table-light">
                            <tr><th>Data</th><th>Forma</th><th class="text-end">Valor</th></tr>
                        </thead>
                        <tbody>
                            @forelse ($payments as $payment)
                                <tr>
                                    <td>{{ $payment->created_at->format('d/m/Y H:i') }}</td>
                                    <td>{{ ['cash' => 'Dinheiro', 'pix' => 'PIX', 'card' => 'Cartão'][$payment->method->value] }}</td>
                                    <td class="text-end">R$ {{ $money(\App\Application\Payments\PaymentBalance::toMinorUnits($payment->amount)) }}</td>
                                </tr>
                                @foreach ($payment->refunds as $refund)
                                    <tr class="text-secondary">
                                        <td>{{ $refund->refunded_at->format('d/m/Y H:i') }}</td>
                                        <td>Estorno · {{ $refund->reason }}</td>
                                        <td class="text-end">− R$ {{ $money(\App\Application\Payments\PaymentBalance::toMinorUnits($refund->amount)) }}</td>
                                    </tr>
                                @endforeach
                            @empty
                                <tr><td colspan="3" class="text-center text-secondary py-4">Nenhum pagamento registrado.</td></tr>
                            @endforelse
                        </tbody>
                        <tfoot class="table-light">
                            <tr><th colspan="2" class="text-end">Pago</th><th class="text-end">R$ {{ $money($totalPaid) }}</th></tr>
                            <tr><th colspan="2" class="text-end">Saldo</th><th class="text-end">R$ {{ $money($balance) }}</th></tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        @endif

        @if ($balance > 0)
            @can('cash.manage')
                <div class="card">
                    <div class="card-header"><h2 class="h5 mb-0">Registrar recebimento</h2></div>
                    <div class="card-body">
                        <form method="POST" action="{{ route('app.payments.store', $attendance) }}"
                              class="row align-items-end g-3">
                            @csrf
                            <input type="hidden" name="idempotency_key" value="{{ \Illuminate\Support\Str::uuid() }}">
                            <div class="col-md-4">
                                <label for="payment_amount" class="form-label">Valor (saldo: R$ {{ $money($balance) }})</label>
                                <input id="payment_amount" name="amount" type="number" min="0.01" max="{{ \App\Application\Payments\PaymentBalance::fromMinorUnits($balance) }}"
                                       step="0.01" value="{{ old('amount', \App\Application\Payments\PaymentBalance::fromMinorUnits($balance)) }}"
                                       class="form-control @error('amount') is-invalid @enderror" required>
                                @error('amount')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-md-4">
                                <label for="payment_method" class="form-label">Forma de pagamento</label>
                                @php
                                    $paymentMethodLabels = ['cash' => 'Dinheiro', 'pix' => 'PIX', 'card' => 'Cartão'];
                                    $enabledPaymentMethods = $currentBarbershop?->enabledPaymentMethodValues()
                                        ?? ['cash', 'pix', 'card'];
                                @endphp
                                <select id="payment_method" name="method"
                                        class="form-select @error('method') is-invalid @enderror" required>
                                    @foreach ($enabledPaymentMethods as $method)
                                        <option value="{{ $method }}" @selected(old('method', $enabledPaymentMethods[0] ?? '') === $method)>
                                            {{ $paymentMethodLabels[$method] }}
                                        </option>
                                    @endforeach
                                </select>
                                @error('method')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-md-4">
                                <button class="btn btn-primary" type="submit">Registrar pagamento</button>
                            </div>
                            @error('cash_register')<div class="col-12 text-danger small">{{ $message }}</div>@enderror
                            @error('idempotency_key')<div class="col-12 text-danger small">{{ $message }}</div>@enderror
                        </form>
                    </div>
                </div>
            @endcan
        @endif
    @endif
</x-app-layout>
