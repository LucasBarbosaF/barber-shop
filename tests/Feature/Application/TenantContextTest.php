<?php

use App\Application\Shared\Tenancy\TenantContext;
use App\Infrastructure\Shared\Tenancy\SessionTenantContext;

it('resolves the application health check', function () {
    $this->get('/up')->assertOk();
});

it('boots the application with tenant context binding', function () {
    $context = app(TenantContext::class);

    expect($context)->toBeInstanceOf(SessionTenantContext::class);
    expect($context->hasTenant())->toBeFalse();
});

it('stores and clears tenant context', function () {
    $context = app(TenantContext::class);
    $context->set('tenant-uuid-123');

    expect($context->id())->toBe('tenant-uuid-123');
    expect($context->hasTenant())->toBeTrue();

    $context->clear();

    expect($context->id())->toBeNull();
    expect($context->hasTenant())->toBeFalse();
});
