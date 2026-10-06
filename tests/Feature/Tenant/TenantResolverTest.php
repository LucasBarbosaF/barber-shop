<?php

use App\Application\Shared\Tenancy\Exceptions\TenantAccessDenied;
use App\Application\Shared\Tenancy\ResolutionStatus;
use App\Application\Shared\Tenancy\TenantContext;
use App\Application\Shared\Tenancy\TenantResolver;
use App\Domain\Tenant\Enums\MembershipRole;
use App\Models\User;

/**
 * Resolução do tenant: quem resolve, com qual membership e o que acontece quando
 * a sessão aponta para uma barbearia que a pessoa não pode mais acessar.
 *
 * ## `actingAsUser()` não é cerimônia de teste
 *
 * O resolver decide o tenant lendo as memberships da pessoa, e essa leitura é
 * uma query que só devolve linhas quando `app.current_user` aponta para ela —
 * é o furo de SELECT do RLS, sem o qual a policy de tenant não tem o que
 * autorizar e devolve zero linhas. Quem seta a opção em produção é o
 * `SetUserDatabaseContext`, dentro do grupo `authenticated`.
 *
 * Sem `actingAsUser()` o resolver devolve `None` para todo mundo. Falha fechada,
 * então não é brecha — é a tela dizendo "você não pertence a nenhuma barbearia"
 * para quem pertence a três. Por isso o helper aparece antes de cada chamada:
 * ele declara a pré-condição que o middleware garante.
 *
 * O ratchet `freezes the call sites of the tenant resolver` existe para o
 * chamador em produção que não passa pelo grupo — job, comando, listener.
 */
function resolver(): TenantResolver
{
    return app(TenantResolver::class);
}

describe('selecao automatica', function () {
    it('adota a unica barbearia da pessoa', function () {
        [$tenant, $user] = tenantWithUser(MembershipRole::Receptionist);

        actingAsUser($user);
        $resolution = resolver()->resolveFor($user);

        expect($resolution->status)->toBe(ResolutionStatus::Resolved);
        expect($resolution->tenantId)->toBe((string) $tenant->id);
        expect(app(TenantContext::class)->id())->toBe((string) $tenant->id);
        expect($resolution->availableTenantIds)->toBe([(string) $tenant->id]);
    });

    it('mantem a barbearia ja escolhida enquanto a membership valer', function () {
        [$tenantA] = tenantWithUser();
        [$tenantB, , $membershipB] = tenantWithUser();

        // Duas barbearias para a mesma pessoa: a escolhida continua valendo.
        $user = User::factory()->create();
        joinsTenant($user, $tenantA, MembershipRole::Admin);
        joinsTenant($user, $tenantB, MembershipRole::Barber);

        app(TenantContext::class)->set((string) $tenantB->id);

        actingAsUser($user);
        $resolution = resolver()->resolveFor($user);

        expect($resolution->isResolved())->toBeTrue();
        expect($resolution->tenantId)->toBe((string) $membershipB->tenant_id);
    });

    it('pede selecao quando ha mais de uma barbearia', function () {
        [$tenantA] = tenantWithUser();
        [$tenantB] = tenantWithUser();

        $user = User::factory()->create();
        joinsTenant($user, $tenantA);
        joinsTenant($user, $tenantB);

        actingAsUser($user);
        $resolution = resolver()->resolveFor($user);

        expect($resolution->status)->toBe(ResolutionStatus::SelectionRequired);
        expect($resolution->needsSelection())->toBeTrue();
        expect($resolution->tenantId)->toBeNull();

        // Escolher a "primeira" seria inventar autorização que ninguém concedeu.
        expect(app(TenantContext::class)->id())->toBeNull();
        expect($resolution->availableTenantIds)
            ->toBe([(string) $tenantA->id, (string) $tenantB->id]);
    });

    it('reporta ausencia de barbearia quando a pessoa nao pertence a nenhuma', function () {
        $user = User::factory()->create();

        actingAsUser($user);
        $resolution = resolver()->resolveFor($user);

        expect($resolution->status)->toBe(ResolutionStatus::None);
        expect($resolution->availableTenantIds)->toBe([]);
        expect(app(TenantContext::class)->id())->toBeNull();
    });
});

