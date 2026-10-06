<x-app-layout title="Barbeiros">
    <div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-4">
        <div>
            <h1 class="h3 mb-0">Barbeiros</h1>
            <p class="text-secondary mb-0">Gerencie a equipe de profissionais da barbearia.</p>
        </div>

        @can('barbers.create')
            @can('users.manage')
                <a href="{{ route('app.team.create', ['role' => 'barber']) }}" class="btn btn-primary">
                    <i class="fas fa-user-plus me-1" aria-hidden="true"></i>
                    Cadastrar barbeiro
                </a>
            @else
                <a href="{{ route('app.barbers.create') }}" class="btn btn-primary">
                    <i class="fas fa-plus me-1" aria-hidden="true"></i>
                    Novo perfil profissional
                </a>
            @endcan
        @endcan
    </div>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 table-stack">
                <thead class="table-light">
                    <tr>
                        <th scope="col">Nome</th>
                        <th scope="col">Telefone</th>
                        <th scope="col">E-mail</th>
                        <th scope="col">Serviços realizados</th>
                        <th scope="col">Status</th>
                        <th scope="col" class="text-end">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($barbers as $barber)
                        <tr>
                            <td>
                                <div class="fw-semibold">{{ $barber->name }}</div>
                                @if ($barber->bio)
                                    <div class="small text-secondary">{{ $barber->bio }}</div>
                                @endif
                            </td>
                            <td>{{ $barber->phone }}</td>
                            <td>{{ $barber->email ?: $barber->user?->email ?: '—' }}</td>
                            <td>
                                @forelse ($barber->services as $service)
                                    <span class="badge text-bg-light">{{ $service->name }}</span>
                                @empty
                                    <span class="text-secondary">Nenhum serviço vinculado</span>
                                @endforelse
                            </td>
                            <td>
                                @if ($barber->is_active)
                                    <span class="badge text-bg-success">Ativo</span>
                                @else
                                    <span class="badge text-bg-secondary">Inativo</span>
                                @endif
                            </td>
                            <td class="text-end text-nowrap">
                                @can('barbers.update')
                                    <a href="{{ route('app.barbers.edit', $barber) }}"
                                       class="btn btn-sm btn-outline-primary">
                                        Editar
                                    </a>
                                @endcan
                                @can('barbers.delete')
                                    <form method="POST" action="{{ route('app.barbers.destroy', $barber) }}"
                                          class="d-inline"
                                          onsubmit="return confirm('Deseja excluir este barbeiro?')">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-sm btn-outline-danger">Excluir</button>
                                    </form>
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center text-secondary py-5">
                                <i class="fas fa-user-tie fa-2x mb-3 d-block" aria-hidden="true"></i>
                                Nenhum barbeiro cadastrado.
                                @can('barbers.create')
                                    <div class="mt-2">
                                        @can('users.manage')
                                            <a href="{{ route('app.team.create', ['role' => 'barber']) }}">
                                                Cadastre o primeiro barbeiro e crie o acesso em um só passo
                                            </a>
                                        @else
                                            <a href="{{ route('app.barbers.create') }}">Cadastre o primeiro perfil profissional</a>
                                        @endcan
                                    </div>
                                @endcan
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">{{ $barbers->links() }}</div>
</x-app-layout>
