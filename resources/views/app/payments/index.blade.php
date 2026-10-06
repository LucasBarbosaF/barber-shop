<x-app-layout title="Vendas">
    <div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-4">
        <div>
            <h1 class="h3 mb-0">Vendas</h1>
            <p class="text-secondary mb-0">Pagamentos e estornos recentes dos atendimentos.</p>
        </div>
        <a href="{{ route('app.cash.index') }}" class="btn btn-outline-primary">
            <i class="fas fa-cash-register me-1" aria-hidden="true"></i>
            Ver caixa
        </a>
    </div>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 table-stack">
                <thead class="table-light">
                    <tr>
                        <th scope="col">Data</th>
                        <th scope="col">Cliente</th>
                        <th scope="col">Barbeiro</th>
                        <th scope="col">Forma</th>
                        <th scope="col">Valor</th>
                        <th scope="col">Estornado</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($payments as $payment)
                        @php
                            $refundedAmount = $payment->refunds->sum(fn ($refund) => (float) $refund->amount);
                            $methodLabels = ['cash' => 'Dinheiro', 'pix' => 'PIX', 'card' => 'Cartão'];
                        @endphp
                        <tr>
                            <td>{{ $payment->created_at->format('d/m/Y H:i') }}</td>
                            <td>{{ $payment->attendance->appointment->customer->name }}</td>
                            <td>{{ $payment->attendance->appointment->barber->name }}</td>
                            <td>{{ $methodLabels[$payment->method->value] }}</td>
                            <td>R$ {{ number_format((float) $payment->amount, 2, ',', '.') }}</td>
                            <td>R$ {{ number_format($refundedAmount, 2, ',', '.') }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center text-secondary py-5">
                                <i class="fas fa-file-invoice-dollar fa-2x mb-3 d-block" aria-hidden="true"></i>
                                Nenhum pagamento registrado.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <p class="form-text mt-3 mb-0">
        O recebimento e o estorno são registrados a partir do atendimento.
    </p>
</x-app-layout>
