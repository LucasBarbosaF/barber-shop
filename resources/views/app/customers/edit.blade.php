<x-app-layout title="Editar cliente">
    <div class="mb-4">
        <h1 class="h3 mb-0">Editar cliente</h1>
        <p class="text-secondary mb-0">Atualize os dados de {{ $customer->name }}.</p>
    </div>

    <div class="card">
        <div class="card-body">
            <form method="POST" action="{{ route('app.customers.update', $customer) }}">
                @csrf
                @method('PUT')
                @include('app.customers.form', ['customer' => $customer])
            </form>
        </div>
    </div>
</x-app-layout>
