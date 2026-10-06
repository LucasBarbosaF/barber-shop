<x-app-layout :title="$reportName">
    <div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-4">
        <div>
            <h1 class="h3 mb-1">{{ $reportName }}</h1>
            <p class="text-secondary mb-0">{{ $report['description'] }}</p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a class="btn btn-outline-success"
               href="{{ route('app.reports.export', ['report' => $reportKey, 'format' => 'csv', 'from' => $from, 'to' => $to]) }}">
                <i class="fas fa-file-csv me-1" aria-hidden="true"></i>
                Exportar CSV
            </a>
            <a class="btn btn-outline-danger"
               href="{{ route('app.reports.export', ['report' => $reportKey, 'format' => 'pdf', 'from' => $from, 'to' => $to]) }}">
                <i class="fas fa-file-pdf me-1" aria-hidden="true"></i>
                Exportar PDF
            </a>
        </div>
    </div>

    <form method="GET" action="{{ route('app.reports.show', ['report' => $reportKey]) }}" class="card mb-4">
        <div class="card-body">
            <div class="row g-3 align-items-end">
                <div class="col-sm-6 col-lg-4">
                    <label class="form-label" for="report_from">Data inicial</label>
                    <input class="form-control @error('from') is-invalid @enderror" id="report_from"
                           type="date" name="from" value="{{ old('from', $from) }}" required>
                    @error('from')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
                <div class="col-sm-6 col-lg-4">
                    <label class="form-label" for="report_to">Data final</label>
                    <input class="form-control @error('to') is-invalid @enderror" id="report_to"
                           type="date" name="to" value="{{ old('to', $to) }}" required>
                    @error('to')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
                <div class="col-lg-4 d-grid">
                    <button class="btn btn-primary" type="submit">
                        <i class="fas fa-filter me-1" aria-hidden="true"></i>
                        Aplicar período
                    </button>
                </div>
            </div>
        </div>
    </form>

    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <div class="small text-secondary">
            Dados de {{ \Illuminate\Support\Carbon::parse($from)->format('d/m/Y') }}
            até {{ \Illuminate\Support\Carbon::parse($to)->format('d/m/Y') }}
        </div>
        <span class="badge text-bg-light">{{ count($report['rows']) }} registro(s)</span>
    </div>

    <div class="row g-3 mb-4">
        @foreach ($report['summary'] as $label => $value)
            <div class="col-sm-6 col-xl-3">
                <div class="card h-100">
                    <div class="card-body">
                        <div class="small text-secondary">{{ $label }}</div>
                        <div class="fs-4 fw-semibold">{{ $value }}</div>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    <section class="card" aria-label="Dados do relatório">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        @foreach ($report['columns'] as $column)
                            <th scope="col">{{ $column }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @forelse ($report['rows'] as $row)
                        <tr>
                            @foreach ($row as $cell)
                                <td>{{ $cell }}</td>
                            @endforeach
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ count($report['columns']) }}" class="text-center text-secondary py-5">
                                Não há dados para o período selecionado.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-footer small text-secondary">
            Relatório gerado a partir dos registros da barbearia selecionada.
        </div>
    </section>
</x-app-layout>
