<div class="mb-3">
    <label for="name" class="form-label">Nome</label>
    <input id="name" name="name" type="text" maxlength="150"
           value="{{ old('name', $customer?->name) }}"
           class="form-control @error('name') is-invalid @enderror" required autofocus>
    @error('name')
        <div class="invalid-feedback">{{ $message }}</div>
    @enderror
</div>

<div class="mb-3">
    <label for="phone" class="form-label">Telefone</label>
    <input id="phone" name="phone" type="tel" inputmode="numeric" maxlength="15"
           data-mask="phone" placeholder="(11) 99210-8613"
           value="{{ old('phone', $customer?->phone) }}"
           class="form-control @error('phone') is-invalid @enderror" required>
    @error('phone')
        <div class="invalid-feedback">{{ $message }}</div>
    @enderror
</div>

<div class="mb-4">
    <label for="notes" class="form-label">Observações</label>
    <textarea id="notes" name="notes" rows="4" maxlength="10000"
              class="form-control @error('notes') is-invalid @enderror">{{ old('notes', $customer?->notes) }}</textarea>
    @error('notes')
        <div class="invalid-feedback">{{ $message }}</div>
    @enderror
</div>

<div class="border-top pt-3 d-flex flex-wrap gap-2">
    <button type="submit" class="btn btn-primary">
        {{ $customer ? 'Salvar alterações' : 'Cadastrar cliente' }}
    </button>
    <a href="{{ route('app.customers.index') }}" class="btn btn-outline-secondary">Cancelar</a>
</div>
