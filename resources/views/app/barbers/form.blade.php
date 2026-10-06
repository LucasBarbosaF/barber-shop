@php
    $oldServiceIds = old('service_ids', $selectedServiceIds ?? []);
    $selectedServiceIds = is_array($oldServiceIds) ? array_map('strval', $oldServiceIds) : [];
@endphp

<div class="mb-3">
    <label for="name" class="form-label">Nome</label>
    <input id="name" name="name" type="text" maxlength="150"
           value="{{ old('name', $barber?->name) }}"
           class="form-control @error('name') is-invalid @enderror" required autofocus>
    @error('name')
        <div class="invalid-feedback">{{ $message }}</div>
    @enderror
</div>

<div class="row">
    <div class="col-md-6 mb-3">
        <label for="phone" class="form-label">Telefone</label>
        <input id="phone" name="phone" type="tel" inputmode="numeric" maxlength="15"
               data-mask="phone" placeholder="(11) 99210-8613"
               value="{{ old('phone', $barber?->phone) }}"
               class="form-control @error('phone') is-invalid @enderror" required>
        @error('phone')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>

    <div class="col-md-6 mb-3">
        <label for="email" class="form-label">E-mail</label>
        <input id="email" name="email" type="email" maxlength="255"
               value="{{ old('email', $barber?->email) }}"
               class="form-control @error('email') is-invalid @enderror">
        @error('email')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>
</div>

<div class="mb-3">
    <label for="user_id" class="form-label">Conta de acesso do barbeiro</label>
    <select id="user_id" name="user_id" class="form-select @error('user_id') is-invalid @enderror">
        <option value="">Sem vínculo de acesso</option>
        @foreach ($barberUsers as $membership)
            <option value="{{ $membership->user_id }}"
                @selected((string) old('user_id', $barber?->user_id) === (string) $membership->user_id)>
                {{ $membership->user?->name }} — {{ $membership->user?->email }}
            </option>
        @endforeach
    </select>
    <div class="form-text">
        Vincule uma conta para o barbeiro receber avisos e ver a própria agenda.
        Perfis sem vínculo não aparecem para agendamentos públicos.
    </div>
    @error('user_id')
        <div class="invalid-feedback">{{ $message }}</div>
    @enderror
</div>

<fieldset class="border-top pt-3 mb-4">
    <legend class="h5">Serviços realizados</legend>
    <p class="text-secondary">
        Na agenda pública, o cliente verá apenas estes serviços depois de selecionar este barbeiro.
    </p>
    <input type="hidden" name="services_present" value="1">
    @forelse ($services as $service)
        <div class="form-check">
            <input id="barber_service_{{ $service->id }}" name="service_ids[]" type="checkbox"
                   value="{{ $service->id }}"
                   class="form-check-input @error('service_ids') is-invalid @enderror"
                   @checked(in_array((string) $service->id, $selectedServiceIds, true))>
            <label for="barber_service_{{ $service->id }}" class="form-check-label">
                {{ $service->name }}
                <span class="text-secondary">({{ $service->duration_minutes }} min)</span>
            </label>
        </div>
    @empty
        <div class="alert alert-warning">
            Não há serviços ativos. Cadastre um serviço antes de salvar o perfil do barbeiro.
        </div>
    @endforelse
    @error('service_ids')
        <div class="text-danger small mt-2">{{ $message }}</div>
    @enderror
    @error('service_ids.*')
        <div class="text-danger small mt-2">{{ $message }}</div>
    @enderror
</fieldset>

<div class="mb-3">
    <label for="bio" class="form-label">Apresentação</label>
    <textarea id="bio" name="bio" rows="4" maxlength="10000"
              class="form-control @error('bio') is-invalid @enderror">{{ old('bio', $barber?->bio) }}</textarea>
    @error('bio')
        <div class="invalid-feedback">{{ $message }}</div>
    @enderror
</div>

<div class="mb-4">
    <label for="commission_percentage" class="form-label">Comissão sobre serviços (%)</label>
    <input id="commission_percentage" name="commission_percentage" type="number" min="0" max="100"
           step="0.01" inputmode="decimal"
           value="{{ old('commission_percentage', $barber?->commission_percentage) }}"
           class="form-control @error('commission_percentage') is-invalid @enderror">
    <div class="form-text">Esse percentual será copiado para os itens de novos atendimentos.</div>
    @error('commission_percentage')
        <div class="invalid-feedback">{{ $message }}</div>
    @enderror
</div>

<fieldset class="border-top pt-3 mb-4">
    <legend class="h5">Expediente semanal</legend>
    <p class="text-secondary">Defina um período contínuo por dia. Deixe em branco nos dias sem atendimento.</p>
    @php
        $weekdays = ['Domingo', 'Segunda-feira', 'Terça-feira', 'Quarta-feira', 'Quinta-feira', 'Sexta-feira', 'Sábado'];
    @endphp
    @foreach ($weekdays as $weekday => $label)
        @php($period = $businessHours->get($weekday))
        <div class="row align-items-center">
            <div class="col-md-3 mb-2">
                <span class="form-label mb-0">{{ $label }}</span>
            </div>
            <div class="col-6 col-md-4 mb-2">
                <label for="business_hours_{{ $weekday }}_opens_at" class="visually-hidden">Abertura — {{ $label }}</label>
                <input id="business_hours_{{ $weekday }}_opens_at" name="business_hours[{{ $weekday }}][opens_at]"
                       type="time" value="{{ old("business_hours.{$weekday}.opens_at", $period?->opens_at ? substr($period->opens_at, 0, 5) : '') }}"
                       class="form-control @error("business_hours.{$weekday}.opens_at") is-invalid @enderror">
                @error("business_hours.{$weekday}.opens_at")
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>
            <div class="col-6 col-md-4 mb-2">
                <label for="business_hours_{{ $weekday }}_closes_at" class="visually-hidden">Fechamento — {{ $label }}</label>
                <input id="business_hours_{{ $weekday }}_closes_at" name="business_hours[{{ $weekday }}][closes_at]"
                       type="time" value="{{ old("business_hours.{$weekday}.closes_at", $period?->closes_at ? substr($period->closes_at, 0, 5) : '') }}"
                       class="form-control @error("business_hours.{$weekday}.closes_at") is-invalid @enderror">
                @error("business_hours.{$weekday}.closes_at")
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>
        </div>
    @endforeach
</fieldset>

<div class="form-check mb-4">
    <input type="hidden" name="is_active" value="0">
    <input id="is_active" name="is_active" type="checkbox" value="1"
           class="form-check-input @error('is_active') is-invalid @enderror"
           @checked(old('is_active', $barber?->is_active ?? true))>
    <label for="is_active" class="form-check-label">Barbeiro ativo</label>
    @error('is_active')
        <div class="invalid-feedback">{{ $message }}</div>
    @enderror
</div>

<div class="border-top pt-3 d-flex flex-wrap gap-2">
    <button type="submit" class="btn btn-primary">
        {{ $barber ? 'Salvar alterações' : 'Cadastrar barbeiro' }}
    </button>
    <a href="{{ route('app.barbers.index') }}" class="btn btn-outline-secondary">Cancelar</a>
</div>
