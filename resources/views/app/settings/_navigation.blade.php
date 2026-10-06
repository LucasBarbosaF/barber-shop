<nav class="nav nav-pills flex-wrap gap-2 mb-4" aria-label="Seções de configuração">
    <a href="{{ route('app.settings.barbershop') }}"
       class="nav-link @if (request()->routeIs('app.settings.barbershop*')) active @endif">Minha Barbearia</a>
    <a href="{{ route('app.settings.permissions') }}"
       class="nav-link @if (request()->routeIs('app.settings.permissions')) active @endif">Permissões</a>
    <a href="{{ route('app.settings.hours') }}"
       class="nav-link @if (request()->routeIs('app.settings.hours')) active @endif">Horários</a>
    <a href="{{ route('app.settings.payment-methods') }}"
       class="nav-link @if (request()->routeIs('app.settings.payment-methods*')) active @endif">Formas de pagamento</a>
</nav>
