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
                <h1>{{ $tenant->name }}</h1>
                <p>Agende seu horário online em poucos passos.</p>
                @if ($tenant->phone || $tenant->email)
                    <div class="booking-contact">
                        @if ($tenant->phone)
                            <span><i class="fas fa-phone" aria-hidden="true"></i> {{ $tenant->phone }}</span>
                        @endif
                        @if ($tenant->phone && $tenant->email)
                            <span aria-hidden="true">·</span>
                        @endif
                        @if ($tenant->email)
                            <span><i class="fas fa-envelope" aria-hidden="true"></i> {{ $tenant->email }}</span>
                        @endif
                    </div>
                @endif
            </header>

            @if (session('booking_status'))
                <div class="alert alert-success booking-alert" role="status">
                    <i class="fas fa-circle-check me-1" aria-hidden="true"></i>
                    {{ session('booking_status') }}
                </div>
            @endif

            @if ($errors->any())
                <div class="alert alert-danger booking-alert" role="alert" tabindex="-1" data-booking-errors>
                    <strong>Não foi possível concluir o agendamento.</strong>
                    <span>Confira os campos destacados abaixo e tente novamente.</span>
                    <ul class="mb-0 mt-2">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @if ($barbers->isEmpty() || $services->isEmpty())
                <section class="booking-card booking-unavailable text-center">
                    <div class="booking-empty-icon" aria-hidden="true">
                        <i class="fas fa-calendar-xmark"></i>
                    </div>
                    <h2>Agendamento indisponível</h2>
                    <p>Ainda não há barbeiros com expediente e serviços ativos para receber agendamentos online.</p>
                    @if ($tenant->phone)
                        <a class="btn btn-primary" href="tel:{{ preg_replace('/\D+/', '', $tenant->phone) }}">
                            <i class="fas fa-phone me-1" aria-hidden="true"></i> Fale com a barbearia
                        </a>
                    @endif
                </section>
            @else
                <div class="booking-layout">
                    <section class="booking-card">
                        <div class="booking-card-heading">
                            <div>
                                <span class="booking-eyebrow">AGENDAMENTO ONLINE</span>
                                <h2>Escolha seu horário</h2>
                                <p>Preencha as etapas e confirme sua reserva.</p>
                            </div>
                            <span class="booking-secure-note">
                                <i class="fas fa-lock" aria-hidden="true"></i> Rápido e seguro
                            </span>
                        </div>

                        <ol class="booking-progress" aria-label="Etapas do agendamento">
                            <li class="is-current">
                                <span>1</span><span>Serviço</span>
                            </li>
                            <li>
                                <span>2</span><span>Horário</span>
                            </li>
                            <li>
                                <span>3</span><span>Seus dados</span>
                            </li>
                        </ol>

                        <form method="POST" action="{{ route('booking.store', $tenant->slug) }}"
                              data-booking-form data-availability-url="{{ route('booking.availability', $tenant->slug) }}">
                            @csrf

                            <section class="booking-section" aria-labelledby="booking-service-heading">
                                <div class="booking-section-title">
                                    <span class="booking-step-number">1</span>
                                    <div>
                                        <h3 id="booking-service-heading">Escolha o profissional e o serviço</h3>
                                        <p>Os serviços variam de acordo com o profissional.</p>
                                    </div>
                                </div>

                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label for="barber_id" class="form-label">Profissional</label>
                                        <select id="barber_id" name="barber_id"
                                                class="form-select @error('barber_id') is-invalid @enderror"
                                                data-booking-barber required>
                                            <option value="">Selecione o profissional</option>
                                            @foreach ($barbers as $barber)
                                                <option value="{{ $barber->id }}"
                                                    data-service-ids="{{ $barber->services->pluck('id')->implode(',') }}"
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
                                        <label for="service_id" class="form-label">Serviço</label>
                                        <select id="service_id" name="service_id"
                                                class="form-select @error('service_id') is-invalid @enderror"
                                                data-booking-service required disabled>
                                            <option value="">Selecione primeiro o profissional</option>
                                            @foreach ($services as $service)
                                                <option value="{{ $service->id }}"
                                                    data-name="{{ $service->name }}"
                                                    data-duration="{{ $service->duration_minutes }}"
                                                    data-price="{{ number_format((float) $service->price, 2, ',', '.') }}"
                                                    @selected((string) old('service_id') === (string) $service->id)>
                                                    {{ $service->name }} · {{ $service->duration_minutes }} min ·
                                                    R$ {{ number_format((float) $service->price, 2, ',', '.') }}
                                                </option>
                                            @endforeach
                                        </select>
                                        <div class="form-text" data-booking-service-help>
                                            Primeiro escolha um profissional.
                                        </div>
                                        @error('service_id')
                                            <div class="invalid-feedback">{{ $message }}</div>
                                        @enderror
                                    </div>
                                </div>
                            </section>

                            <section class="booking-section" aria-labelledby="booking-time-heading">
                                <div class="booking-section-title">
                                    <span class="booking-step-number">2</span>
                                    <div>
                                        <h3 id="booking-time-heading">Quando você quer vir?</h3>
                                        <p>Escolha uma data para consultar os horários disponíveis.</p>
                                    </div>
                                </div>

                                <div class="booking-date-field">
                                    <label for="appointment_date" class="form-label">Data do atendimento</label>
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
                                            <span>Escolha profissional, serviço e data para ver os horários.</span>
                                        </div>
                                    </div>
                                    <input type="hidden" name="time" data-booking-time value="{{ old('time') }}">
                                    @error('time')
                                        <div class="text-danger small mt-2">{{ $message }}</div>
                                    @enderror
                                </fieldset>
                            </section>

                            <section class="booking-section booking-customer-section" aria-labelledby="booking-customer-heading">
                                <div class="booking-section-title">
                                    <span class="booking-step-number">3</span>
                                    <div>
                                        <h3 id="booking-customer-heading">Seus dados</h3>
                                        <p>Precisamos destas informações para confirmar seu horário.</p>
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
                                        <div class="form-text">Usaremos seu telefone para localizar ou criar seu cadastro.</div>
                                        @error('customer_phone')
                                            <div class="invalid-feedback">{{ $message }}</div>
                                        @enderror
                                    </div>
                                </div>
                            </section>

                            <button type="submit" class="btn btn-primary btn-lg w-100 booking-submit">
                                <i class="fas fa-calendar-check me-2" aria-hidden="true"></i>
                                Confirmar agendamento
                            </button>
                            <p class="booking-privacy-note">
                                <i class="fas fa-shield-halved" aria-hidden="true"></i>
                                Seus dados são usados somente para organizar seu atendimento.
                            </p>
                        </form>
                    </section>

                    <aside class="booking-summary" aria-labelledby="booking-summary-heading">
                        <div class="booking-summary-top">
                            <span class="booking-summary-icon" aria-hidden="true">
                                <i class="fas fa-clipboard-check"></i>
                            </span>
                            <div>
                                <h2 id="booking-summary-heading">Resumo do agendamento</h2>
                                <p>Confira antes de confirmar</p>
                            </div>
                        </div>
                        <dl class="booking-summary-list">
                            <div>
                                <dt>Profissional</dt>
                                <dd data-summary-barber>Não selecionado</dd>
                            </div>
                            <div>
                                <dt>Serviço</dt>
                                <dd data-summary-service>Não selecionado</dd>
                            </div>
                            <div>
                                <dt>Data</dt>
                                <dd data-summary-date>Não selecionada</dd>
                            </div>
                            <div>
                                <dt>Horário</dt>
                                <dd data-summary-time>Aguardando seleção</dd>
                            </div>
                        </dl>
                        <div class="booking-summary-price">
                            <span>Valor do serviço</span>
                            <strong data-summary-price>—</strong>
                        </div>
                        <p class="booking-summary-note">
                            <i class="fas fa-circle-info" aria-hidden="true"></i>
                            O pagamento é feito diretamente na barbearia.
                        </p>
                    </aside>
                </div>
            @endif

            <footer class="booking-footer">
                Precisa de ajuda? Entre em contato com <strong>{{ $tenant->name }}</strong>.
            </footer>
        </div>
    </main>
</x-booking-layout>
