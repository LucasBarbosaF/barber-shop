<x-app-layout title="Caixa">
    <div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-4">
        <div>
            <h1 class="h3 mb-0">Caixa</h1>
            <p class="text-secondary mb-0">Acompanhe aberturas, fechamentos e movimentações.</p>
        </div>
        <a href="{{ route('app.payments.index') }}" class="btn btn-outline-primary">
            <i class="fas fa-file-invoice me-1" aria-hidden="true"></i>
            Ver vendas
        </a>
    </div>

    @php
        $hasOpenRegister = $cashRegisters->contains(fn ($register) => $register->status->value === 'open');
    @endphp
    @if (! $hasOpenRegister)
        @can('cash.manage')
            <div class="card border-primary mb-4">
                <div class="card-header">
                    <h2 class="h5 mb-0">Abrir caixa</h2>
                </div>
                <div class="card-body">
                    <p class="text-secondary">
                        Informe o valor inicial em dinheiro. Para receber pagamentos em espécie, é necessário abrir o caixa.
                    </p>
                    <form method="POST" action="{{ route('app.cash.open') }}" class="row align-items-end g-3">
                        @csrf
                        <div class="col-sm-6 col-md-4">
                            <label for="opening_amount" class="form-label">Saldo inicial (R$)</label>
                            <input id="opening_amount" name="opening_amount" type="number" min="0" step="0.01"
                                   inputmode="decimal" value="{{ old('opening_amount', '0.00') }}"
                                   class="form-control @error('opening_amount') is-invalid @enderror" required>
                            @error('opening_amount')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                            @error('cash_register')
                                <div class="text-danger small mt-1">{{ $message }}</div>
                            @enderror
                        </div>
                        <div class="col-auto">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-lock-open me-1" aria-hidden="true"></i>
                                Abrir caixa
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        @endcan
    @endif

    @if ($cashRegisters->isEmpty())
        <div class="card">
            <div class="card-body text-center text-secondary py-5">
                <i class="fas fa-cash-register fa-2x mb-3 d-block" aria-hidden="true"></i>
                @can('cash.manage')
                    Use o formulário acima para abrir o primeiro caixa.
                @else
                    Nenhum caixa foi aberto ainda. Peça a alguém com permissão de movimentar o caixa para abri-lo.
                @endcan
            </div>
        </div>
    @endif

    @foreach ($cashRegisters as $cashRegister)
        <section class="card mb-4" aria-labelledby="cash-register-{{ $cashRegister->id }}">
            <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                <h2 id="cash-register-{{ $cashRegister->id }}" class="h5 mb-0">
                    Caixa aberto em {{ $cashRegister->opened_at->format('d/m/Y H:i') }}
                </h2>
                <span class="badge {{ $cashRegister->status->value === 'open' ? 'text-bg-success' : 'text-bg-secondary' }}">
                    {{ $cashRegister->status->value === 'open' ? 'Aberto' : 'Fechado' }}
                </span>
            </div>
            <div class="card-body">
                <div class="row g-3 mb-3">
                    <div class="col-6 col-lg-3">
                        <div class="text-secondary small">Saldo inicial</div>
                        <div class="fw-semibold">R$ {{ number_format((float) $cashRegister->opening_amount, 2, ',', '.') }}</div>
                    </div>
                    @if ($cashRegister->status->value === 'closed')
                        <div class="col-6 col-lg-3">
                            <div class="text-secondary small">Saldo esperado</div>
                            <div class="fw-semibold">R$ {{ number_format((float) $cashRegister->expected_amount, 2, ',', '.') }}</div>
                        </div>
                        <div class="col-6 col-lg-3">
                            <div class="text-secondary small">Valor contado</div>
                            <div class="fw-semibold">R$ {{ number_format((float) $cashRegister->closing_amount, 2, ',', '.') }}</div>
                        </div>
                        <div class="col-6 col-lg-3">
                            <div class="text-secondary small">Diferença</div>
                            <div class="fw-semibold">R$ {{ number_format((float) $cashRegister->difference_amount, 2, ',', '.') }}</div>
                        </div>
                    @else
                        <div class="col-6 col-lg-3">
                            <div class="text-secondary small">Saldo esperado</div>
                            <div class="fw-semibold">R$ {{ number_format((float) $expectedOpenAmount, 2, ',', '.') }}</div>
                        </div>
                    @endif
                </div>

                @if ($cashRegister->status->value === 'open')
                    @can('cash.manage')
                        <div class="row g-4 mb-4">
                            <div class="col-lg-6">
                                <div class="border rounded p-3 h-100">
                                    <h3 class="h6">Registrar movimentação</h3>
                                    <p class="small text-secondary">
                                        Use para entradas ou retiradas manuais. Pagamentos e estornos são lançados automaticamente.
                                    </p>
                                    <form method="POST"
                                          action="{{ route('app.cash.transactions.store', $cashRegister) }}"
                                          class="row g-3">
                                        @csrf
                                        <div class="col-sm-6">
                                            <label for="movement_type_{{ $cashRegister->id }}" class="form-label">Tipo</label>
                                            <select id="movement_type_{{ $cashRegister->id }}" name="type"
                                                    class="form-select @error('type') is-invalid @enderror" required>
                                                <option value="cash_in" @selected(old('type') === 'cash_in')>Entrada</option>
                                                <option value="cash_out" @selected(old('type') === 'cash_out')>Retirada</option>
                                            </select>
                                            @error('type')
                                                <div class="invalid-feedback">{{ $message }}</div>
                                            @enderror
                                        </div>
                                        <div class="col-sm-6">
                                            <label for="movement_amount_{{ $cashRegister->id }}" class="form-label">Valor (R$)</label>
                                            <input id="movement_amount_{{ $cashRegister->id }}" name="amount" type="number"
                                                   min="0.01" step="0.01" inputmode="decimal" value="{{ old('amount') }}"
                                                   class="form-control @error('amount') is-invalid @enderror" required>
                                            @error('amount')
                                                <div class="invalid-feedback">{{ $message }}</div>
                                            @enderror
                                        </div>
                                        <div class="col-12">
                                            <label for="movement_description_{{ $cashRegister->id }}" class="form-label">Descrição</label>
                                            <input id="movement_description_{{ $cashRegister->id }}" name="description"
                                                   type="text" minlength="3" maxlength="500" value="{{ old('description') }}"
                                                   class="form-control @error('description') is-invalid @enderror" required>
                                            @error('description')
                                                <div class="invalid-feedback">{{ $message }}</div>
                                            @enderror
                                        </div>
                                        @error('cash_register')
                                            <div class="col-12 text-danger small">{{ $message }}</div>
                                        @enderror
                                        <div class="col-12">
                                            <button type="submit" class="btn btn-outline-primary">Registrar movimentação</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                            <div class="col-lg-6">
                                <div class="border rounded p-3 h-100">
                                    <h3 class="h6">Fechar caixa</h3>
                                    <p class="small text-secondary">
                                        Conte o dinheiro físico e informe o total. O sistema registra a diferença em relação ao saldo esperado.
                                    </p>
                                    <form method="POST" action="{{ route('app.cash.close', $cashRegister) }}" class="row g-3">
                                        @csrf
                                        @method('PATCH')
                                        <div class="col-sm-7">
                                            <label for="closing_amount_{{ $cashRegister->id }}" class="form-label">Valor contado (R$)</label>
                                            <input id="closing_amount_{{ $cashRegister->id }}" name="closing_amount" type="number"
                                                   min="0" step="0.01" inputmode="decimal" value="{{ old('closing_amount') }}"
                                                   class="form-control @error('closing_amount') is-invalid @enderror" required>
                                            @error('closing_amount')
                                                <div class="invalid-feedback">{{ $message }}</div>
                                            @enderror
                                        </div>
                                        @error('cash_register')
                                            <div class="col-12 text-danger small">{{ $message }}</div>
                                        @enderror
                                        <div class="col-12 align-self-end">
                                            <button type="submit" class="btn btn-danger">Fechar caixa</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    @endcan
                @endif

                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0 table-stack">
                        <thead class="table-light">
                            <tr>
                                <th scope="col">Data</th>
                                <th scope="col">Movimentação</th>
                                <th scope="col">Descrição</th>
                                <th scope="col" class="text-end">Valor</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($cashRegister->transactions as $transaction)
                                @php
                                    $transactionLabels = [
                                        'cash_in' => 'Entrada',
                                        'cash_out' => 'Retirada',
                                        'payment' => 'Pagamento',
                                        'refund' => 'Estorno',
                                    ];
                                @endphp
                                <tr>
                                    <td>{{ $transaction->created_at->format('d/m/Y H:i') }}</td>
                                    <td>{{ $transactionLabels[$transaction->type->value] }}</td>
                                    <td>{{ $transaction->description }}</td>
                                    <td class="text-end">R$ {{ number_format((float) $transaction->amount, 2, ',', '.') }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="text-center text-secondary py-4">
                                        Nenhuma movimentação registrada neste caixa.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    @endforeach
</x-app-layout>
