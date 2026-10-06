<footer class="app-footer">
    <div class="float-end d-none d-sm-inline">SaaS multi-tenant</div>
    <strong>
        Copyright &copy; {{ date('Y') }}
        <a href="{{ route('home') }}" class="text-decoration-none">
            {{ filled($currentBarbershop?->name) ? $currentBarbershop->name : config('app.name', 'Barber SaaS') }}
        </a>.
    </strong>
    Todos os direitos reservados.
</footer>
