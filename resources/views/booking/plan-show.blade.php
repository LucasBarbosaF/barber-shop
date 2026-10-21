<x-booking-layout>
    <main class="booking-page">
        <div class="booking-container">
            <header class="booking-brand text-center">
                @if (filled($tenant->logo_path))
                    <img src="{{ asset('storage/'.$tenant->logo_path) }}" alt="Logo de {{ $tenant->name }}"
                         class="booking-logo">
                @else
                    <div class="booking-brand-icon" aria-hidden="true">
                        <i class="fas fa-scissors"></i>
                    </div>
                @endif
                <h1>Assinar {{ $plan->name }}</h1>
                <p>{{ $plan->sessions_per_month }} corte(s) por mês durante {{ $plan->duration_months }} meses.</p>
            </header>

            @if ($errors->any())
                <div class="alert alert-danger booking-alert" role="alert" tabindex="-1" data-booking-errors>
                    <strong>Não foi possível assinar o plano.</strong>
                    <span>Confira os campos destacados abaixo e tente novamente.</span>
                    <ul class="mb-0 mt-2">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @if ($barbers->isEmpty())
                <section class="booking-card booking-unavailable text-center">
                    <div class="booking-empty-icon" aria-hidden="true">
                        <i class="fas fa-calendar-xmark"></i>
                    </div>
                    <h2>Assinatura indisponível no momento</h2>
                    <p>Não há barbeiros com expediente para este serviço. Entre em contato com a barbearia.</p>
                </section>
            @else
                <div class="booking-layout">
                    <section class="booking-card">
                        <div class="booking-card-heading">
                            <div>
                                <span class="booking-eyebrow">RECORRÊNCIA</span>
                                <h2>Escolha seu barbeiro e o primeiro horário</h2>
                                <p>O pagamento é feito diretamente na barbearia.</p>
                            </div>
                        </div>

                        <form method="POST"
                              action="{{ route('booking.plans.subscribe', [$tenant->slug, $plan]) }}"
                              data-booking-form
                              data-booking-fixed-service="{{ $plan->service_id }}"
                              data-availability-url="{{ route('booking.availability', $tenant->slug) }}">
                            @csrf

                            <section class="booking-section" aria-labelledby="booking-barber-heading">
                                <div class="booking-section-title">
                                    <span class="booking-step-number">1</span>
                                    <div>
                                        <h3 id="booking-barber-heading">Profissional preferencial</h3>
                                        <p>Serviço do plano: {{ $plan->service?->name }}.</p>
                                    </div>
                                </div>

                                <label for="barber_id" class="form-label">Profissional</label>
                                <select id="barber_id" name="barber_id"
                                        class="form-select @error('barber_id') is-invalid @enderror"
                                        data-booking-barber required>
                                    <option value="">Selecione o profissional</option>
                                    @foreach ($barbers as $barber)
                                        <option value="{{ $barber->id }}"
                                            data-service-ids="{{ $plan->service_id }}"
                                            @selected((string) old('barber_id') === (string) $barber->id)>
                                            {{ $barber->name }}
                                        </option>
                                    @endforeach
                                </select>
                                @error('barber_id')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </section>

                            <section class="booking-section" aria-labelledby="booking-time-heading">
                                <div class="booking-section-title">
                                    <span class="booking-step-number">2</span>
                                    <div>
                                        <h3 id="booking-time-heading">Primeiro horário</h3>
                                        <p>Os próximos cortes você agenda pelo link da sua assinatura.</p>
                                    </div>
                                </div>

                                <div class="booking-date-field">
                                    <label for="appointment_date" class="form-label">Data do primeiro corte</label>
                                    <input id="appointment_date" type="date" name="date"
                                           min="{{ now(config('app.timezone'))->toDateString() }}"
                                           value="{{ old('date') }}"
                                           class="form-control @error('date') is-invalid @enderror"
                                           data-booking-date required>
                                    @error('date')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>

                                <fieldset class="booking-slots-field">
                                    <legend class="form-label">Horários disponíveis</legend>
                                    <p class="booking-slot-hint">Toque em um horário para selecioná-lo.</p>
                                    <div data-booking-slots class="booking-slots" aria-live="polite" tabindex="-1">
                                        <div class="booking-slot-placeholder">
                                            <i class="far fa-clock" aria-hidden="true"></i>
                                            <span>Escolha o profissional e a data para ver os horários.</span>
                                        </div>
                                    </div>
                                    <input type="hidden" name="time" data-booking-time value="{{ old('time') }}">
                                    @error('time')
                                        <div class="text-danger small mt-2">{{ $message }}</div>
                                    @enderror
                                </fieldset>
                            </section>

                            <section class="booking-section" aria-labelledby="booking-customer-heading">
                                <div class="booking-section-title">
                                    <span class="booking-step-number">3</span>
                                    <div>
                                        <h3 id="booking-customer-heading">Seus dados</h3>
                                        <p>Usamos seu telefone para localizar seu cadastro.</p>
                                    </div>
                                </div>

                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label for="customer_name" class="form-label">Nome completo</label>
                                        <input id="customer_name" name="customer_name" type="text" maxlength="150"
                                               autocomplete="name" placeholder="Como podemos chamar você?"
                                               value="{{ old('customer_name') }}"
                                               class="form-control @error('customer_name') is-invalid @enderror" required>
                                        @error('customer_name')
                                            <div class="invalid-feedback">{{ $message }}</div>
                                        @enderror
                                    </div>
                                    <div class="col-md-6">
                                        <label for="customer_phone" class="form-label">Celular com DDD</label>
                                        <input id="customer_phone" name="customer_phone" type="tel"
                                               autocomplete="tel" inputmode="numeric" maxlength="15"
                                               data-mask="phone" placeholder="(11) 99210-8613"
                                               value="{{ old('customer_phone') }}"
                                               class="form-control @error('customer_phone') is-invalid @enderror" required>
                                        <div class="form-text">Enviaremos o link da sua assinatura para este número.</div>
                                        @error('customer_phone')
                                            <div class="invalid-feedback">{{ $message }}</div>
                                        @enderror
                                    </div>
                                </div>
                            </section>

                            <button type="submit" class="btn btn-primary btn-lg w-100 booking-submit">
                                <i class="fas fa-rotate me-2" aria-hidden="true"></i>
                                Confirmar assinatura
                            </button>
                            <p class="booking-privacy-note">
                                <i class="fas fa-shield-halved" aria-hidden="true"></i>
                                Seus dados são usados somente para organizar seus cortes.
                            </p>
                        </form>
                    </section>

                    <aside class="booking-summary" aria-labelledby="booking-summary-heading">
                        <div class="booking-summary-top">
                            <span class="booking-summary-icon" aria-hidden="true">
                                <i class="fas fa-rotate"></i>
                            </span>
                            <div>
                                <h2 id="booking-summary-heading">Resumo do plano</h2>
                                <p>{{ $plan->name }}</p>
                            </div>
                        </div>
                        <dl class="booking-summary-list">
                            <div>
                                <dt>Serviço</dt>
                                <dd>{{ $plan->service?->name }}</dd>
                            </div>
                            <div>
                                <dt>Vigência</dt>
                                <dd>{{ $plan->duration_months }} meses</dd>
                            </div>
                            <div>
                                <dt>Cortes</dt>
                                <dd>{{ $plan->sessions_per_month }} por mês ({{ $plan->total_sessions }} no total)</dd>
                            </div>
                            <div>
                                <dt>Primeiro horário</dt>
                                <dd data-summary-date>Não selecionado</dd>
                            </div>
                            <div>
                                <dt>Horário</dt>
                                <dd data-summary-time>Aguardando seleção</dd>
                            </div>
                        </dl>
                        <div class="booking-summary-price">
                            <span>Valor mensal</span>
                            <strong>
                                R$ {{ number_format((float) $plan->price, 2, ',', '.') }}
                                <span class="fs-6 fw-normal">/mês</span>
                            </strong>
                        </div>
                        <p class="booking-summary-note">
                            <i class="fas fa-circle-info" aria-hidden="true"></i>
                            Total de R$ {{ number_format((float) $plan->price * $plan->duration_months, 2, ',', '.') }}
                            em {{ $plan->duration_months }} meses. O pagamento é feito diretamente na barbearia,
                            mês a mês — seu plano fica ativo após a confirmação do primeiro pagamento.
                        </p>
                    </aside>
                </div>
            @endif

            <footer class="booking-footer">
                <a href="{{ route('booking.plans', $tenant->slug) }}">Voltar para os planos</a>
            </footer>
        </div>
    </main>
</x-booking-layout>
