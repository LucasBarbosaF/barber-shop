<x-app-layout title="Barbearias">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
        <div>
            <h1 class="h3 mb-0">Barbearias</h1>
            <p class="text-secondary mb-0">Todas as barbearias cadastradas na plataforma</p>
        </div>
        <a href="{{ route('admin.barbershops.create') }}" class="btn btn-primary">
            Nova barbearia
        </a>
    </div>

    @if (session('created_password'))
        <div class="alert alert-success" role="alert">
            <h2 class="h6 alert-heading">
                Barbearia "{{ session('created_barbershop') }}" criada
            </h2>
            <p>
                Repasse estas credenciais ao responsável. A senha aparece <strong>uma única vez</strong>
                e ele será obrigado a trocá-la no primeiro acesso.
            </p>
            <ul class="mb-0">
                <li><strong>E-mail:</strong> {{ session('created_email') }}</li>
                <li><strong>Senha provisória:</strong> <code class="fw-bold">{{ session('created_password') }}</code></li>
            </ul>
        </div>
    @endif

    <div class="card">
        @if ($tenants->isEmpty())
            <div class="card-body text-center text-secondary">
                Nenhuma barbearia cadastrada ainda.
            </div>
        @else
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 table-stack">
                    <thead class="table-light">
                        <tr>
                            <th scope="col">Barbearia</th>
                            <th scope="col">Responsável</th>
                            <th scope="col">Contato</th>
                            <th scope="col">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($tenants as $tenant)
                            <tr>
                                <td>
                                    <span class="fw-semibold">{{ $tenant->name }}</span>
                                    <span class="d-block text-secondary small">{{ $tenant->slug }}</span>
                                </td>
                                <td>
                                    @forelse ($tenant->admins as $admin)
                                        {{ $admin->name }}
                                        <span class="d-block text-secondary small">{{ $admin->email }}</span>
                                    @empty
                                        <span class="text-secondary">—</span>
                                    @endforelse
                                </td>
                                <td>
                                    {{ $tenant->phone ?: '—' }}
                                    @if ($tenant->email)
                                        <span class="d-block text-secondary small">{{ $tenant->email }}</span>
                                    @endif
                                </td>
                                <td>
                                    @if ($tenant->is_active)
                                        <span class="badge text-bg-success">Ativa</span>
                                    @else
                                        <span class="badge text-bg-danger">Inativa</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="card-footer">
                {{ $tenants->links() }}
            </div>
        @endif
    </div>
</x-app-layout>
