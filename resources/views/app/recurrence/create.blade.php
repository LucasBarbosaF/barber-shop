<x-app-layout title="Novo plano de recorrência">
    <div class="mb-4">
        <h1 class="h3 mb-0">Novo plano de recorrência</h1>
        <p class="text-secondary mb-0">Defina quantos cortes o cliente tem por mês, por quanto tempo e a que preço.</p>
    </div>

    <div class="card">
        <div class="card-body">
            <form method="POST" action="{{ route('app.recurrence.store') }}">
                @csrf
                @include('app.recurrence.form', ['plan' => null])
            </form>
        </div>
    </div>
</x-app-layout>
