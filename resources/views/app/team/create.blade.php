@php
    $isBarberRegistration = old('role', $initialRole?->value) === 'barber';
    $oldServiceIds = old('service_ids', []);
    $selectedServiceIds = is_array($oldServiceIds) ? array_map('strval', $oldServiceIds) : [];
@endphp

<x-app-layout :title="$isBarberRegistration ? 'Cadastrar barbeiro na equipe' : 'Adicionar à equipe'">
    <div class="mb-4">
        <h1 data-page-heading class="h3 mb-0">
            {{ $isBarberRegistration ? 'Cadastrar barbeiro na equipe' : 'Adicionar à equipe' }}
        </h1>
        <p data-page-description class="text-secondary mb-0">
            {{ $isBarberRegistration
                ? 'Em um único cadastro, crie o acesso e o perfil profissional do barbeiro.'
                : 'Crie o acesso para um novo membro desta barbearia.' }}
        </p>
    </div>

    <div class="card">
        <div class="card-body">
            <form method="POST" action="{{ route('app.team.store') }}">
                @csrf

                <div class="mb-3">
                    <h2 class="h5">Dados de acesso</h2>
                    <p class="form-text">Esses dados serão usados para entrar no sistema.</p>
                    <label for="name" class="form-label">Nome completo</label>
                    <input id="name" name="name" type="text" maxlength="255"
                           value="{{ old('name') }}"
                           class="form-control @error('name') is-invalid @enderror" required autofocus>
                    @error('name')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="mb-3">
                    <label for="email" class="form-label">E-mail de acesso</label>
                    <input id="email" name="email" type="email" maxlength="255"
                           value="{{ old('email') }}"
                           class="form-control @error('email') is-invalid @enderror" required>
                    @error('email')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="mb-3">
                    <label for="role" class="form-label">Função na equipe</label>
                    <select id="role" name="role" class="form-select @error('role') is-invalid @enderror" required>
                        <option value="">Selecione uma função</option>
                        @foreach ($roles as $value => $label)
                            <option value="{{ $value }}" @selected(old('role', $initialRole?->value) === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('role')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="mb-4" data-barber-phone @if (old('role', $initialRole?->value) !== 'barber') hidden @endif>
                    <h2 class="h5">Perfil profissional</h2>
                    <p class="form-text">
                        O perfil será criado e vinculado a esta conta automaticamente. Depois você poderá
                        configurar o expediente e a comissão. Marque apenas os serviços que esse profissional realiza.
                    </p>
                    <label for="phone" class="form-label">Telefone do barbeiro com DDD</label>
                    <input id="phone" name="phone" type="tel" inputmode="numeric" maxlength="15"
                           data-mask="phone" placeholder="(11) 99210-8613"
                           value="{{ old('phone') }}"
                           class="form-control @error('phone') is-invalid @enderror"
                           @if (old('role', $initialRole?->value) === 'barber') required @endif>
                    @error('phone')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror

                    <fieldset class="mt-3">
                        <legend class="form-label">Serviços realizados</legend>
                        @if ($services->isEmpty())
                            <div class="alert alert-warning">
                                Cadastre ao menos um serviço ativo antes de adicionar um barbeiro.
                                <a href="{{ route('app.services.create') }}">Cadastrar serviço</a>
                            </div>
                        @else
                            @foreach ($services as $service)
                                <div class="form-check">
                                    <input id="team_service_{{ $service->id }}" name="service_ids[]" type="checkbox"
                                           value="{{ $service->id }}"
                                           class="form-check-input @error('service_ids') is-invalid @enderror"
                                           @checked(in_array((string) $service->id, $selectedServiceIds, true))>
                                    <label for="team_service_{{ $service->id }}" class="form-check-label">
                                        {{ $service->name }}
                                        <span class="text-secondary">({{ $service->duration_minutes }} min)</span>
                                    </label>
                                </div>
                            @endforeach
                        @endif
                        @error('service_ids')
                            <div class="text-danger small mt-2">{{ $message }}</div>
                        @enderror
                        @error('service_ids.*')
                            <div class="text-danger small mt-2">{{ $message }}</div>
                        @enderror
                    </fieldset>

                    <div class="mt-4">
                        <label for="commission_percentage" class="form-label">
                            Comissão sobre serviços (%)
                        </label>
                        <div class="input-group">
                            <input id="commission_percentage" name="commission_percentage" type="number"
                                   min="0" max="100" step="0.01" inputmode="decimal"
                                   value="{{ old('commission_percentage') }}"
                                   class="form-control @error('commission_percentage') is-invalid @enderror"
                                   required>
                            <span class="input-group-text">%</span>
                            @error('commission_percentage')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                        <div class="form-text">Percentual aplicado aos novos atendimentos.</div>
                    </div>

                    <fieldset class="border-top pt-3 mt-4">
                        <legend class="h5">Expediente semanal</legend>
                        <p class="text-secondary">
                            Informe os dias e horários em que o barbeiro atende. É necessário configurar ao menos um dia.
                        </p>
                        @php
                            $weekdays = ['Domingo', 'Segunda-feira', 'Terça-feira', 'Quarta-feira', 'Quinta-feira', 'Sexta-feira', 'Sábado'];
                        @endphp
                        @foreach ($weekdays as $weekday => $label)
                            <div class="row align-items-center">
                                <div class="col-md-3 mb-2">
                                    <span class="form-label mb-0">{{ $label }}</span>
                                </div>
                                <div class="col-6 col-md-4 mb-2">
                                    <label for="team_business_hours_{{ $weekday }}_opens_at" class="visually-hidden">
                                        Abertura — {{ $label }}
                                    </label>
                                    <input id="team_business_hours_{{ $weekday }}_opens_at"
                                           name="business_hours[{{ $weekday }}][opens_at]" type="time"
                                           value="{{ old("business_hours.{$weekday}.opens_at") }}"
                                           class="form-control @error("business_hours.{$weekday}.opens_at") is-invalid @enderror">
                                    @error("business_hours.{$weekday}.opens_at")
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>
                                <div class="col-6 col-md-4 mb-2">
                                    <label for="team_business_hours_{{ $weekday }}_closes_at" class="visually-hidden">
                                        Fechamento — {{ $label }}
                                    </label>
                                    <input id="team_business_hours_{{ $weekday }}_closes_at"
                                           name="business_hours[{{ $weekday }}][closes_at]" type="time"
                                           value="{{ old("business_hours.{$weekday}.closes_at") }}"
                                           class="form-control @error("business_hours.{$weekday}.closes_at") is-invalid @enderror">
                                    @error("business_hours.{$weekday}.closes_at")
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>
                            </div>
                        @endforeach
                        @error('business_hours')
                            <div class="text-danger small mt-2">{{ $message }}</div>
                        @enderror
                    </fieldset>
                </div>

                <div class="alert alert-info">
                    Uma senha provisória será gerada e exibida uma única vez após o cadastro.
                    A pessoa deverá alterá-la ao entrar pela primeira vez.
                </div>

                <div class="border-top pt-3 d-flex gap-2">
                    <button type="submit" class="btn btn-primary">
                        {{ $isBarberRegistration ? 'Cadastrar barbeiro e criar acesso' : 'Criar acesso' }}
                    </button>
                    <a href="{{ route('app.team.index') }}" class="btn btn-outline-secondary">Cancelar</a>
                </div>
            </form>
        </div>
    </div>

    <script>
        const roleSelect = document.querySelector('#role');
        const barberPhone = document.querySelector('[data-barber-phone]');
        const phoneInput = barberPhone.querySelector('input[name="phone"]');
        const roleOption = roleSelect.querySelector('option[value="barber"]');
        const heading = document.querySelector('[data-page-heading]');
        const description = document.querySelector('[data-page-description]');
        const submitButton = document.querySelector('button[type="submit"]');
        const barberDescription = 'Em um único cadastro, crie o acesso e o perfil profissional do barbeiro.';
        const memberDescription = 'Crie o acesso para um novo membro desta barbearia.';

        roleSelect.addEventListener('change', () => {
            const isBarber = roleSelect.value === 'barber';
            barberPhone.hidden = !isBarber;
            phoneInput.required = isBarber;
            heading.textContent = isBarber ? 'Cadastrar barbeiro na equipe' : 'Adicionar à equipe';
            description.textContent = isBarber ? barberDescription : memberDescription;
            submitButton.textContent = isBarber ? 'Cadastrar barbeiro e criar acesso' : 'Criar acesso';
        });

        @if ($services->isEmpty())
            roleOption.disabled = true;
        @endif
    </script>
</x-app-layout>
