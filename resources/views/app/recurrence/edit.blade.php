<x-app-layout title="Editar plano de recorrência">
    <div class="mb-4">
        <h1 class="h3 mb-0">Editar plano de recorrência</h1>
        <p class="text-secondary mb-0">
            Atualize os dados de {{ $plan->name }}. Assinaturas já criadas mantêm os valores originais.
        </p>
    </div>

    <div class="card">
        <div class="card-body">
            <form method="POST" action="{{ route('app.recurrence.update', $plan) }}">
                @csrf
                @method('PUT')
                @include('app.recurrence.form', ['plan' => $plan])
            </form>
        </div>
    </div>
</x-app-layout>
