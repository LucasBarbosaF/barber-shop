{{--
    Cabeçalho de navegação da agenda, no modelo do Google Calendar: setas,
    "Hoje", o título do período e o seletor de visão. Todas as URLs vêm do
    controller já com os filtros ativos, então a barra funciona sem JavaScript —
    o JS só desenha o calendário abaixo dela.
--}}
<div class="card agenda-toolbar mb-3">
    <div class="card-body agenda-toolbar-inner">
        <div class="agenda-nav">
            <a href="{{ $nav['prev'] }}" class="btn btn-sm btn-outline-secondary agenda-nav-btn"
               aria-label="Período anterior">
                <i class="fas fa-chevron-left" aria-hidden="true"></i>
            </a>
            <a href="{{ $nav['today'] }}" class="btn btn-sm btn-outline-secondary">Hoje</a>
            <a href="{{ $nav['next'] }}" class="btn btn-sm btn-outline-secondary agenda-nav-btn"
               aria-label="Próximo período">
                <i class="fas fa-chevron-right" aria-hidden="true"></i>
            </a>
            <h2 class="agenda-toolbar-title mb-0">{{ $title }}</h2>
        </div>

        <div class="agenda-views" role="group" aria-label="Visão da agenda">
            @foreach (['day' => 'Dia', 'week' => 'Semana', 'month' => 'Mês', 'list' => 'Lista'] as $viewKey => $viewLabel)
                <a href="{{ $nav['views'][$viewKey] }}"
                   class="agenda-view-btn @if ($view === $viewKey) is-active @endif"
                   @if ($view === $viewKey) aria-current="page" @endif>{{ $viewLabel }}</a>
            @endforeach
        </div>
    </div>
</div>
