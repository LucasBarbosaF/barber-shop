<?php

use App\Domain\Tenant\Enums\MembershipRole;
use App\Models\User;
use Illuminate\Foundation\Vite;

/**
 * A casca AdminLTE do painel (Sprint 29, template): sidebar, header, footer e
 * a área de conteúdo onde as páginas entram.
 *
 * O menu escondido aqui é espelho do Gate, nunca substituto dele — a rota
 * `/app/equipe` continua exigindo `permission:users.view` por conta própria.
 */
describe('template do painel', function () {
    it('renderiza sidebar, header, footer e conteudo', function () {
        [$tenant, $user] = tenantWithUser(MembershipRole::Admin);

        $this->actingAs($user)
            ->get(route('app.dashboard'))
            ->assertOk()
            ->assertSee('app-wrapper', escape: false)
            ->assertSee('app-sidebar', escape: false)
            ->assertSee('app-header', escape: false)
            ->assertSee('app-main', escape: false)
            ->assertSee('app-content', escape: false)
            ->assertSee('app-footer', escape: false)
            ->assertSee('Dashboard');
    });

    it('mostra a equipe no menu apenas para quem tem users.view', function () {
        [, $admin] = tenantWithUser(MembershipRole::Admin);

        $response = $this->actingAs($admin)
            ->get(route('app.dashboard'))
            ->assertOk()
            ->assertSee(route('app.team.index'), escape: false)
            ->assertSee(route('app.customers.index'), escape: false)
            ->assertSee(route('app.barbers.index'), escape: false)
            ->assertSee(route('app.services.index'), escape: false)
            ->assertSee('data-appointment-notifications', escape: false)
            ->assertSee(route('app.agenda.index'), escape: false)
            ->assertSee(route('app.cash.index'), escape: false)
            ->assertSee(route('app.payments.index'), escape: false)
            ->assertSee(route('app.commissions.index'), escape: false)
            ->assertSee('aria-label="Atendimento - abrir agenda"', escape: false);

        expect(substr_count($response->getContent(), 'class="nav nav-treeview"'))->toBe(7)
            ->and($response->getContent())->toContain('data-lte-toggle="treeview"')
            ->and($response->getContent())->toContain('aria-controls="menu-operacao"')
            ->and($response->getContent())->toContain('aria-controls="menu-equipe"');

        [, $receptionist] = tenantWithUser(MembershipRole::Receptionist);

        $this->actingAs($receptionist)
            ->get(route('app.dashboard'))
            ->assertOk()
            ->assertDontSee(route('app.team.index'), escape: false)
            ->assertDontSee(route('app.commissions.index'), escape: false)
            ->assertDontSee('id="menu-equipe"', escape: false);

        [, $financeiro] = tenantWithUser(MembershipRole::Financeiro);

        $this->actingAs($financeiro)
            ->get(route('app.dashboard'))
            ->assertOk()
            ->assertDontSee('aria-label="Atendimento - abrir agenda"', escape: false);
    });

    it('esconde o bloco do superadmin de quem nao e superadmin', function () {
        [, $user] = tenantWithUser(MembershipRole::Admin);

        $this->actingAs($user)
            ->get(route('app.dashboard'))
            ->assertOk()
            ->assertDontSee('PLATAFORMA', escape: false);

        $superadmin = User::factory()->superadmin()->create();

        $response = $this->actingAs($superadmin)
            ->get(route('app.dashboard'))
            ->assertOk()
            ->assertSee('Plataforma')
            ->assertSee('aria-controls="menu-plataforma"', escape: false)
            ->assertSee('id="menu-plataforma"', escape: false);

        expect(substr_count($response->getContent(), 'class="nav nav-treeview"'))->toBe(7);
    });

    it('oferece o logout no header e no menu lateral', function () {
        [, $user] = tenantWithUser(MembershipRole::Admin);

        $response = $this->actingAs($user)
            ->get(route('app.dashboard'))
            ->assertOk();

        $html = $response->getContent();

        expect(substr_count($html, 'action="'.route('logout').'"'))->toBe(2);
    });

    /*
     * A suíte roda com `withoutVite()` (tests/TestCase.php), então os testes
     * comuns nunca veem o manifest: um input renomeado em `vite.config.js` ou
     * um `@vite` apontando para o pacote errado passaria em silêncio. Este
     * teste existe justamente para isso — e é o único que precisa do build.
     *
     * O render vai pelo manifest de produção, com `useHotFile` apontando para
     * um arquivo que não existe: assim um dev server ligado (`public/hot`)
     * não troca os assets do build pelos do 127.0.0.1:5173.
     */
    it('resolve o manifest do vite e entrega os assets do painel', function () {
        if (! file_exists(public_path('build/manifest.json'))) {
            $this->markTestSkipped('build ausente: rode `npm run build` (ou `make front`)');
        }

        $this->withVite();

        $assets = (string) app(Vite::class)->useHotFile('/tmp/vite-hot-nao-existe')([
            'resources/css/admin.css',
            'resources/js/admin.js',
        ]);

        expect($assets)->toContain('/build/assets/admin-');

        preg_match_all('#/build/assets/[a-zA-Z0-9._-]+#', $assets, $matches);
        $files = array_unique($matches[0]);

        expect($files)->toHaveCount(2);

        foreach ($files as $file) {
            expect(file_exists(public_path(ltrim($file, '/'))))->toBeTrue();
        }
    });
});
