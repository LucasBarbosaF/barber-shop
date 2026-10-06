<x-app-layout :title="$membership->user?->name">
    <div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-4">
        <div>
            <h1 class="h3 mb-0">{{ $membership->user?->name }}</h1>
            <p class="text-secondary mb-0">Membro desta equipe</p>
        </div>

        <a href="{{ route('app.team.index') }}" class="btn btn-sm btn-outline-secondary">
            Voltar à equipe
        </a>
    </div>

    {{--
        Esta tela só é alcançada depois da MembershipPolicy: a permissão
        `users.view` foi conferida no tenant corrente e a pertinência do registro
        a esse mesmo tenant também. Os dados abaixo são do vínculo, não do
        usuário global.
    --}}
    <div class="card">
        <div class="card-body">
            <dl class="row mb-0">
                <dt class="col-sm-4 text-secondary">E-mail</dt>
                <dd class="col-sm-8">{{ $membership->user?->email }}</dd>

                <dt class="col-sm-4 text-secondary">Papel nesta barbearia</dt>
                <dd class="col-sm-8">{{ $membership->role->label() }}</dd>

                <dt class="col-sm-4 text-secondary">Vínculo</dt>
                <dd class="col-sm-8">
                    @if ($membership->is_active)
                        <span class="badge text-bg-success">Ativo</span>
                    @else
                        <span class="badge text-bg-danger">Revogado</span>
                    @endif
                </dd>

                <dt class="col-sm-4 text-secondary">Desde</dt>
                <dd class="col-sm-8 mb-0">
                    {{ $membership->created_at?->format('d/m/Y') }}
                </dd>
            </dl>
        </div>
    </div>
</x-app-layout>
