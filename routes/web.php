<?php

use App\Http\Controllers\Admin\BarbershopController;
use App\Http\Controllers\App\AgendaController;
use App\Http\Controllers\App\AppointmentNotificationController;
use App\Http\Controllers\App\AttendanceController;
use App\Http\Controllers\App\BarberController;
use App\Http\Controllers\App\CashRegisterController;
use App\Http\Controllers\App\CommissionController;
use App\Http\Controllers\App\CustomerController;
use App\Http\Controllers\App\DashboardController;
use App\Http\Controllers\App\PaymentController;
use App\Http\Controllers\App\ReportController;
use App\Http\Controllers\App\ServiceController;
use App\Http\Controllers\App\SettingsController;
use App\Http\Controllers\App\TeamController;
use App\Http\Controllers\App\TenantController;
use App\Http\Controllers\Auth\PasswordSetupController;
use App\Http\Controllers\PublicBookingController;
use App\Http\Middleware\SetPublicBookingTenant;
use App\Http\Middleware\SetTenantDatabaseContext;
use App\Http\Middleware\SetUserDatabaseContext;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return auth()->check()
        ? redirect()->route('app.dashboard')
        : redirect()->route('login');
})->name('home');

Route::middleware(['throttle:api', SetPublicBookingTenant::class])
    ->prefix('agendar/{tenant}')
    ->name('booking.')
    ->group(function (): void {
        Route::get('/', [PublicBookingController::class, 'show'])->name('show');
        Route::get('/horarios', [PublicBookingController::class, 'availability'])->name('availability');
        Route::post('/', [PublicBookingController::class, 'store'])->name('store');
    });

// Primeiro acesso: troca obrigatória da senha provisória.
Route::middleware('auth')->group(function (): void {
    Route::get('/app/definir-senha', [PasswordSetupController::class, 'create'])->name('password.setup');
    Route::post('/app/definir-senha', [PasswordSetupController::class, 'store'])->name('password.setup.store');
});

