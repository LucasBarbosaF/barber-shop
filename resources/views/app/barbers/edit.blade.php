<x-app-layout title="Editar barbeiro">
    <div class="mb-4">
        <h1 class="h3 mb-0">Editar barbeiro</h1>
        <p class="text-secondary mb-0">Atualize os dados de {{ $barber->name }}.</p>
    </div>

    <div class="card">
        <div class="card-body">
            <form method="POST" action="{{ route('app.barbers.update', $barber) }}">
                @csrf
                @method('PUT')
                @include('app.barbers.form', ['barber' => $barber])
            </form>
        </div>
    </div>
</x-app-layout>
