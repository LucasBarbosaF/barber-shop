{{--
    Menu lateral. Os módulos ainda não implementados seguem como placeholders;
    os relatórios disponíveis são links protegidos pela permissão reports.view.

    Esconder item não é autorização: os itens reais seguem Gate/Policy no
    backend (`@can('users.view')` aqui é só espelho do que a rota já exige, e
    o link do painel do superadmin é do middleware `superadmin`).
--}}
<aside class="app-sidebar bg-body-secondary shadow" data-bs-theme="dark">
    <div class="sidebar-brand">
        <a href="{{ route('app.dashboard') }}" class="brand-link">
            @if (filled($currentBarbershop?->logo_path))
                <img src="{{ asset('storage/'.$currentBarbershop->logo_path) }}"
                     alt="" class="brand-image rounded-circle shadow" style="width: 2rem; height: 2rem; object-fit: cover;">
            @else
                <i class="fas fa-scissors brand-image" aria-hidden="true"></i>
            @endif
            <span class="brand-text fw-light">{{ filled($currentBarbershop?->name) ? $currentBarbershop->name : config('app.name', 'Barber SaaS') }}</span>
        </a>
    </div>

    <div class="sidebar-wrapper">
        <nav class="mt-2" aria-label="Menu principal">
            <ul class="nav sidebar-menu flex-column" data-lte-toggle="treeview" data-accordion="false" id="navigation">
                <li class="nav-item">
                    <a href="{{ route('app.dashboard') }}" class="nav-link @if (request()->routeIs('app.dashboard')) active @endif">
                        <i class="nav-icon fas fa-gauge-high" aria-hidden="true"></i>
                        <p>Dashboard</p>
                    </a>
                </li>

                <li class="nav-item">
                    <a href="#" class="nav-link" aria-expanded="false" aria-controls="menu-operacao">
                        <i class="nav-icon fas fa-calendar-days" aria-hidden="true"></i>
                        <p>Operação<i class="nav-arrow fas fa-angle-right" aria-hidden="true"></i></p>
                    </a>
                    <ul class="nav nav-treeview" id="menu-operacao">
                        @can('schedule.view')
                            <li class="nav-item">
                                <a href="{{ route('app.agenda.index') }}" class="nav-link @if (request()->routeIs('app.agenda.*')) active @endif">
                                    <i class="nav-icon fas fa-calendar-days" aria-hidden="true"></i>
                                    <p>Agenda</p>
                                </a>
                            </li>
                        @endcan

                    </ul>
                </li>

                <li class="nav-item">
                    <a href="#" class="nav-link" aria-expanded="false" aria-controls="menu-cadastros">
                        <i class="nav-icon fas fa-address-book" aria-hidden="true"></i>
                        <p>Cadastros<i class="nav-arrow fas fa-angle-right" aria-hidden="true"></i></p>
                    </a>
                    <ul class="nav nav-treeview" id="menu-cadastros">
                        @can('customers.view')
                            <li class="nav-item">
                                <a href="{{ route('app.customers.index') }}" class="nav-link @if (request()->routeIs('app.customers.*')) active @endif">
                                    <i class="nav-icon fas fa-users" aria-hidden="true"></i>
                                    <p>Clientes</p>
                                </a>
                            </li>
                        @endcan
                        @can('barbers.view')
                            <li class="nav-item">
                                <a href="{{ route('app.barbers.index') }}" class="nav-link @if (request()->routeIs('app.barbers.*')) active @endif">
                                    <i class="nav-icon fas fa-user-tie" aria-hidden="true"></i>
                                    <p>Barbeiros</p>
                                </a>
                            </li>
                        @endcan
                        @can('services.view')
                            <li class="nav-item">
                                <a href="{{ route('app.services.index') }}" class="nav-link @if (request()->routeIs('app.services.*')) active @endif">
                                    <i class="nav-icon fas fa-tags" aria-hidden="true"></i>
                                    <p>Serviços</p>
                                </a>
                            </li>
                        @endcan
                    </ul>
                </li>

                <li class="nav-item">
                    @can('commissions.view')
                    <a href="#" class="nav-link" aria-expanded="false" aria-controls="menu-financeiro">
                        <i class="nav-icon fas fa-money-bill-wave" aria-hidden="true"></i>
                        <p>Financeiro<i class="nav-arrow fas fa-angle-right" aria-hidden="true"></i></p>
                    </a>
                    @endcan
                    <ul class="nav nav-treeview" id="menu-financeiro">
                        @can('commissions.view')
                            <li class="nav-item">
                                <a href="{{ route('app.commissions.index') }}"
                                   class="nav-link @if (request()->routeIs('app.commissions.*')) active @endif">
                                    <i class="nav-icon fas fa-percent" aria-hidden="true"></i>
                                    <p>Comissões e repasses</p>
                                </a>
                            </li>
                        @endcan
                    </ul>
                </li>

                <li class="nav-item">
                    @can('reports.view')
                    <a href="#" class="nav-link" aria-expanded="false" aria-controls="menu-relatorios">
                        <i class="nav-icon fas fa-chart-column" aria-hidden="true"></i>
                        <p>Relatórios<i class="nav-arrow fas fa-angle-right" aria-hidden="true"></i></p>
                    </a>
                    @endcan
                    <ul class="nav nav-treeview" id="menu-relatorios">
                        @can('reports.view')
                            @foreach ([
                                'faturamento' => ['Faturamento', 'fa-chart-column'],
                                'clientes' => ['Clientes', 'fa-chart-pie'],
                                'barbeiros' => ['Barbeiros', 'fa-users'],
                                'comissoes' => ['Comissões', 'fa-percent'],
                            ] as $reportKey => [$reportLabel, $reportIcon])
                                <li class="nav-item">
                                    <a href="{{ route('app.reports.show', ['report' => $reportKey]) }}"
                                       class="nav-link @if (request()->routeIs('app.reports.*') && request()->route('report') === $reportKey) active @endif">
                                        <i class="nav-icon fas {{ $reportIcon }}" aria-hidden="true"></i>
                                        <p>{{ $reportLabel }}</p>
                                    </a>
                                </li>
                            @endforeach
                        @endcan
                    </ul>
                </li>

                <li class="nav-item">
                    <a href="#" class="nav-link" aria-expanded="false" aria-controls="menu-saas">
                        <i class="nav-icon fas fa-credit-card" aria-hidden="true"></i>
                        <p>SaaS<i class="nav-arrow fas fa-angle-right" aria-hidden="true"></i></p>
                    </a>
                    <ul class="nav nav-treeview" id="menu-saas">
                        <li class="nav-item">
                            <span class="nav-link" aria-disabled="true" style="pointer-events: none; opacity: .5;">
                                <i class="nav-icon fas fa-credit-card" aria-hidden="true"></i>
                                <p>Assinatura</p>
                            </span>
                        </li>
                    </ul>
                </li>

                @can('settings.manage')
                <li class="nav-item @if (request()->routeIs('app.settings.*')) menu-open @endif">
                    <a href="#" class="nav-link" aria-expanded="false" aria-controls="menu-configuracoes">
                        <i class="nav-icon fas fa-gear" aria-hidden="true"></i>
                        <p>Configurações<i class="nav-arrow fas fa-angle-right" aria-hidden="true"></i></p>
                    </a>
                    <ul class="nav nav-treeview" id="menu-configuracoes">
                        <li class="nav-item">
                            <a href="{{ route('app.settings.barbershop') }}" class="nav-link @if (request()->routeIs('app.settings.barbershop*')) active @endif">
                                <i class="nav-icon fas fa-store" aria-hidden="true"></i>
                                <p>Minha Barbearia</p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="{{ route('app.settings.permissions') }}" class="nav-link @if (request()->routeIs('app.settings.permissions')) active @endif">
                                <i class="nav-icon fas fa-key" aria-hidden="true"></i>
                                <p>Permissões</p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="{{ route('app.settings.hours') }}" class="nav-link @if (request()->routeIs('app.settings.hours')) active @endif">
                                <i class="nav-icon fas fa-clock" aria-hidden="true"></i>
                                <p>Horários</p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="{{ route('app.settings.payment-methods') }}" class="nav-link @if (request()->routeIs('app.settings.payment-methods*')) active @endif">
                                <i class="nav-icon fas fa-credit-card" aria-hidden="true"></i>
                                <p>Formas de pagamento</p>
                            </a>
                        </li>
                    </ul>
                </li>
                @endcan

                @can('users.view')
                    <li class="nav-item @if (request()->routeIs('app.team.*')) menu-open @endif">
                        <a href="#" class="nav-link @if (request()->routeIs('app.team.*')) active @endif"
                           aria-expanded="{{ request()->routeIs('app.team.*') ? 'true' : 'false' }}" aria-controls="menu-equipe">
                            <i class="nav-icon fas fa-users" aria-hidden="true"></i>
                            <p>Equipe<i class="nav-arrow fas fa-angle-right" aria-hidden="true"></i></p>
                        </a>
                        <ul class="nav nav-treeview" id="menu-equipe">
                            <li class="nav-item">
                                <a href="{{ route('app.team.index') }}" class="nav-link @if (request()->routeIs('app.team.*')) active @endif">
                                    <i class="nav-icon fas fa-users" aria-hidden="true"></i>
                                    <p>Equipe</p>
                                </a>
                            </li>
                        </ul>
                    </li>
                @endcan

                @if (auth()->user()?->isSuperadmin())
                    <li class="nav-item @if (request()->routeIs('admin.*')) menu-open @endif">
                        <a href="#" class="nav-link @if (request()->routeIs('admin.*')) active @endif"
                           aria-expanded="{{ request()->routeIs('admin.*') ? 'true' : 'false' }}" aria-controls="menu-plataforma">
                            <i class="nav-icon fas fa-layer-group" aria-hidden="true"></i>
                            <p>Plataforma<i class="nav-arrow fas fa-angle-right" aria-hidden="true"></i></p>
                        </a>
                        <ul class="nav nav-treeview" id="menu-plataforma">
                            <li class="nav-item">
                                <a href="{{ route('admin.barbershops.index') }}" class="nav-link @if (request()->routeIs('admin.*')) active @endif">
                                    <i class="nav-icon fas fa-store" aria-hidden="true"></i>
                                    <p>Barbearias</p>
                                </a>
                            </li>
                        </ul>
                    </li>
                @endif

                <li class="nav-item">
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="nav-link border-0 bg-transparent w-100 text-start" style="color: inherit;">
                            <i class="nav-icon fas fa-arrow-right-from-bracket" aria-hidden="true"></i>
                            <p>Sair</p>
                        </button>
                    </form>
                </li>
            </ul>
        </nav>
    </div>
</aside>
