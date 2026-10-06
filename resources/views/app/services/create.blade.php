<x-app-layout title="Novo serviço">
    <div class="mb-4">
        <h1 class="h3 mb-0">Novo serviço</h1>
        <p class="text-secondary mb-0">Informe os dados do serviço.</p>
    </div>

    <div class="card">
        <div class="card-body">
            <form method="POST" action="{{ route('app.services.store') }}">
                @csrf
                @include('app.services.form', ['service' => null])
            </form>
        </div>
    </div>
</x-app-layout>
