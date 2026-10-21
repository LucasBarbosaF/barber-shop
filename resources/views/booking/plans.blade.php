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
                <h1>Planos de recorrência de {{ $tenant->name }}</h1>
                <p>Corte sempre no mesmo ritmo, com preço fechado.</p>
            </header>

            @if (session('booking_status'))
                <div class="alert alert-success booking-alert" role="status">
                    <i class="fas fa-circle-check me-1" aria-hidden="true"></i>
                    {{ session('booking_status') }}
                </div>
            @endif

            @if ($plans->isEmpty())
                <section class="booking-card booking-unavailable text-center">
                    <div class="booking-empty-icon" aria-hidden="true">
                        <i class="fas fa-calendar-xmark"></i>
                    </div>
                    <h2>Nenhum plano disponível agora</h2>
                    <p>Esta barbearia ainda não oferece planos de recorrência. Você pode agendar avulso.</p>
                    <a class="btn btn-primary" href="{{ route('booking.show', $tenant->slug) }}">
                        <i class="fas fa-calendar me-1" aria-hidden="true"></i> Agendar avulso
                    </a>
                </section>
            @else
                <div class="row g-3">
                    @foreach ($plans as $plan)
                        <div class="col-md-6 col-lg-4">
                            <div class="booking-card h-100 d-flex flex-column">
                                <span class="booking-eyebrow">RECORRÊNCIA</span>
                                <h2 class="h5 mt-1">{{ $plan->name }}</h2>
                                @if ($plan->description)
                                    <p class="text-secondary">{{ $plan->description }}</p>
                                @endif
                                <ul class="list-unstyled mb-3 flex-grow-1">
                                    <li class="mb-1">
                                        <i class="fas fa-check text-success me-1" aria-hidden="true"></i>
                                        {{ $plan->sessions_per_month }} corte(s) por mês
                                    </li>
                                    <li class="mb-1">
                                        <i class="fas fa-check text-success me-1" aria-hidden="true"></i>
                                        {{ $plan->duration_months }} meses de vigência
                                        ({{ $plan->total_sessions }} cortes no total)
                                    </li>
                                    <li class="mb-1">
                                        <i class="fas fa-check text-success me-1" aria-hidden="true"></i>
                                        Até {{ $plan->max_sessions_per_week }} cortes por semana
                                    </li>
                                    <li class="mb-1">
                                        <i class="fas fa-cut text-secondary me-1" aria-hidden="true"></i>
                                        {{ $plan->service?->name }}
                                    </li>
                                </ul>
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <strong class="fs-4">
                                            R$ {{ number_format((float) $plan->price, 2, ',', '.') }}
                                            <span class="fs-6 fw-normal">/mês</span>
                                        </strong>
                                        <div class="small text-secondary">
                                            Total R$ {{ number_format((float) $plan->price * $plan->duration_months, 2, ',', '.') }}
                                            em {{ $plan->duration_months }} meses
                                        </div>
                                    </div>
                                    <a href="{{ route('booking.plans.show', [$tenant->slug, $plan]) }}"
                                       class="btn btn-primary">
                                        Assinar
                                    </a>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif

            <footer class="booking-footer">
                Precisa de ajuda? Entre em contato com <strong>{{ $tenant->name }}</strong>.
                <a href="{{ route('booking.show', $tenant->slug) }}">Agendar avulso</a>
            </footer>
        </div>
    </main>
</x-booking-layout>
