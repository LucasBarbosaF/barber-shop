<div class="mb-3">
    <label for="name" class="form-label">Nome</label>
    <input id="name" name="name" type="text" maxlength="150"
           value="{{ old('name', $service?->name) }}"
           class="form-control @error('name') is-invalid @enderror" required autofocus>
    @error('name')
        <div class="invalid-feedback">{{ $message }}</div>
    @enderror
</div>

<div class="mb-3">
    <label for="description" class="form-label">Descrição</label>
    <textarea id="description" name="description" rows="3"
              class="form-control @error('description') is-invalid @enderror">{{ old('description', $service?->description) }}</textarea>
    @error('description')
        <div class="invalid-feedback">{{ $message }}</div>
    @enderror
</div>

<div class="row">
    <div class="col-md-6 mb-3">
        <label for="duration_minutes" class="form-label">Duração (minutos)</label>
        <input id="duration_minutes" name="duration_minutes" type="number" min="1" max="1440"
               value="{{ old('duration_minutes', $service?->duration_minutes) }}"
               class="form-control @error('duration_minutes') is-invalid @enderror" required>
        @error('duration_minutes')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>

    <div class="col-md-6 mb-3">
        <label for="price" class="form-label">Preço (R$)</label>
        <input id="price" name="price" type="text" inputmode="decimal" maxlength="15"
               data-mask="currency" placeholder="0,00"
               value="{{ old('price', $service?->price) }}"
               class="form-control @error('price') is-invalid @enderror" required>
        @error('price')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>
</div>

<div class="form-check mb-4">
    <input type="hidden" name="is_active" value="0">
    <input id="is_active" name="is_active" type="checkbox" value="1"
           class="form-check-input @error('is_active') is-invalid @enderror"
           @checked(old('is_active', $service?->is_active ?? true))>
    <label for="is_active" class="form-check-label">Serviço ativo</label>
    @error('is_active')
        <div class="invalid-feedback">{{ $message }}</div>
    @enderror
</div>

<div class="border-top pt-3 d-flex flex-wrap gap-2">
    <button type="submit" class="btn btn-primary">
        {{ $service ? 'Salvar alterações' : 'Cadastrar serviço' }}
    </button>
    <a href="{{ route('app.services.index') }}" class="btn btn-outline-secondary">Cancelar</a>
</div>
