<x-app-layout title="Serviços">
    <div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-4">
        <div>
            <h1 class="h3 mb-0">Serviços</h1>
            <p class="text-secondary mb-0">Gerencie os serviços oferecidos pela barbearia.</p>
        </div>

        @can('services.create')
            <a href="{{ route('app.services.create') }}" class="btn btn-primary">
                <i class="fas fa-plus me-1" aria-hidden="true"></i>
                Novo serviço
            </a>
        @endcan
    </div>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th scope="col">Serviço</th>
                        <th scope="col">Duração</th>
                        <th scope="col">Preço</th>
                        <th scope="col">Status</th>
                        <th scope="col" class="text-end">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($services as $service)
                        <tr>
                            <td>
                                <div class="fw-semibold">{{ $service->name }}</div>
                                @if ($service->description)
                                    <div class="small text-secondary">{{ $service->description }}</div>
                                @endif
                            </td>
                            <td>{{ $service->duration_minutes }} min</td>
                            <td>R$ {{ number_format((float) $service->price, 2, ',', '.') }}</td>
                            <td>
                                @if ($service->is_active)
                                    <span class="badge text-bg-success">Ativo</span>
                                @else
                                    <span class="badge text-bg-secondary">Inativo</span>
                                @endif
                            </td>
                            <td class="text-end text-nowrap">
                                @can('services.update')
                                    <a href="{{ route('app.services.edit', $service) }}"
                                       class="btn btn-sm btn-outline-primary">
                                        Editar
                                    </a>
                                @endcan
                                @can('services.delete')
                                    <form method="POST" action="{{ route('app.services.destroy', $service) }}"
                                          class="d-inline"
                                          onsubmit="return confirm('Deseja excluir este serviço?')">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-sm btn-outline-danger">Excluir</button>
                                    </form>
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center text-secondary py-5">
                                <i class="fas fa-tags fa-2x mb-3 d-block" aria-hidden="true"></i>
                                Nenhum serviço cadastrado.
                                @can('services.create')
                                    <div class="mt-2">
                                        <a href="{{ route('app.services.create') }}">Cadastre o primeiro serviço</a>
                                    </div>
                                @endcan
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">{{ $services->links() }}</div>
</x-app-layout>
