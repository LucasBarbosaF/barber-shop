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
use App\Http\Controllers\App\RecurrenceController;
use App\Http\Controllers\App\ReportController;
use App\Http\Controllers\App\ServiceController;
use App\Http\Controllers\App\SettingsController;
use App\Http\Controllers\App\TeamController;
use App\Http\Controllers\App\TenantController;
use App\Http\Controllers\Auth\PasswordSetupController;
use App\Http\Controllers\PublicBookingController;
use App\Http\Controllers\PublicRecurrenceController;
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

        /*
         | Recorrência no site: o cliente escolhe um plano ativo, marca o corte
         | de abertura e recebe o link "minha recorrência" (uuid público, sem
         | PII na URL) para acompanhar a cota e agendar os próximos cortes.
         |
         | Os ids entram como string e o controller resolve o model depois do
         | `SetPublicBookingTenant`: route model binding roda antes do middleware
         | e o RLS esconderia a linha (mesma razão de `PublicBookingController`).
         */
        Route::get('/planos', [PublicRecurrenceController::class, 'plans'])->name('plans');
        Route::get('/planos/{planId}', [PublicRecurrenceController::class, 'show'])->name('plans.show');
        Route::post('/planos/{planId}/assinar', [PublicRecurrenceController::class, 'subscribe'])->name('plans.subscribe');
        Route::get('/recorrencia/{subscriptionPublicId}', [PublicRecurrenceController::class, 'manage'])
            ->name('subscription.manage');
        Route::get('/recorrencia/{subscriptionPublicId}/horarios', [PublicRecurrenceController::class, 'availability'])
            ->name('subscription.availability');
        Route::post('/recorrencia/{subscriptionPublicId}/cortes', [PublicRecurrenceController::class, 'storeCut'])
            ->name('subscription.cuts.store');
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
            Route::patch('/app/agendamentos/{appointment}/status', [AgendaController::class, 'updateStatus'])
                ->name('app.agenda.appointments.status');
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

        /*
         | Recorrência: catálogo de planos (o que a barbearia vende) e
         | assinaturas (o que o cliente tem). Ler exige `recurrence.view`;
         | a mutação do catálogo é `recurrence.create/update/delete` e o ciclo
         | de vida da assinatura (criar, ativar, agendar corte, cancelar) é
         | `recurrence.manage` — a recepção opera sem poder alterar o catálogo.
         */
        Route::middleware('tenant.selected')
            ->prefix('app/recorrencia')
            ->name('app.recurrence.')
            ->group(function (): void {
                Route::middleware('permission:recurrence.view')->group(function (): void {
                    Route::get('/', [RecurrenceController::class, 'index'])->name('index');
                    Route::get('/assinaturas/{subscription}', [RecurrenceController::class, 'showSubscription'])
                        ->name('subscriptions.show');
                });

                Route::middleware('permission:recurrence.create')->group(function (): void {
                    Route::get('/novo', [RecurrenceController::class, 'create'])->name('create');
                    Route::post('/', [RecurrenceController::class, 'store'])->name('store');
                });

                Route::middleware('permission:recurrence.update')->group(function (): void {
                    Route::get('/{plan}/editar', [RecurrenceController::class, 'edit'])->name('edit');
                    Route::put('/{plan}', [RecurrenceController::class, 'update'])->name('update');
                });

                Route::middleware('permission:recurrence.delete')->group(function (): void {
                    Route::delete('/{plan}', [RecurrenceController::class, 'destroy'])->name('destroy');
                });

                Route::middleware('permission:recurrence.manage')->group(function (): void {
                    Route::post('/assinaturas', [RecurrenceController::class, 'storeSubscription'])
                        ->name('subscriptions.store');
                    Route::post('/assinaturas/{subscription}/pagamento', [RecurrenceController::class, 'activate'])
                        ->name('subscriptions.activate');
                    Route::post('/assinaturas/{subscription}/caixa', [RecurrenceController::class, 'storeCash'])
                        ->name('subscriptions.cash');
                    Route::post('/assinaturas/{subscription}/pagamentos', [RecurrenceController::class, 'storePayment'])
                        ->name('subscriptions.payments.store');
                    Route::post('/assinaturas/{subscription}/cortes', [RecurrenceController::class, 'storeCut'])
                        ->name('subscriptions.cuts.store');
                    Route::post('/assinaturas/{subscription}/cancelar', [RecurrenceController::class, 'cancel'])
                        ->name('subscriptions.cancel');
                });
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
