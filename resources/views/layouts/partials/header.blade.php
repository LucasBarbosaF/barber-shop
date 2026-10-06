{{--
    Topbar do AdminLTE. O toggle usa `data-lte-toggle="sidebar"`, que é o
    seletor do pushmenu do AdminLTE 4 — não é `data-widget`, que era do 3.
--}}
<nav class="app-header navbar navbar-expand bg-body">
    <div class="container-fluid">
        <ul class="navbar-nav">
            <li class="nav-item">
                <a class="nav-link" data-lte-toggle="sidebar" href="#" role="button" aria-label="Alternar menu lateral">
                    <i class="fas fa-bars" aria-hidden="true"></i>
                </a>
            </li>
            <li class="nav-item d-none d-md-block">
                <a href="{{ route('app.dashboard') }}" class="nav-link @if (request()->routeIs('app.dashboard')) active @endif">
                    <i class="fas fa-gauge-high me-1" aria-hidden="true"></i>
                    Dashboard
                </a>
            </li>
        </ul>

        <ul class="navbar-nav ms-auto">
            @auth
                @can('schedule.view')
                    <li class="nav-item dropdown" data-appointment-notifications
                        data-url="{{ route('app.appointment-notifications.index') }}"
                        data-read-url="{{ route('app.appointment-notifications.read', ['notification' => '__ID__']) }}">
                        <a href="#" class="nav-link" data-bs-toggle="dropdown" role="button"
                           aria-label="Notificações de agendamento">
                            <i class="fas fa-bell" aria-hidden="true"></i>
                            <span class="badge rounded-pill text-bg-danger" data-notification-count hidden>0</span>
                        </a>
                        <div class="dropdown-menu dropdown-menu-lg dropdown-menu-end">
                            <span class="dropdown-item-text fw-semibold">Novos agendamentos</span>
                            <div data-notification-list>
                                <span class="dropdown-item-text text-secondary">Carregando...</span>
                            </div>
                        </div>
                    </li>
                @endcan
                <li class="nav-item dropdown user-menu">
                    <a href="#" class="nav-link dropdown-toggle" data-bs-toggle="dropdown" role="button">
                        <i class="fas fa-user-circle me-1" aria-hidden="true"></i>
                        <span class="d-none d-md-inline">{{ auth()->user()->name }}</span>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-lg dropdown-menu-end">
                        <li class="user-header text-bg-primary">
                            <i class="fas fa-user fa-2x" aria-hidden="true"></i>
                            <p>
                                {{ auth()->user()->name }}
                                <small>{{ auth()->user()->email }}</small>
                            </p>
                        </li>
                        <li class="user-footer">
                            <form method="POST" action="{{ route('logout') }}">
                                @csrf
                                <button type="submit" class="btn btn-outline-danger float-end">Sair</button>
                            </form>
                        </li>
                    </ul>
                </li>
            @else
                <li class="nav-item">
                    <a href="{{ route('login') }}" class="nav-link">Entrar</a>
                </li>
            @endauth
        </ul>
    </div>
</nav>