describe('membership e tenant inativos nao contam', function () {
    it('ignora membership revogada', function () {
        [, , $membership] = tenantWithUser();
        revokingMembership($membership);

        actingAsUser($membership->user);
        $resolution = resolver()->resolveFor($membership->user);

        expect($resolution->status)->toBe(ResolutionStatus::None);
        expect(app(TenantContext::class)->id())->toBeNull();
    });

    it('ignora barbearia desativada', function () {
        [$tenant, $user] = tenantWithUser();
        $tenant->update(['is_active' => false]);

        actingAsUser($user);
        $resolution = resolver()->resolveFor($user);

        expect($resolution->status)->toBe(ResolutionStatus::None);
    });

    it('conta so as barbearias validas quando ha varias', function () {
        [$tenantA] = tenantWithUser();
        [$tenantB] = tenantWithUser();

        $user = User::factory()->create();
        joinsTenant($user, $tenantA);
        $revogada = joinsTenant($user, $tenantB);

        revokingMembership($revogada);

        actingAsUser($user);

        expect(resolver()->resolveFor($user)->status)->toBe(ResolutionStatus::Resolved);
        expect(app(TenantContext::class)->id())->toBe((string) $tenantA->id);
    });

    it('conta so as barbearias ativas quando ha varias', function () {
        [$tenantA] = tenantWithUser();
        [$tenantB] = tenantWithUser();

        $user = User::factory()->create();
        joinsTenant($user, $tenantA);
        joinsTenant($user, $tenantB);

        $tenantB->update(['is_active' => false]);

        actingAsUser($user);

        expect(resolver()->resolveFor($user)->status)->toBe(ResolutionStatus::Resolved);
        expect(app(TenantContext::class)->id())->toBe((string) $tenantA->id);
    });
});

describe('a sessao e revalidada, nunca obedecida', function () {
    it('descarta tenant plantado na sessao que a pessoa nao acessa', function () {
        [$tenantAlheio] = tenantWithUser();
        [$tenantDaPessoa, $user] = tenantWithUser();

        app(TenantContext::class)->set((string) $tenantAlheio->id);

        actingAsUser($user);
        $resolution = resolver()->resolveFor($user);

        expect($resolution->isResolved())->toBeTrue();
        expect($resolution->tenantId)->toBe((string) $tenantDaPessoa->id);
        expect(app(TenantContext::class)->id())->toBe((string) $tenantDaPessoa->id);
    });

    it('limpa o contexto quando a membership da sessao foi revogada', function () {
        [$tenant, $user, $membership] = tenantWithUser();
        revokingMembership($membership);

        app(TenantContext::class)->set((string) $tenant->id);

        actingAsUser($user);

        expect(resolver()->resolveFor($user)->status)->toBe(ResolutionStatus::None);
        expect(app(TenantContext::class)->id())->toBeNull();
    });

    it('limpa o contexto quando a barbearia da sessao foi desativada', function () {
        [$tenant, $user] = tenantWithUser();
        app(TenantContext::class)->set((string) $tenant->id);

        $tenant->update(['is_active' => false]);

        actingAsUser($user);

        expect(resolver()->resolveFor($user)->status)->toBe(ResolutionStatus::None);
        expect(app(TenantContext::class)->id())->toBeNull();
    });
});

describe('troca explicita', function () {
    it('troca para uma barbearia da propria pessoa', function () {
        [$tenantA] = tenantWithUser();
        [$tenantB] = tenantWithUser();

        $user = User::factory()->create();
        joinsTenant($user, $tenantA);
        joinsTenant($user, $tenantB);

        app(TenantContext::class)->set((string) $tenantA->id);

        actingAsUser($user);
        $tenant = resolver()->switchTo($user, (string) $tenantB->id);

        expect($tenant->id)->toBe($tenantB->id);
        expect(app(TenantContext::class)->id())->toBe((string) $tenantB->id);
    });

    it('recusa tenant de outra pessoa', function () {
        [$tenantAlheio] = tenantWithUser();
        [$tenantDaPessoa, $user] = tenantWithUser();

        app(TenantContext::class)->set((string) $tenantDaPessoa->id);

        actingAsUser($user);

        expect(fn () => resolver()->switchTo($user, (string) $tenantAlheio->id))
            ->toThrow(TenantAccessDenied::class);

        // O contexto não muda quando a troca é negada.
        expect(app(TenantContext::class)->id())->toBe((string) $tenantDaPessoa->id);
    });

    it('recusa tenant inexistente', function () {
        [, $user] = tenantWithUser();

        actingAsUser($user);

        expect(fn () => resolver()->switchTo($user, '999999'))
            ->toThrow(TenantAccessDenied::class);
    });

    it('recusa barbearia desativada', function () {
        [$tenant, $user] = tenantWithUser();
        $tenant->update(['is_active' => false]);

        actingAsUser($user);

        expect(fn () => resolver()->switchTo($user, (string) $tenant->id))
            ->toThrow(TenantAccessDenied::class);
    });

    it('recusa membership revogada', function () {
        [$tenant, $user, $membership] = tenantWithUser();
        revokingMembership($membership);

        actingAsUser($user);

        expect(fn () => resolver()->switchTo($user, (string) $tenant->id))
            ->toThrow(TenantAccessDenied::class);
    });

    it('nega superadmin sem membership', function () {
        [$tenant] = tenantWithUser();
        $superadmin = User::factory()->superadmin()->create();

        actingAsUser($superadmin);

        expect(fn () => resolver()->switchTo($superadmin, (string) $tenant->id))
            ->toThrow(TenantAccessDenied::class);

        expect(resolver()->resolveFor($superadmin)->status)->toBe(ResolutionStatus::None);
    });
});
