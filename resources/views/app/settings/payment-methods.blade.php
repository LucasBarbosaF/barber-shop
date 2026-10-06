<x-app-layout title="Formas de pagamento">
    <div class="mb-4">
        <h1 class="h3 mb-1">Formas de pagamento</h1>
        <p class="text-secondary mb-0">Escolha quais formas podem ser usadas para registrar recebimentos.</p>
    </div>
    @include('app.settings._navigation')

    <form method="POST" action="{{ route('app.settings.payment-methods.update') }}" class="card">
        @csrf
        @method('PUT')
        <div class="card-header"><h2 class="h5 mb-0">Formas disponíveis</h2></div>
        <div class="card-body">
            <p class="text-secondary">Mantenha ao menos uma forma ativa. Essa configuração é aplicada aos novos recebimentos; registros anteriores não são alterados.</p>
            @php($selectedMethods = old('payment_methods', $enabledMethods))
            @foreach ($methods as $method)
                @php($label = match ($method->value) { 'cash' => 'Dinheiro', 'pix' => 'Pix', 'card' => 'Cartão' })
                <div class="form-check mb-3">
                    <input id="payment_method_{{ $method->value }}" name="payment_methods[]" type="checkbox"
                           value="{{ $method->value }}" class="form-check-input"
                           @checked(in_array($method->value, $selectedMethods, true))>
                    <label for="payment_method_{{ $method->value }}" class="form-check-label">{{ $label }}</label>
                </div>
            @endforeach
            @error('payment_methods')
                <div class="text-danger small">{{ $message }}</div>
            @enderror
            @error('payment_methods.*')
                <div class="text-danger small">{{ $message }}</div>
            @enderror
        </div>
        <div class="card-footer text-end">
            <button class="btn btn-primary" type="submit">
                <i class="fas fa-floppy-disk me-1" aria-hidden="true"></i>
                Salvar formas de pagamento
            </button>
        </div>
    </form>
</x-app-layout>