Route::middleware(['auth', 'password.set'])->group(function (): void {
    /*
     | `auth` fica no grupo externo como rede de segurança da superfície /app e
     | /admin inteira; o grupo interno acrescenta a resolução do tenant, que só
     | as rotas que operam numa barbearia precisam.
     |
     | O grupo `authenticated` já traz o par de middlewares de banco, em volta do
     | `ResolveTenant`: o usuário entra antes (o resolver consulta `memberships`
     | para decidir a barbearia) e o tenant depois (só existe após a resolução).
     | Eles NÃO entram aqui de novo — cada um duas vezes abriria duas transações
     | aninhadas e o `TenantContext` seria lido antes de ser resolvido.
     |
     | `password.set` fica aqui, e não dentro de `authenticated`, porque a troca
     | da senha provisória é justamente uma rota que precisa existir para quem
     | ainda não tem contexto de barbearia. Já a troca de tenant fica dentro do
     | grupo, sem `tenant.selected`: ela exige usuário resolvido, mas precisa
     | funcionar para quem ainda não escolheu.
     */
    Route::middleware('authenticated')->group(function (): void {
        Route::get('/app', [DashboardController::class, 'index'])->name('app.dashboard');

        Route::post('/app/barbearia', [TenantController::class, 'update'])
            ->name('app.tenant.switch');

        Route::middleware(['tenant.selected', 'permission:schedule.view'])->group(function (): void {
            Route::get('/app/agenda', [AgendaController::class, 'index'])->name('app.agenda.index');
            Route::get('/app/notificacoes/agendamentos', [AppointmentNotificationController::class, 'index'])
                ->name('app.appointment-notifications.index');
            Route::patch('/app/notificacoes/agendamentos/{notification}/ler', [
                AppointmentNotificationController::class,
                'markAsRead',
            ])->name('app.appointment-notifications.read');
        });

        Route::middleware(['tenant.selected', 'permission:schedule.manage'])->group(function (): void {
            Route::post('/app/agenda/indisponibilidades', [AgendaController::class, 'block'])
                ->name('app.agenda.blocked-periods.store');
            Route::delete('/app/agenda/indisponibilidades/{blockedPeriod}', [AgendaController::class, 'unblock'])
                ->name('app.agenda.blocked-periods.destroy');
        });

        Route::middleware(['tenant.selected', 'permission:attendance.view'])->group(function (): void {
            Route::get('/app/atendimentos/{attendance}', [AttendanceController::class, 'show'])
                ->name('app.attendances.show');
        });

        Route::middleware(['tenant.selected', 'permission:attendance.manage'])->group(function (): void {
            Route::post('/app/agendamentos/{appointment}/atendimento', [AttendanceController::class, 'open'])
                ->name('app.attendances.open');
            Route::post('/app/atendimentos/{attendance}/itens', [AttendanceController::class, 'addItem'])
                ->name('app.attendances.items.store');
            Route::patch('/app/atendimentos/{attendance}/fechar', [AttendanceController::class, 'close'])
                ->name('app.attendances.close');
        });

        Route::middleware(['tenant.selected', 'permission:cash.view'])->group(function (): void {
            Route::get('/app/caixa', [CashRegisterController::class, 'index'])
                ->name('app.cash.index');
            Route::get('/app/vendas', [PaymentController::class, 'index'])
                ->name('app.payments.index');
        });

        Route::middleware(['tenant.selected', 'permission:commissions.view'])->group(function (): void {
            Route::get('/app/comissoes', [CommissionController::class, 'index'])
                ->name('app.commissions.index');
        });

        Route::middleware(['tenant.selected', 'permission:reports.view'])->group(function (): void {
            Route::get('/app/relatorios/{report}', [ReportController::class, 'show'])
                ->whereIn('report', ['faturamento', 'clientes', 'barbeiros', 'comissoes'])
                ->name('app.reports.show');
            Route::get('/app/relatorios/{report}/exportar/{format}', [ReportController::class, 'export'])
                ->whereIn('report', ['faturamento', 'clientes', 'barbeiros', 'comissoes'])
                ->whereIn('format', ['csv', 'pdf'])
                ->name('app.reports.export');
        });

        Route::middleware(['tenant.selected', 'permission:settings.manage'])->prefix('/app/configuracoes')->name('app.settings.')->group(function (): void {
            Route::get('/minha-barbearia', [SettingsController::class, 'barbershop'])->name('barbershop');
            Route::put('/minha-barbearia', [SettingsController::class, 'updateBarbershop'])->name('barbershop.update');
            Route::get('/permissoes', [SettingsController::class, 'permissions'])->name('permissions');
            Route::get('/horarios', [SettingsController::class, 'hours'])->name('hours');
            Route::get('/pagamentos', [SettingsController::class, 'paymentMethods'])->name('payment-methods');
            Route::put('/pagamentos', [SettingsController::class, 'updatePaymentMethods'])->name('payment-methods.update');
        });

        Route::middleware(['tenant.selected', 'permission:reports.view'])->group(function (): void {
            Route::get('/app/relatorios/{report}', [ReportController::class, 'show'])
                ->whereIn('report', ['faturamento', 'clientes', 'barbeiros', 'comissoes'])
                ->name('app.reports.show');
            Route::get('/app/relatorios/{report}/exportar/{format}', [ReportController::class, 'export'])
                ->whereIn('report', ['faturamento', 'clientes', 'barbeiros', 'comissoes'])
                ->whereIn('format', ['csv', 'pdf'])
                ->name('app.reports.export');
        });

        Route::middleware(['tenant.selected', 'permission:commissions.manage'])->group(function (): void {
            Route::post('/app/comissoes/regras', [CommissionController::class, 'storeRule'])
                ->name('app.commissions.rules.store');
            Route::delete('/app/comissoes/regras/{rule}', [CommissionController::class, 'destroyRule'])
                ->name('app.commissions.rules.destroy');
            Route::post('/app/comissoes/fechamentos', [CommissionController::class, 'closePeriod'])
                ->name('app.commissions.periods.close');
            Route::post('/app/comissoes/fechamentos/{period}/repasses', [CommissionController::class, 'pay'])
                ->name('app.commissions.payments.store');
        });

        Route::middleware(['tenant.selected', 'permission:cash.manage'])->group(function (): void {
            Route::post('/app/atendimentos/{attendance}/pagamentos', [PaymentController::class, 'store'])
                ->name('app.payments.store');
            Route::post('/app/pagamentos/{payment}/estornos', [PaymentController::class, 'refund'])
                ->name('app.payments.refunds.store');
            Route::post('/app/caixa', [CashRegisterController::class, 'open'])
                ->name('app.cash.open');
            Route::post('/app/caixa/{cashRegister}/movimentacoes', [CashRegisterController::class, 'movement'])
                ->name('app.cash.transactions.store');
            Route::patch('/app/caixa/{cashRegister}/fechar', [CashRegisterController::class, 'close'])
                ->name('app.cash.close');
        });

        /*
         | Equipe da barbearia corrente. `tenant.selected` exige que já exista
         | uma escolha, e `permission` resolve pelo Gate no tenant do
         | TenantContext — esconder o link no menu não substitui esta checagem.
         |
         | O `show` não repete o `permission:users.view`: a MembershipPolicy
         | já verifica a permissão *e* a pertinência ao tenant. Duplicar aqui
         | daria ao controller duas fontes de verdade para a mesma pergunta.
         */
        Route::get('/app/equipe', [TeamController::class, 'index'])
            ->middleware(['tenant.selected', 'permission:users.view'])
            ->name('app.team.index');

        Route::middleware(['tenant.selected', 'permission:users.manage'])->group(function (): void {
            Route::get('/app/equipe/novo', [TeamController::class, 'create'])->name('app.team.create');
            Route::post('/app/equipe', [TeamController::class, 'store'])->name('app.team.store');
        });

        Route::get('/app/equipe/{membership}', [TeamController::class, 'show'])
            ->middleware('tenant.selected')
            ->name('app.team.show');

        Route::middleware('tenant.selected')
            ->prefix('app/clientes')
            ->name('app.customers.')
            ->group(function (): void {
                Route::get('/', [CustomerController::class, 'index'])
                    ->middleware('permission:customers.view')
                    ->name('index');
                Route::get('/novo', [CustomerController::class, 'create'])
                    ->middleware('permission:customers.create')
                    ->name('create');
                Route::post('/', [CustomerController::class, 'store'])
                    ->middleware('permission:customers.create')
                    ->name('store');
                Route::get('/{customer}/editar', [CustomerController::class, 'edit'])
                    ->middleware('permission:customers.update')
                    ->name('edit');
                Route::get('/{customer}', [CustomerController::class, 'show'])
                    ->middleware('permission:customers.view')
                    ->name('show');
                Route::put('/{customer}', [CustomerController::class, 'update'])
                    ->middleware('permission:customers.update')
                    ->name('update');
                Route::patch('/{customer}', [CustomerController::class, 'update'])
                    ->middleware('permission:customers.update')
                    ->name('update.patch');
                Route::delete('/{customer}', [CustomerController::class, 'destroy'])
                    ->middleware('permission:customers.delete')
                    ->name('destroy');
            });

        Route::middleware('tenant.selected')
            ->prefix('app/barbeiros')
            ->name('app.barbers.')
            ->group(function (): void {
                Route::get('/', [BarberController::class, 'index'])
                    ->middleware('permission:barbers.view')
                    ->name('index');
                Route::get('/novo', [BarberController::class, 'create'])
                    ->middleware('permission:barbers.create')
                    ->name('create');
                Route::post('/', [BarberController::class, 'store'])
                    ->middleware('permission:barbers.create')
                    ->name('store');
                Route::get('/{barber}/editar', [BarberController::class, 'edit'])
                    ->middleware('permission:barbers.update')
                    ->name('edit');
                Route::get('/{barber}', [BarberController::class, 'show'])
                    ->middleware('permission:barbers.view')
                    ->name('show');
                Route::put('/{barber}', [BarberController::class, 'update'])
                    ->middleware('permission:barbers.update')
                    ->name('update');
                Route::patch('/{barber}', [BarberController::class, 'update'])
                    ->middleware('permission:barbers.update')
                    ->name('update.patch');
                Route::delete('/{barber}', [BarberController::class, 'destroy'])
                    ->middleware('permission:barbers.delete')
                    ->name('destroy');
            });

        Route::middleware('tenant.selected')
            ->prefix('app/servicos')
            ->name('app.services.')
            ->group(function (): void {
                Route::get('/', [ServiceController::class, 'index'])
                    ->middleware('permission:services.view')
                    ->name('index');
                Route::get('/novo', [ServiceController::class, 'create'])
                    ->middleware('permission:services.create')
                    ->name('create');
                Route::post('/', [ServiceController::class, 'store'])
                    ->middleware('permission:services.create')
                    ->name('store');
                Route::get('/{service}/editar', [ServiceController::class, 'edit'])
                    ->middleware('permission:services.update')
                    ->name('edit');
                Route::get('/{service}', [ServiceController::class, 'show'])
                    ->middleware('permission:services.view')
                    ->name('show');
                Route::put('/{service}', [ServiceController::class, 'update'])
                    ->middleware('permission:services.update')
                    ->name('update');
                Route::patch('/{service}', [ServiceController::class, 'update'])
                    ->middleware('permission:services.update')
                    ->name('update.patch');
                Route::delete('/{service}', [ServiceController::class, 'destroy'])
                    ->middleware('permission:services.delete')
                    ->name('destroy');
            });
    });

    /*
     | Painel do superadmin: único lugar onde nascem as barbearias.
     | Não existe registro público, então quem cria uma barbearia
     | e o usuário responsável é o próprio superadmin.
     |
     | `/admin` precisa do mesmo par de middlewares que o grupo de cima, porque
     | `RegisterBarbershop` cria a membership do owner. Sem o contexto de banco, a
     | policy de INSERT recusaria a linha (`new row violates row-level security
     | policy`) e não haveria como cadastrar uma barbearia. Aqui não há
     | `ResolveTenant` — superadmin não pertence a barbearia nenhuma —, então
     | entra o par completo com o tenant vazio, que é a única combinação que a
     | policy aceita para ele.
     */
    Route::middleware([
        'auth',
        'superadmin',
        SetUserDatabaseContext::class,
        SetTenantDatabaseContext::class,
    ])
        ->prefix('admin')
        ->name('admin.')
        ->group(function (): void {
            Route::get('/barbearias', [BarbershopController::class, 'index'])->name('barbershops.index');
            Route::get('/barbearias/nova', [BarbershopController::class, 'create'])->name('barbershops.create');
            Route::post('/barbearias', [BarbershopController::class, 'store'])->name('barbershops.store');
        });
});
