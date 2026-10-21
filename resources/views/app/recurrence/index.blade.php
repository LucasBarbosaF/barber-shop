<x-app-layout title="Recorrência">
    <div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-4">
        <div>
            <h1 class="h3 mb-0">Recorrência</h1>
            <p class="text-secondary mb-0">Planos de corte recorrente e assinaturas dos clientes.</p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            @can('recurrence.manage')
                <button class="btn btn-primary" type="button" data-bs-toggle="modal"
                        data-bs-target="#newSubscriptionModal">
                    <i class="fas fa-user-plus me-1" aria-hidden="true"></i>
                    Nova assinatura
                </button>
            @endcan
            @can('recurrence.create')
                <a href="{{ route('app.recurrence.create') }}" class="btn btn-outline-primary">
                    <i class="fas fa-plus me-1" aria-hidden="true"></i>
                    Novo plano
                </a>
            @endcan
        </div>
    </div>

    @if (session('status'))
        <div class="alert alert-success" role="status">
            <i class="fas fa-circle-check me-1" aria-hidden="true"></i>
            {{ session('status') }}
        </div>
    @endif

    <ul class="nav nav-pills mb-4">
        <li class="nav-item">
            <a class="nav-link @if ($tab === 'planos') active @endif"
               href="{{ route('app.recurrence.index', ['tab' => 'planos']) }}">
                <i class="fas fa-rotate me-1" aria-hidden="true"></i>
                Planos
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link @if ($tab === 'assinaturas') active @endif"
               href="{{ route('app.recurrence.index', ['tab' => 'assinaturas']) }}">
                <i class="fas fa-users me-1" aria-hidden="true"></i>
                Assinaturas
            </a>
        </li>
    </ul>

    @if ($tab === 'planos')
        <form method="GET" action="{{ route('app.recurrence.index') }}" class="card mb-4">
            <input type="hidden" name="tab" value="planos">
            <div class="card-body">
                <div class="row g-3 align-items-end">
                    <div class="col-sm-8 col-lg-9">
                        <label for="plan_search" class="form-label">Buscar plano</label>
                        <input id="plan_search" name="plan_search" type="search" maxlength="150"
                               placeholder="Nome do plano ou serviço…"
                               value="{{ request('plan_search') }}" class="form-control">
                    </div>
                    <div class="col-sm-4 col-lg-3 d-grid">
                        <button class="btn btn-outline-primary" type="submit">
                            <i class="fas fa-filter me-1" aria-hidden="true"></i>
                            Filtrar
                        </button>
                    </div>
                </div>
            </div>
        </form>

        <div class="card">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 table-stack">
                    <thead class="table-light">
                        <tr>
                            <th scope="col">Plano</th>
                            <th scope="col">Serviço</th>
                            <th scope="col">Cortes</th>
                            <th scope="col">Vigência</th>
                            <th scope="col">Preço/mês</th>
                            <th scope="col">Status</th>
                            <th scope="col" class="text-end">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($plans as $plan)
                            <tr>
                                <td>
                                    <div class="fw-semibold">{{ $plan->name }}</div>
                                    @if ($plan->description)
                                        <div class="small text-secondary">{{ $plan->description }}</div>
                                    @endif
                                </td>
                                <td>{{ $plan->service?->name ?? '—' }}</td>
                                <td>
                                    {{ $plan->sessions_per_month }}/mês
                                    <span class="text-secondary">· {{ $plan->total_sessions }} no total</span>
                                </td>
                                <td>{{ $plan->duration_months }} meses</td>
                                <td>R$ {{ number_format((float) $plan->price, 2, ',', '.') }}<span class="text-secondary">/mês</span></td>
                                <td>
                                    @if ($plan->is_active)
                                        <span class="badge text-bg-success">Ativo</span>
                                    @else
                                        <span class="badge text-bg-secondary">Inativo</span>
                                    @endif
                                </td>
                                <td class="text-end text-nowrap">
                                    @can('recurrence.update')
                                        <a href="{{ route('app.recurrence.edit', $plan) }}"
                                           class="btn btn-sm btn-outline-primary">
                                            Editar
                                        </a>
                                    @endcan
                                    @can('recurrence.delete')
                                        <form method="POST" action="{{ route('app.recurrence.destroy', $plan) }}"
                                              class="d-inline"
                                              onsubmit="return confirm('Deseja excluir o plano {{ $plan->name }}? Assinaturas já criadas mantêm os dados.')" >
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-sm btn-outline-danger">Excluir</button>
                                        </form>
                                    @endcan
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="text-center text-secondary py-5">
                                    <i class="fas fa-rotate fa-2x mb-3 d-block" aria-hidden="true"></i>
                                    Nenhum plano cadastrado.
                                    @can('recurrence.create')
                                        <div class="mt-2">
                                            <a href="{{ route('app.recurrence.create') }}">Cadastre o primeiro plano</a>
                                        </div>
                                    @endcan
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="mt-3">{{ $plans->links() }}</div>
    @else
        <form method="GET" action="{{ route('app.recurrence.index') }}" class="card mb-4">
            <input type="hidden" name="tab" value="assinaturas">
            <div class="card-body">
                <div class="row g-3 align-items-end">
                    <div class="col-sm-6 col-lg-5">
                        <label for="subscription_search" class="form-label">Buscar assinatura</label>
                        <input id="subscription_search" name="subscription_search" type="search" maxlength="150"
                               placeholder="Cliente, telefone ou plano…"
                               value="{{ request('subscription_search') }}" class="form-control">
                    </div>
                    <div class="col-sm-4 col-lg-3">
                        <label for="status" class="form-label">Status</label>
                        <select id="status" name="status" class="form-select">
                            <option value="">Todos</option>
                            @foreach (\App\Domain\Recurrence\Enums\SubscriptionStatus::cases() as $statusOption)
                                <option value="{{ $statusOption->value }}"
                                    @selected(request('status') === $statusOption->value)>
                                    {{ $statusOption->label() }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-sm-2 col-lg-2 d-grid">
                        <button class="btn btn-outline-primary" type="submit">
                            <i class="fas fa-filter me-1" aria-hidden="true"></i>
                            Filtrar
                        </button>
                    </div>
                </div>
            </div>
        </form>

        <div class="card">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 table-stack">
                    <thead class="table-light">
                        <tr>
                            <th scope="col">Cliente</th>
                            <th scope="col">Plano</th>
                            <th scope="col">Barbeiro</th>
                            <th scope="col">Vigência</th>
                            <th scope="col">Preço/mês</th>
                            <th scope="col">Status</th>
                            <th scope="col" class="text-end">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($subscriptions as $subscription)
                            <tr>
                                <td>
                                    <div class="fw-semibold">{{ $subscription->customer?->name ?? '—' }}</div>
                                    <div class="small text-secondary">{{ $subscription->customer?->phone }}</div>
                                </td>
                                <td>{{ $subscription->plan_name }}</td>
                                <td>{{ $subscription->barber?->name ?? 'Sem preferência' }}</td>
                                <td class="text-nowrap">
                                    {{ $subscription->starts_at->format('d/m/Y') }}
                                    – {{ $subscription->ends_at->format('d/m/Y') }}
                                </td>
                                <td>R$ {{ number_format((float) $subscription->price, 2, ',', '.') }}<span class="text-secondary">/mês</span></td>
                                <td>
                                    @include('app.recurrence.partials.status-badge', ['subscription' => $subscription])
                                </td>
                                <td class="text-end text-nowrap">
                                    <a href="{{ route('app.recurrence.subscriptions.show', $subscription) }}"
                                       class="btn btn-sm btn-outline-primary">
                                        Abrir
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="text-center text-secondary py-5">
                                    <i class="fas fa-users fa-2x mb-3 d-block" aria-hidden="true"></i>
                                    Nenhuma assinatura encontrada.
                                    @can('recurrence.manage')
                                        <div class="mt-2">
                                            <a href="#" data-bs-toggle="modal" data-bs-target="#newSubscriptionModal">
                                                Crie a primeira assinatura
                                            </a>
                                        </div>
                                    @endcan
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="mt-3">{{ $subscriptions->links() }}</div>
    @endif

    @can('recurrence.manage')
        @include('app.recurrence.partials.new-subscription-modal')
    @endcan
</x-app-layout>
