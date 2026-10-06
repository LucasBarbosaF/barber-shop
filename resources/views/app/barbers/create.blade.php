<x-app-layout title="Novo barbeiro">
    <div class="mb-4">
        <h1 class="h3 mb-0">Novo barbeiro</h1>
        <p class="text-secondary mb-0">Informe os dados do profissional.</p>
    </div>

    <div class="card">
        <div class="card-body">
            <form method="POST" action="{{ route('app.barbers.store') }}">
                @csrf
                @include('app.barbers.form', ['barber' => null])
            </form>
        </div>
    </div>
</x-app-layout>
