<div class="mb-3">
    <label for="name" class="form-label">Nome do plano</label>
    <input id="name" name="name" type="text" maxlength="150"
           value="{{ old('name', $plan?->name) }}" placeholder="Ex.: Clube do Corte"
           class="form-control @error('name') is-invalid @enderror" required autofocus>
    @error('name')
        <div class="invalid-feedback">{{ $message }}</div>
    @enderror
</div>

<div class="mb-3">
    <label for="description" class="form-label">Descrição</label>
    <textarea id="description" name="description" rows="3"
              placeholder="O que o cliente ganha com este plano?"
              class="form-control @error('description') is-invalid @enderror">{{ old('description', $plan?->description) }}</textarea>
    @error('description')
        <div class="invalid-feedback">{{ $message }}</div>
    @enderror
</div>

<div class="mb-3">
    <label for="service_id" class="form-label">Serviço incluído</label>
    <select id="service_id" name="service_id"
            class="form-select @error('service_id') is-invalid @enderror"
            data-plan-service required>
        <option value="">Selecione o serviço</option>
        @foreach ($services as $service)
            <option value="{{ $service->id }}"
                data-price="{{ $service->price }}"
                @selected((string) old('service_id', $plan?->service_id) === (string) $service->id)>
                {{ $service->name }} — R$ {{ number_format((float) $service->price, 2, ',', '.') }}
            </option>
        @endforeach
    </select>
    @error('service_id')
        <div class="invalid-feedback">{{ $message }}</div>
    @enderror
    <div class="form-text">Todos os cortes do plano usam este serviço.</div>
</div>

<div class="row g-3">
    <div class="col-md-4">
        <label for="duration_months" class="form-label">Duração (meses)</label>
        <input id="duration_months" name="duration_months" type="number" min="1" max="60"
               value="{{ old('duration_months', $plan?->duration_months ?? 6) }}"
               class="form-control @error('duration_months') is-invalid @enderror"
               data-plan-duration required>
        @error('duration_months')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>

    <div class="col-md-4">
        <label for="sessions_per_month" class="form-label">Cortes por mês</label>
        <input id="sessions_per_month" name="sessions_per_month" type="number" min="1" max="4"
               value="{{ old('sessions_per_month', $plan?->sessions_per_month ?? 2) }}"
               class="form-control @error('sessions_per_month') is-invalid @enderror"
               data-plan-sessions required>
        @error('sessions_per_month')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
        <div class="form-text">Sem acúmulo: o que não usar no mês perde.</div>
    </div>

    <div class="col-md-4">
        <label for="max_sessions_per_week" class="form-label">Máximo por semana</label>
        <select id="max_sessions_per_week" name="max_sessions_per_week"
                class="form-select @error('max_sessions_per_week') is-invalid @enderror" required>
            @foreach ([1, 2] as $weekLimit)
                <option value="{{ $weekLimit }}"
                    @selected((string) old('max_sessions_per_week', $plan?->max_sessions_per_week ?? 2) === (string) $weekLimit)>
                    {{ $weekLimit }} corte(s) por semana
                </option>
            @endforeach
        </select>
        @error('max_sessions_per_week')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>
</div>

<div class="mb-3 mt-3">
    <label for="price" class="form-label">Preço mensal (R$)</label>
    <input id="price" name="price" type="text" inputmode="decimal" maxlength="15"
           data-mask="currency" placeholder="0,00"
           value="{{ old('price', $plan?->price) }}"
           class="form-control @error('price') is-invalid @enderror" required>
    @error('price')
        <div class="invalid-feedback">{{ $message }}</div>
    @enderror
    <div class="form-text">
        Valor cobrado por mês de contrato — ex.: R$ 120,00 × 6 meses = R$ 720,00 no total.
        Os pagamentos são confirmados mês a mês na assinatura.
    </div>
    <div class="form-text" data-plan-summary></div>
</div>

<div class="form-check mb-4">
    <input type="hidden" name="is_active" value="0">
    <input id="is_active" name="is_active" type="checkbox" value="1"
           class="form-check-input @error('is_active') is-invalid @enderror"
           @checked(old('is_active', $plan?->is_active ?? true))>
    <label for="is_active" class="form-check-label">Plano ativo (visível no site e para venda)</label>
    @error('is_active')
        <div class="invalid-feedback">{{ $message }}</div>
    @enderror
</div>

<div class="border-top pt-3 d-flex flex-wrap gap-2">
    <button type="submit" class="btn btn-primary">
        {{ $plan ? 'Salvar alterações' : 'Cadastrar plano' }}
    </button>
    <a href="{{ route('app.recurrence.index') }}" class="btn btn-outline-secondary">Cancelar</a>
</div>

<script>
    (function () {
        const service = document.querySelector('[data-plan-service]');
        const duration = document.querySelector('[data-plan-duration]');
        const sessions = document.querySelector('[data-plan-sessions]');
        const summary = document.querySelector('[data-plan-summary]');
        if (!service || !duration || !sessions || !summary) {
            return;
        }

        const update = () => {
            const months = parseInt(duration.value, 10);
            const perMonth = parseInt(sessions.value, 10);
            if (!months || !perMonth) {
                return;
            }
            const total = months * perMonth;
            const base = `${total} corte(s) em ${months} mês(es).`;
            const price = service.selectedOptions[0]?.dataset?.price;
            summary.textContent = price
                ? `${base} Serviço avulso custaria R$ ${price} cada.`
                : base;
        };

        service.addEventListener('change', update);
        duration.addEventListener('change', update);
        sessions.addEventListener('change', update);
        update();
    })();
</script>
