<x-app-layout title="Horários">
    <div class="mb-4">
        <h1 class="h3 mb-1">Horários de atendimento</h1>
        <p class="text-secondary mb-0">O expediente é definido individualmente para cada barbeiro e controla os horários disponíveis para agendamento.</p>
    </div>
    @include('app.settings._navigation')

    <div class="card">
        <div class="card-header"><h2 class="h5 mb-0">Expediente por barbeiro</h2></div>
        <div class="list-group list-group-flush">
            @forelse ($barbers as $barber)
                @php($hoursByWeekday = $barber->businessHours->keyBy('weekday'))
                <section class="list-group-item p-3 p-lg-4">
                    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
                        <div>
                            <h3 class="h6 mb-1">{{ $barber->name }}</h3>
                            <div class="small text-secondary">Expediente usado para calcular disponibilidade.</div>
                        </div>
                        @can('update', $barber)
                            <a href="{{ route('app.barbers.edit', $barber) }}" class="btn btn-sm btn-outline-primary">
                                <i class="fas fa-pen me-1" aria-hidden="true"></i>
                                Editar horários
                            </a>
                        @endcan
                    </div>
                    <div class="row g-2">
                        @foreach ($weekdays as $weekday => $label)
                            @php($businessHour = $hoursByWeekday->get($weekday))
                            <div class="col-sm-6 col-lg-4">
                                <div class="d-flex justify-content-between border rounded p-2">
                                    <span>{{ $label }}</span>
                                    @if ($businessHour)
                                        <span class="text-nowrap">{{ substr($businessHour->opens_at, 0, 5) }}–{{ substr($businessHour->closes_at, 0, 5) }}</span>
                                    @else
                                        <span class="text-secondary">Fechado</span>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                </section>
            @empty
                <div class="list-group-item text-center text-secondary py-5">
                    Cadastre um barbeiro para definir o expediente de atendimento.
                </div>
            @endforelse
        </div>
    </div>
</x-app-layout>
