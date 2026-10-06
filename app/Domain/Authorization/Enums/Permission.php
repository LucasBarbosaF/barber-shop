<?php

namespace App\Domain\Authorization\Enums;

/**
 * Permissão de autorização do sistema.
 *
 * Granularidade: recurso.ação (ex.: `customers.view`, `cash.manage`).
 *
 * A lista é derivada dos módulos obrigatórios do MVP descritos no README. O valor
 * do enum é a chave estável usada nos Gates — nunca a reescreva sem migrar as
 * policies, porque Gates e testes referenciam a string.
 */
enum Permission: string
{
    case DashboardView = 'dashboard.view';

    case CustomersView = 'customers.view';
    case CustomersCreate = 'customers.create';
    case CustomersUpdate = 'customers.update';
    case CustomersDelete = 'customers.delete';

    case BarbersView = 'barbers.view';
    case BarbersCreate = 'barbers.create';
    case BarbersUpdate = 'barbers.update';
    case BarbersDelete = 'barbers.delete';

    case ServicesView = 'services.view';
    case ServicesCreate = 'services.create';
    case ServicesUpdate = 'services.update';
    case ServicesDelete = 'services.delete';

    case ProductsView = 'products.view';
    case ProductsCreate = 'products.create';
    case ProductsUpdate = 'products.update';
    case ProductsDelete = 'products.delete';

    case StockManage = 'stock.manage';

    case ScheduleView = 'schedule.view';
    case ScheduleManage = 'schedule.manage';

    case AttendanceView = 'attendance.view';
    case AttendanceManage = 'attendance.manage';

    case FinanceView = 'finance.view';

    case CashView = 'cash.view';
    case CashManage = 'cash.manage';

    case CommissionsView = 'commissions.view';
    case CommissionsManage = 'commissions.manage';

    case ReportsView = 'reports.view';

    case SubscriptionView = 'subscription.view';
    case SubscriptionManage = 'subscription.manage';

    case UsersView = 'users.view';

    /** Só o admin da barbearia concede e revoga roles — evita privilege escalation. */
    case UsersManage = 'users.manage';

    case SettingsManage = 'settings.manage';

    case AuditView = 'audit.view';

    public function label(): string
    {
        return match ($this) {
            self::DashboardView => 'Ver dashboard',
            self::CustomersView => 'Ver clientes',
            self::CustomersCreate => 'Cadastrar clientes',
            self::CustomersUpdate => 'Editar clientes',
            self::CustomersDelete => 'Excluir clientes',
            self::BarbersView => 'Ver barbeiros',
            self::BarbersCreate => 'Cadastrar barbeiros',
            self::BarbersUpdate => 'Editar barbeiros',
            self::BarbersDelete => 'Excluir barbeiros',
            self::ServicesView => 'Ver serviços',
            self::ServicesCreate => 'Cadastrar serviços',
            self::ServicesUpdate => 'Editar serviços',
            self::ServicesDelete => 'Excluir serviços',
            self::ProductsView => 'Ver produtos',
            self::ProductsCreate => 'Cadastrar produtos',
            self::ProductsUpdate => 'Editar produtos',
            self::ProductsDelete => 'Excluir produtos',
            self::StockManage => 'Movimentar estoque',
            self::ScheduleView => 'Ver agenda',
            self::ScheduleManage => 'Criar, cancelar e remarcar agendamentos',
            self::AttendanceView => 'Ver atendimentos',
            self::AttendanceManage => 'Abrir e fechar atendimentos',
            self::FinanceView => 'Ver financeiro',
            self::CashView => 'Ver caixa',
            self::CashManage => 'Movimentar caixa e sangrias',
            self::CommissionsView => 'Ver comissões',
            self::CommissionsManage => 'Fechar e pagar comissões',
            self::ReportsView => 'Ver relatórios',
            self::SubscriptionView => 'Ver assinatura',
            self::SubscriptionManage => 'Gerenciar assinatura',
            self::UsersView => 'Ver usuários',
            self::UsersManage => 'Gerenciar usuários e roles',
            self::SettingsManage => 'Alterar configurações da barbearia',
            self::AuditView => 'Ver auditoria',
        };
    }

    public function resource(): string
    {
        return explode('.', $this->value, 2)[0];
    }
}
