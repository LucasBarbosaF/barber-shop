<x-app-layout title="Permissões">
    <div class="mb-4">
        <h1 class="h3 mb-1">Permissões por perfil</h1>
        <p class="text-secondary mb-0">Consulte as permissões concedidas a cada papel na barbearia.</p>
    </div>
    @include('app.settings._navigation')

    <div class="alert alert-info" role="note">
        As permissões são definidas pelo sistema para cada perfil e não podem ser alteradas nesta tela.
        Para atribuir um perfil a uma pessoa, acesse a seção <a href="{{ route('app.team.index') }}">Equipe</a>.
    </div>

    <section class="card">
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th scope="col">Permissão</th>
                        @foreach ($roles as $role)
                            <th scope="col" class="text-center">{{ $role->label() }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($permissionsByResource as $resource => $permissions)
                        <tr class="table-secondary">
                            <th colspan="{{ count($roles) + 1 }}" scope="colgroup">{{ ucfirst($resource) }}</th>
                        </tr>
                        @foreach ($permissions as $permission)
                            <tr>
                                <th scope="row" class="fw-normal">{{ $permission->label() }}</th>
                                @foreach ($roles as $role)
                                    <td class="text-center">
                                        @if (in_array($permission->value, $rolePermissions[$role->value], true))
                                            <i class="fas fa-check text-success" aria-label="Permitido"></i>
                                        @else
                                            <span class="text-secondary" aria-label="Não permitido">—</span>
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>
</x-app-layout>
