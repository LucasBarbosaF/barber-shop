<x-app-layout title="Editar serviço">
    <div class="mb-4">
        <h1 class="h3 mb-0">Editar serviço</h1>
        <p class="text-secondary mb-0">Atualize os dados de {{ $service->name }}.</p>
    </div>

    <div class="card">
        <div class="card-body">
            <form method="POST" action="{{ route('app.services.update', $service) }}">
                @csrf
                @method('PUT')
                @include('app.services.form', ['service' => $service])
            </form>
        </div>
    </div>
</x-app-layout>
