<x-app-layout title="Clientes">
    <div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-4">
        <div>
            <h1 class="h3 mb-0">Clientes</h1>
            <p class="text-secondary mb-0">Gerencie os clientes da barbearia.</p>
        </div>

        @can('customers.create')
            <a href="{{ route('app.customers.create') }}" class="btn btn-primary">
                <i class="fas fa-plus me-1" aria-hidden="true"></i>
                Novo cliente
            </a>
        @endcan
    </div>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 table-stack">
                <thead class="table-light">
                    <tr>
                        <th scope="col">Nome</th>
                        <th scope="col">Telefone</th>
                        <th scope="col">Observações</th>
                        <th scope="col" class="text-end">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($customers as $customer)
                        <tr>
                            <td class="fw-semibold">{{ $customer->name }}</td>
                            <td>{{ $customer->phone }}</td>
                            <td>{{ $customer->notes ?: '—' }}</td>
                            <td class="text-end text-nowrap">
                                @can('customers.update')
                                    <a href="{{ route('app.customers.edit', $customer) }}"
                                       class="btn btn-sm btn-outline-primary">
                                        Editar
                                    </a>
                                @endcan
                                @can('customers.delete')
                                    <form method="POST" action="{{ route('app.customers.destroy', $customer) }}"
                                          class="d-inline"
                                          onsubmit="return confirm('Deseja excluir este cliente?')">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-sm btn-outline-danger">Excluir</button>
                                    </form>
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="text-center text-secondary py-5">
                                <i class="fas fa-users fa-2x mb-3 d-block" aria-hidden="true"></i>
                                Nenhum cliente cadastrado.
                                @can('customers.create')
                                    <div class="mt-2">
                                        <a href="{{ route('app.customers.create') }}">Cadastre o primeiro cliente</a>
                                    </div>
                                @endcan
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">{{ $customers->links() }}</div>
</x-app-layout>
