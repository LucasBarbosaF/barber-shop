@php
    $subscriptionErrors = collect([
        'plan_id', 'customer_id', 'customer_name', 'customer_phone',
        'barber_id', 'starts_at', 'first_appointment_date', 'first_appointment_time',
    ])->contains(fn ($key) => $errors->has($key));
@endphp

<div class="modal fade" id="newSubscriptionModal" tabindex="-1"
     aria-labelledby="newSubscriptionModalLabel" aria-hidden="true"
     @if ($subscriptionErrors) data-auto-show-modal @endif>
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <form method="POST" action="{{ route('app.recurrence.subscriptions.store') }}">
                @csrf
                <div class="modal-header">
                    <div>
                        <h2 class="modal-title fs-5" id="newSubscriptionModalLabel">Nova assinatura</h2>
                        <p class="small text-secondary mb-0 mt-1">
                            A assinatura nasce aguardando pagamento e fica ativa após a confirmação.
                        </p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="subscription_plan_id" class="form-label">Plano</label>
                        <select id="subscription_plan_id" name="plan_id"
                                class="form-select @error('plan_id') is-invalid @enderror" required>
                            <option value="">Selecione o plano</option>
                            @foreach ($activePlans as $plan)
                                <option value="{{ $plan->id }}"
                                    data-sessions="{{ $plan->sessions_per_month }}"
                                    data-months="{{ $plan->duration_months }}"
                                    @selected((string) old('plan_id') === (string) $plan->id)>
                                        {{ $plan->name }} — R$ {{ number_format((float) $plan->price, 2, ',', '.') }}/mês
                                </option>
                            @endforeach
                        </select>
                        @error('plan_id')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                        <div class="form-text" data-plan-summary>
                            Selecione um plano para ver a quantidade de cortes.
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="subscription_customer_id" class="form-label">Cliente já cadastrado</label>
                        <select id="subscription_customer_id" name="customer_id" class="form-select"
                                data-customer-select>
                            <option value="">Novo cliente (informar nome e telefone abaixo)</option>
                            @foreach ($customers as $customer)
                                <option value="{{ $customer->id }}"
                                    data-name="{{ $customer->name }}"
                                    @selected((string) old('customer_id') === (string) $customer->id)>
                                    {{ $customer->name }} — {{ $customer->phone }}
                                </option>
                            @endforeach
                        </select>
                        <div class="form-text">
                            Para um cliente já cadastrado, selecione acima. Caso contrário, preencha os campos abaixo.
                        </div>
                    </div>

                    <div class="row g-3 mb-3" data-new-customer-fields
                         @if (! old('customer_id')) style="display: none;" @endif>
                        <div class="col-md-6">
                            <label for="subscription_customer_name" class="form-label">Nome do novo cliente</label>
                            <input id="subscription_customer_name" name="customer_name" type="text" maxlength="150"
                                   value="{{ old('customer_name') }}"
                                   class="form-control @error('customer_name') is-invalid @enderror">
                            @error('customer_name')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                        <div class="col-md-6">
                            <label for="subscription_customer_phone" class="form-label">Telefone com DDD</label>
                            <input id="subscription_customer_phone" name="customer_phone" type="tel" inputmode="numeric"
                                   maxlength="15" data-mask="phone" placeholder="(11) 99210-8613"
                                   value="{{ old('customer_phone') }}"
                                   class="form-control @error('customer_phone') is-invalid @enderror">
                            @error('customer_phone')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label for="subscription_barber_id" class="form-label">Barbeiro preferencial</label>
                            <select id="subscription_barber_id" name="barber_id"
                                    class="form-select @error('barber_id') is-invalid @enderror">
                                <option value="">Sem preferência</option>
                                @foreach ($barbers as $barber)
                                    <option value="{{ $barber->id }}"
                                        @selected((string) old('barber_id') === (string) $barber->id)>
                                        {{ $barber->name }}
                                    </option>
                                @endforeach
                            </select>
                            @error('barber_id')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                        <div class="col-md-6">
                            <label for="subscription_starts_at" class="form-label">Início da vigência</label>
                            <input id="subscription_starts_at" name="starts_at" type="date"
                                   value="{{ old('starts_at', now()->toDateString()) }}"
                                   class="form-control @error('starts_at') is-invalid @enderror" required>
                            @error('starts_at')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>

                    <div class="border rounded p-3">
                        <h3 class="h6">Primeiro corte (opcional)</h3>
                        <p class="small text-secondary">
                            Se o cliente já estiver com horário marcado, informe data e hora — o agendamento nasce
                            vinculado à assinatura.
                        </p>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="subscription_first_appointment_date" class="form-label">Data</label>
                                <input id="subscription_first_appointment_date" name="first_appointment_date"
                                       type="date" value="{{ old('first_appointment_date') }}"
                                       class="form-control @error('first_appointment_date') is-invalid @enderror">
                                @error('first_appointment_date')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="col-md-6">
                                <label for="subscription_first_appointment_time" class="form-label">Horário</label>
                                <input id="subscription_first_appointment_time" name="first_appointment_time"
                                       type="time" value="{{ old('first_appointment_time') }}"
                                       class="form-control @error('first_appointment_time') is-invalid @enderror">
                                @error('first_appointment_time')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button class="btn btn-primary" type="submit">
                        <i class="fas fa-check me-1" aria-hidden="true"></i>
                        Criar assinatura
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    (function () {
        const modal = document.getElementById('newSubscriptionModal');
        if (!modal) {
            return;
        }

        const planSelect = modal.querySelector('#subscription_plan_id');
        const planSummary = modal.querySelector('[data-plan-summary]');
        const customerSelect = modal.querySelector('[data-customer-select]');
        const newCustomerFields = modal.querySelector('[data-new-customer-fields]');

        const updatePlanSummary = () => {
            const option = planSelect.selectedOptions[0];
            const sessions = option?.dataset?.sessions;
            const months = option?.dataset?.months;
            planSummary.textContent = sessions && months
                ? `${parseInt(sessions, 10) * parseInt(months, 10)} cortes no total (${sessions} por mês durante ${months} meses).`
                : 'Selecione um plano para ver a quantidade de cortes.';
        };

        const toggleCustomerFields = () => {
            newCustomerFields.style.display = customerSelect.value ? 'none' : '';
        };

        planSelect.addEventListener('change', updatePlanSummary);
        customerSelect.addEventListener('change', toggleCustomerFields);

        updatePlanSummary();
        toggleCustomerFields();
    })();
</script>
