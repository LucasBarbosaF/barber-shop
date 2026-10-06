<?php

use App\Domain\Authorization\Enums\Permission;
use App\Domain\Authorization\RolePermissions;
use App\Domain\Tenant\Enums\MembershipRole;

it('grants every permission to at least one role', function (Permission $permission): void {
    $grantedTo = array_filter(
        MembershipRole::cases(),
        fn (MembershipRole $role): bool => in_array($permission, RolePermissions::for($role), true),
    );

    expect($grantedTo)->not->toBeEmpty();
})->with(Permission::cases());

it('names every permission as resource.action', function (Permission $permission): void {
    expect($permission->value)->toMatch('/^[a-z]+\.[a-z]+$/')
        ->and($permission->resource())->toBe(explode('.', $permission->value)[0]);
})->with(Permission::cases());

it('gives every permission a distinct label', function (Permission $permission): void {
    $labels = array_map(
        static fn (Permission $case): string => $case->label(),
        Permission::cases(),
    );

    expect($permission->label())->not->toBe('')
        ->and(array_unique($labels))->toHaveCount(count(Permission::cases()));
})->with(Permission::cases());

it('grants the admin every permission', function (): void {
    expect(RolePermissions::for(MembershipRole::Admin))->toEqual(Permission::cases());
});

it('never grants a duplicate permission within a role', function (MembershipRole $role): void {
    $values = array_map(
        static fn (Permission $permission): string => $permission->value,
        RolePermissions::for($role),
    );

    expect(array_unique($values))->toHaveCount(count($values));
})->with(MembershipRole::cases());

it('keeps every non admin role a strict subset of the admin', function (MembershipRole $role): void {
    $values = array_map(
        static fn (Permission $permission): string => $permission->value,
        RolePermissions::for($role),
    );

    $adminValues = array_map(
        static fn (Permission $permission): string => $permission->value,
        RolePermissions::for(MembershipRole::Admin),
    );

    expect(array_diff($values, $adminValues))->toBe([])
        ->and(count($values))->toBeLessThan(count($adminValues));
})->with(array_filter(
    MembershipRole::cases(),
    static fn (MembershipRole $role): bool => $role !== MembershipRole::Admin,
));

it('restricts role management to the admin', function (): void {
    foreach (MembershipRole::cases() as $role) {
        expect($role->canManageRoles())->toBe($role === MembershipRole::Admin);
    }
});

it('never grants users.manage outside the admin', function (): void {
    foreach (MembershipRole::cases() as $role) {
        if ($role === MembershipRole::Admin) {
            expect(RolePermissions::for($role))->toContain(Permission::UsersManage);

            continue;
        }

        expect(RolePermissions::for($role))->not->toContain(Permission::UsersManage);
    }
});

it('separates operational and financial access', function (): void {
    $barber = RolePermissions::for(MembershipRole::Barber);
    $financeiro = RolePermissions::for(MembershipRole::Financeiro);

    // O barbeiro opera a agenda, mas não enxerga o financeiro.
    expect($barber)->toContain(Permission::ScheduleManage)
        ->and($barber)->not->toContain(Permission::FinanceView)
        ->and($barber)->not->toContain(Permission::CashView);

    // O financeiro move o caixa, mas não mexe na agenda nem altera cadastros.
    expect($financeiro)->toContain(Permission::CashManage)
        ->and($financeiro)->not->toContain(Permission::ScheduleManage)
        ->and($financeiro)->not->toContain(Permission::CustomersUpdate)
        ->and($financeiro)->not->toContain(Permission::ProductsCreate);
});

it('gives every role a human readable label', function (MembershipRole $role): void {
    expect($role->label())->not->toBe('')
        ->and(MembershipRole::options())->toHaveKey($role->value, $role->label());
})->with(MembershipRole::cases());
