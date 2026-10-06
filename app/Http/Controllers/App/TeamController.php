<?php

namespace App\Http\Controllers\App;

use App\Application\Shared\Tenancy\TenantContext;
use App\Application\Team\Actions\CreateTeamMember;
use App\Domain\Tenant\Enums\MembershipRole;
use App\Domain\Tenant\Models\Membership;
use App\Http\Controllers\Controller;
use App\Infrastructure\Shared\Persistence\TenantScope;
use App\Models\Barber;
use App\Models\Service;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TeamController extends Controller
{
    public function index(): View
    {
        $this->authorize('viewAny', Membership::class);

        $memberships = TenantScope::for(Membership::query())
            ->with('user')
            ->latest()
            ->paginate(15);
        $barberProfiles = Barber::query()
            ->whereNotNull('user_id')
            ->get(['id', 'user_id'])
            ->keyBy('user_id');

        return view('app.team.index', [
            'memberships' => $memberships,
            'barberProfiles' => $barberProfiles,
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', Membership::class);

        return view('app.team.create', [
            'roles' => MembershipRole::options(),
            'initialRole' => MembershipRole::tryFrom((string) request()->query('role')),
            'services' => Service::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'duration_minutes', 'price']),
        ]);
    }

    public function store(
        Request $request,
        CreateTeamMember $createTeamMember,
        TenantContext $tenantContext,
    ): RedirectResponse {
        $this->authorize('create', Membership::class);

        $phone = preg_replace('/\D+/', '', (string) $request->input('phone'));
        $request->merge(['phone' => $phone]);
        $tenantId = $tenantContext->id();
        $attributes = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'role' => ['required', Rule::enum(MembershipRole::class)],
            'phone' => [
                Rule::requiredIf($request->input('role') === MembershipRole::Barber->value),
                'nullable',
                'string',
                'regex:/^\d{10,11}$/',
                Rule::unique('barbers', 'phone')->where('tenant_id', $tenantContext->id()),
            ],
            'service_ids' => [
                Rule::requiredIf($request->input('role') === MembershipRole::Barber->value),
                'array',
                'min:1',
            ],
            'service_ids.*' => [
                'required',
                'integer',
                'distinct',
                Rule::exists('services', 'id')
                    ->where('tenant_id', $tenantId)
                    ->where('is_active', true),
            ],
            'commission_percentage' => [
                Rule::requiredIf($request->input('role') === MembershipRole::Barber->value),
                'nullable',
                'numeric',
                'decimal:0,2',
                'between:0,100',
            ],
            'business_hours' => [
                Rule::requiredIf($request->input('role') === MembershipRole::Barber->value),
                'array',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! is_array($value)) {
                        return;
                    }

                    if (array_diff(array_keys($value), range(0, 6)) !== []) {
                        $fail('Informe horários apenas para os dias válidos da semana.');

                        return;
                    }

                    $hasWorkingDay = collect($value)->contains(
                        fn (mixed $period): bool => is_array($period)
                            && filled($period['opens_at'] ?? null)
                            && filled($period['closes_at'] ?? null),
                    );

                    if (! $hasWorkingDay) {
                        $fail('Informe o expediente de pelo menos um dia da semana.');
                    }

                    foreach ($value as $period) {
                        if (! is_array($period)) {
                            continue;
                        }

                        $opensAt = $period['opens_at'] ?? null;
                        $closesAt = $period['closes_at'] ?? null;
                        if (filled($opensAt) && filled($closesAt) && $opensAt >= $closesAt) {
                            $fail('O horário de encerramento deve ser posterior ao de abertura.');

                            return;
                        }
                    }
                },
            ],
            'business_hours.*.opens_at' => [
                'nullable',
                'date_format:H:i',
                'required_with:business_hours.*.closes_at',
            ],
            'business_hours.*.closes_at' => [
                'nullable',
                'date_format:H:i',
                'required_with:business_hours.*.opens_at',
            ],
        ], [
            'email.unique' => 'Este e-mail já possui uma conta no sistema.',
            'phone.regex' => 'Informe um telefone com DDD válido.',
            'service_ids.required' => 'Selecione ao menos um serviço realizado pelo barbeiro.',
            'service_ids.min' => 'Selecione ao menos um serviço realizado pelo barbeiro.',
            'commission_percentage.required' => 'Informe o percentual de comissão do barbeiro.',
            'business_hours.required' => 'Informe o expediente semanal do barbeiro.',
        ]);

        $result = $createTeamMember->execute([
            'name' => $attributes['name'],
            'email' => $attributes['email'],
            'role' => MembershipRole::from($attributes['role']),
            'phone' => $attributes['phone'] ?? null,
            'service_ids' => array_map('intval', $attributes['service_ids'] ?? []),
            'commission_percentage' => $attributes['commission_percentage'] ?? null,
            'business_hours' => $attributes['business_hours'] ?? [],
        ]);

        $redirect = redirect()
            ->route('app.team.index')
            ->with('team_member_name', $result['membership']->user?->name)
            ->with('team_member_email', $result['membership']->user?->email)
            ->with('team_member_password', $result['password']);

        if ($result['membership']->role === MembershipRole::Barber) {
            $barberId = Barber::query()
                ->where('user_id', $result['membership']->user_id)
                ->value('id');

            $redirect->with('team_member_barber_id', $barberId);
        }

        return $redirect;
    }

    public function show(Membership $membership): View
    {
        $this->authorize('view', $membership);

        return view('app.team.show', [
            'membership' => $membership->load('user'),
        ]);
    }
}
