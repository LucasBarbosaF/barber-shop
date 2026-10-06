<x-app-layout title="Equipe">
    @if (session('team_member_password'))
        <div class="alert alert-success" role="alert">
            <h2 class="h6 alert-heading">Acesso criado para {{ session('team_member_name') }}</h2>
            <p>
                Repasse estas credenciais ao membro da equipe. A senha aparece
                <strong>uma única vez</strong> e deverá ser trocada no primeiro acesso.
            </p>
            <ul class="mb-0">
                <li><strong>E-mail:</strong> {{ session('team_member_email') }}</li>
                <li><strong>Senha provisória:</strong> <code>{{ session('team_member_password') }}</code></li>
            </ul>
            @if (session('team_member_barber_id'))
                @can('barbers.update')
                    <div class="mt-3">
                        <a href="{{ route('app.barbers.edit', session('team_member_barber_id')) }}"
                           class="btn btn-sm btn-success">
                            Revisar perfil profissional
                        </a>
                    </div>
                @endcan
            @endif
        </div>
    @endif

    <div class="d-flex justify-content-between align-items-end mb-4">
        <div>
            <h1 class="h3 mb-0">Equipe</h1>
            <p class="text-secondary mb-0">Pessoas com acesso a esta barbearia</p>
        </div>

        <div class="d-flex gap-2">
            @can('users.manage')
                <a href="{{ route('app.team.create') }}" class="btn btn-primary">
                    <i class="fas fa-user-plus me-1" aria-hidden="true"></i>
                    Adicionar à equipe
                </a>
            @endcan
            <a href="{{ route('app.dashboard') }}" class="btn btn-sm btn-outline-secondary">
                Voltar
            </a>
        </div>
    </div>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th scope="col">Nome</th>
                        <th scope="col">E-mail</th>
                        <th scope="col">Papel</th>
                        <th scope="col">Cadastro de barbeiro</th>
                        <th scope="col">Status</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($memberships as $membership)
                        <tr>
                            <td>
                                <a href="{{ route('app.team.show', $membership) }}">
                                    {{ $membership->user?->name }}
                                </a>
                            </td>
                            <td>{{ $membership->user?->email }}</td>
                            <td>{{ $membership->role->label() }}</td>
                            <td>
                                @if ($membership->role === \App\Domain\Tenant\Enums\MembershipRole::Barber)
                                    @if ($barberProfiles->has($membership->user_id))
                                        @can('barbers.update')
                                            <a href="{{ route('app.barbers.edit', $barberProfiles->get($membership->user_id)) }}">
                                                Editar perfil profissional
                                            </a>
                                        @else
                                            <span class="text-secondary">Perfil criado</span>
                                        @endcan
                                    @else
                                        <span class="text-secondary">Perfil não vinculado</span>
                                    @endif
                                @else
                                    —
                                @endif
                            </td>
                            <td>
                                @if ($membership->is_active)
                                    <span class="badge text-bg-success">Ativo</span>
                                @else
                                    <span class="badge text-bg-danger">Revogado</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center text-secondary py-4">
                                Nenhuma barbearia selecionada.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">{{ $memberships->links() }}</div>
</x-app-layout>
