<x-app-layout title="Novo cliente">
    <div class="mb-4">
        <h1 class="h3 mb-0">Novo cliente</h1>
        <p class="text-secondary mb-0">Informe os dados do cliente.</p>
    </div>

    <div class="card">
        <div class="card-body">
            <form method="POST" action="{{ route('app.customers.store') }}">
                @csrf
                @include('app.customers.form', ['customer' => null])
            </form>
        </div>
    </div>
</x-app-layout>
