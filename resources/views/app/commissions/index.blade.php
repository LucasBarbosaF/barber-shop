<x-app-layout title="Comissões">
    <div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-4">
        <div>
            <h1 class="h3 mb-1">Comissões e repasses</h1>
            <p class="text-secondary mb-0">Acompanhe os valores apurados e os repasses da equipe.</p>
        </div>
        @can('commissions.manage')
            <button class="btn btn-primary" type="button" data-bs-toggle="modal" data-bs-target="#closeCommissionPeriodModal">
                <i class="fas fa-calendar-check me-1" aria-hidden="true"></i>
                Fechar período
            </button>
        @endcan
    </div>

    <form method="GET" action="{{ route('app.commissions.index') }}" class="card mb-4">
        <div class="card-body">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                <h2 class="h6 mb-0">Filtrar resultados</h2>
                @if (array_filter($filters, fn ($value) => $value !== null && $value !== ''))
                    <a href="{{ route('app.commissions.index') }}" class="small">Limpar filtros</a>
                @endif
            </div>
            <div class="row g-3 align-items-end">
                <div class="col-sm-6 col-lg-4">
                    <label for="filter_period_id" class="form-label">Período</label>
                    <select id="filter_period_id" name="period_id" class="form-select">
                        <option value="">Todos os períodos</option>
                        @foreach ($periodOptions as $periodOption)
                            <option value="{{ $periodOption->id }}" @selected($filters['period_id'] === $periodOption->id)>
                                {{ $periodOption->period_start->format('d/m/Y') }} – {{ $periodOption->period_end->format('d/m/Y') }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-sm-6 col-lg-3">
                    <label for="filter_barber_id" class="form-label">Barbeiro</label>
                    <select id="filter_barber_id" name="barber_id" class="form-select">
                        <option value="">Toda a equipe</option>
                        @foreach ($barbers as $barber)
                            <option value="{{ $barber->id }}" @selected($filters['barber_id'] === $barber->id)>
                                {{ $barber->name }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-sm-6 col-lg-3">
                    <label for="filter_status" class="form-label">Situação do repasse</label>
                    <select id="filter_status" name="status" class="form-select">
                        <option value="">Todas</option>
                        <option value="pending" @selected($filters['status'] === 'pending')>Pendentes</option>
                        <option value="paid" @selected($filters['status'] === 'paid')>Repassadas</option>
                    </select>
                </div>
                <div class="col-sm-6 col-lg-2 d-grid">
                    <button class="btn btn-outline-primary" type="submit">
                        <i class="fas fa-filter me-1" aria-hidden="true"></i>
                        Aplicar
                    </button>
                </div>
            </div>
        </div>
    </form>

    <div class="row g-3 mb-4">
        <div class="col-sm-4">
            <div class="card h-100">
                <div class="card-body">
                    <div class="small text-secondary">Comissões na seleção</div>
                    <div class="fs-4 fw-semibold">{{ $summary->count ?? 0 }}</div>
                </div>
            </div>
        </div>
        <div class="col-sm-4">
            <div class="card h-100">
                <div class="card-body">
                    <div class="small text-secondary">Total apurado na seleção</div>
                    <div class="fs-4 fw-semibold">R$ {{ (new \App\Domain\Shared\ValueObjects\Money($summary->total ?? '0.00'))->format() }}</div>
                </div>
            </div>
        </div>
        <div class="col-sm-4">
            <div class="card h-100">
                <div class="card-body">
                    <div class="small text-secondary">Pendente de repasse</div>
                    <div class="fs-4 fw-semibold text-warning-emphasis">
                        R$ {{ (new \App\Domain\Shared\ValueObjects\Money($summary->pending ?? '0.00'))->format() }}
                    </div>
                </div>
            </div>
        </div>
    </div>

    @can('commissions.manage')
        <div class="modal fade" id="closeCommissionPeriodModal" tabindex="-1"
             aria-labelledby="closeCommissionPeriodModalLabel" aria-hidden="true"
             @if ($errors->has('period_start') || $errors->has('period_end')) data-auto-show-modal @endif>
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <form method="POST" action="{{ route('app.commissions.periods.close') }}">
                    @csrf
                        <div class="modal-header">
                            <div>
                                <h2 class="modal-title fs-5" id="closeCommissionPeriodModalLabel">Fechar período de comissão</h2>
                                <p class="small text-secondary mb-0 mt-1">
                                    Serão apurados os atendimentos encerrados dentro das datas escolhidas.
                                </p>
                            </div>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                        </div>
                        <div class="modal-body">
                            <div class="alert alert-info small" role="note">
                                Períodos sobrepostos a um fechamento anterior não podem ser apurados novamente.
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="period_start">Data inicial</label>
                                <input class="form-control @error('period_start') is-invalid @enderror" id="period_start"
                                       name="period_start" type="date" value="{{ old('period_start') }}" required>
                                @error('period_start')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                            <div>
                                <label class="form-label" for="period_end">Data final</label>
                                <input class="form-control @error('period_end') is-invalid @enderror" id="period_end"
                                       name="period_end" type="date" value="{{ old('period_end') }}" required>
                                @error('period_end')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                            <button class="btn btn-primary" type="submit">
                                <i class="fas fa-calculator me-1" aria-hidden="true"></i>
                                Apurar período
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endcan

    <section class="card mb-4" aria-labelledby="periods-heading">
        <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
                <h2 id="periods-heading" class="h5 mb-0">Períodos e repasses</h2>
                <div class="small text-secondary">Valores pendentes podem ser repassados individualmente por barbeiro.</div>
            </div>
            <span class="badge text-bg-light">{{ $periods->count() }} período(s)</span>
        </div>
        @forelse ($periods as $period)
            @php
                $pendingByBarber = $pendingByPeriod->get($period->getKey(), collect());
                $periodTotal = $period->commissions_sum_amount ?? '0.00';
            @endphp
            <div class="border-top p-3 p-lg-4">
                <div class="row align-items-center g-3 mb-3">
                    <div class="col-md">
                        <div class="fw-semibold">
                            {{ $period->period_start->format('d/m/Y') }} – {{ $period->period_end->format('d/m/Y') }}
                        </div>
                        <div class="small text-secondary">
                            {{ $period->commissions->count() }} comissão(ões)
                            <span class="mx-1">·</span>
                            Total do período: R$ {{ (new \App\Domain\Shared\ValueObjects\Money($periodTotal))->format() }}
                        </div>
                    </div>
                    <div class="col-md-auto">
                        @if ($period->status === null)
                            <span class="badge text-bg-warning">Atualize o banco</span>
                        @elseif ($period->status->value === 'paid')
                            <span class="badge text-bg-success">Repassado</span>
                        @else
                            <span class="badge text-bg-secondary">Fechado</span>
                        @endif
                    </div>
                </div>

                @can('commissions.manage')
                    @if ($filters['status'] !== 'paid' && $pendingByBarber->isNotEmpty())
                        <div class="d-flex flex-column gap-2">
                            @foreach ($pendingByBarber as $pending)
                                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 bg-body-tertiary rounded p-3">
                                    <div>
                                        <div class="fw-medium">{{ $pending->first()->barber_name_snapshot }}</div>
                                        <div class="small text-secondary">Pendente: R$ {{ (new \App\Domain\Shared\ValueObjects\Money($pending->first()->amount))->format() }}</div>
                                    </div>
                                    <form method="POST" action="{{ route('app.commissions.payments.store', $period) }}">
                                        @csrf
                                        <input type="hidden" name="barber_id" value="{{ $pending->first()->barber_id }}">
                                        <input type="hidden" name="idempotency_key" value="{{ \Illuminate\Support\Str::uuid() }}">
                                        <button class="btn btn-sm btn-success" type="submit">
                                            <i class="fas fa-hand-holding-usd me-1" aria-hidden="true"></i>
                                            Registrar repasse
                                        </button>
                                    </form>
                                </div>
                            @endforeach
                        </div>
                    @elseif ($filters['status'] === 'paid' || $pendingByBarber->isEmpty())
                        <div class="small text-secondary">
                            @if ($period->payments->isNotEmpty())
                                <i class="fas fa-check-circle text-success me-1" aria-hidden="true"></i>
                                Repasse concluído ou não há valores pendentes para os filtros atuais.
                            @else
                                Não há valores pendentes de repasse para os filtros atuais.
                            @endif
                        </div>
                    @endif
                @endcan

                @if ($period->payments->isNotEmpty())
                    <details class="mt-3">
                        <summary class="small text-secondary">Ver repasses registrados ({{ $period->payments->count() }})</summary>
                        <ul class="small mb-0 mt-2">
                            @foreach ($period->payments as $payment)
                                <li>{{ $payment->barber->name }} — R$ {{ (new \App\Domain\Shared\ValueObjects\Money($payment->amount))->format() }}</li>
                            @endforeach
                        </ul>
                    </details>
                @endif
            </div>
        @empty
            <div class="p-4 text-center text-secondary">
                Nenhum período corresponde aos filtros selecionados.
            </div>
        @endforelse
    </section>

    <section class="card" aria-labelledby="commissions-heading">
        <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
                <h2 id="commissions-heading" class="h5 mb-0">Comissões apuradas</h2>
                <div class="small text-secondary">Até 100 lançamentos mais recentes na seleção.</div>
            </div>
            <span class="badge text-bg-light">{{ $commissions->count() }} lançamento(s)</span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th scope="col">Período</th>
                        <th scope="col">Barbeiro</th>
                        <th scope="col">Serviço</th>
                        <th scope="col">Regra</th>
                        <th scope="col" class="text-end">Comissão</th>
                        <th scope="col">Repasse</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($commissions as $commission)
                        <tr>
                            <td class="text-nowrap">
                                {{ $commission->period->period_start->format('d/m/Y') }} – {{ $commission->period->period_end->format('d/m/Y') }}
                            </td>
                            <td>{{ $commission->barber_name_snapshot }}</td>
                            <td>{{ $commission->service_name_snapshot }}</td>
                            <td>
                                @if ($commission->calculation_type_snapshot->value === 'percentage')
                                    {{ $commission->percentage_snapshot }}%
                                @else
                                    R$ {{ (new \App\Domain\Shared\ValueObjects\Money($commission->fixed_amount_snapshot))->format() }} por unidade
                                @endif
                            </td>
                            <td class="text-end text-nowrap">R$ {{ (new \App\Domain\Shared\ValueObjects\Money($commission->amount))->format() }}</td>
                            <td>
                                @if ($commission->commission_payment_id === null)
                                    <span class="badge text-bg-warning">Pendente</span>
                                @else
                                    <span class="badge text-bg-success">Repassada</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center text-secondary py-4">
                                Nenhum lançamento corresponde aos filtros selecionados.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</x-app-layout>
