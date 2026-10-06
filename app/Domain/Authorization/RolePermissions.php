<?php

namespace App\Domain\Authorization;

use App\Domain\Authorization\Enums\Permission;
use App\Domain\Tenant\Enums\MembershipRole;

/**
 * Matriz role → permissions.
 *
 * Fonte única da verdade, por escolha de design: o catálogo NÃO vive no banco.
 * Consequências aceitas:
 * - adicionar ou remover uma permission exige deploy, não migration;
 * - a matriz é auditável por leitura e por teste, sem join.
 *
 * Invariante testada em tests/Unit/Domain/Authorization/RolePermissionsTest.php:
 * todo Permission precisa estar concedido a pelo menos uma role, e nenhuma role
 * pode conceder uma permission fora do enum.
 */
final class RolePermissions
{
    /**
     * @return array<int, Permission>
     */
    public static function for(MembershipRole $role): array
    {
        return match ($role) {
            MembershipRole::Admin => self::all(),
            MembershipRole::Manager => self::of([
                Permission::DashboardView,
                Permission::CustomersView,
                Permission::CustomersCreate,
                Permission::CustomersUpdate,
                Permission::CustomersDelete,
                Permission::BarbersView,
                Permission::BarbersCreate,
                Permission::BarbersUpdate,
                Permission::BarbersDelete,
                Permission::ServicesView,
                Permission::ServicesCreate,
                Permission::ServicesUpdate,
                Permission::ServicesDelete,
                Permission::ProductsView,
                Permission::ProductsCreate,
                Permission::ProductsUpdate,
                Permission::ProductsDelete,
                Permission::StockManage,
                Permission::ScheduleView,
                Permission::ScheduleManage,
                Permission::AttendanceView,
                Permission::AttendanceManage,
                Permission::FinanceView,
                Permission::CashView,
                Permission::CashManage,
                Permission::CommissionsView,
                Permission::CommissionsManage,
                Permission::ReportsView,
                Permission::SubscriptionView,
                Permission::UsersView,
                Permission::SettingsManage,
                Permission::AuditView,
            ]),
            MembershipRole::Supervisor => self::of([
                Permission::DashboardView,
                Permission::CustomersView,
                Permission::CustomersCreate,
                Permission::CustomersUpdate,
                Permission::CustomersDelete,
                Permission::BarbersView,
                Permission::BarbersCreate,
                Permission::BarbersUpdate,
                Permission::BarbersDelete,
                Permission::ServicesView,
                Permission::ServicesCreate,
                Permission::ServicesUpdate,
                Permission::ServicesDelete,
                Permission::ProductsView,
                Permission::ProductsCreate,
                Permission::ProductsUpdate,
                Permission::ProductsDelete,
                Permission::StockManage,
                Permission::ScheduleView,
                Permission::ScheduleManage,
                Permission::AttendanceView,
                Permission::AttendanceManage,
                Permission::ReportsView,
                Permission::UsersView,
            ]),
            MembershipRole::Barber => self::of([
                Permission::DashboardView,
                Permission::CustomersView,
                Permission::CustomersCreate,
                Permission::BarbersView,
                Permission::ServicesView,
                Permission::ScheduleView,
                Permission::ScheduleManage,
                Permission::AttendanceView,
                Permission::AttendanceManage,
            ]),
            MembershipRole::Receptionist => self::of([
                Permission::DashboardView,
                Permission::CustomersView,
                Permission::CustomersCreate,
                Permission::CustomersUpdate,
                Permission::BarbersView,
                Permission::ServicesView,
                Permission::ScheduleView,
                Permission::ScheduleManage,
                Permission::AttendanceView,
            ]),
            MembershipRole::Financeiro => self::of([
                Permission::DashboardView,
                Permission::CustomersView,
                Permission::ProductsView,
                Permission::FinanceView,
                Permission::CashView,
                Permission::CashManage,
                Permission::CommissionsView,
                Permission::CommissionsManage,
                Permission::ReportsView,
                Permission::SubscriptionView,
            ]),
        };
    }

    /**
     * @return array<int, Permission>
     */
    private static function all(): array
    {
        return Permission::cases();
    }

    /**
     * @param  array<int, Permission>  $permissions
     * @return array<int, Permission>
     */
    private static function of(array $permissions): array
    {
        return array_values($permissions);
    }
}
