<?php

namespace App\Providers;

use App\Application\Barbers\Contracts\BarberRepository;
use App\Application\Customers\Contracts\CustomerRepository;
use App\Application\Services\Contracts\ServiceRepository;
use App\Application\Shared\Tenancy\TenantContext;
use App\Domain\Tenant\Models\Tenant;
use App\Http\Responses\LoginResponse;
use App\Infrastructure\Barbers\Persistence\EloquentBarberRepository;
use App\Infrastructure\Customers\Persistence\EloquentCustomerRepository;
use App\Infrastructure\Services\Persistence\EloquentServiceRepository;
use App\Infrastructure\Shared\Tenancy\SessionTenantContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(TenantContext::class, SessionTenantContext::class);
        $this->app->bind(BarberRepository::class, EloquentBarberRepository::class);
        $this->app->bind(CustomerRepository::class, EloquentCustomerRepository::class);
        $this->app->bind(ServiceRepository::class, EloquentServiceRepository::class);

        // Usuários com senha provisória vão direto para a troca obrigatória.
        $this->app->singleton(LoginResponseContract::class, LoginResponse::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        URL::forceScheme('https');
        // O painel é AdminLTE/Bootstrap; a paginação padrão do Laravel renderiza
        // classes Tailwind e sairia fora do card em que os links são exibidos.
        Paginator::useBootstrapFive();

        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        View::composer('*', function ($view): void {
            $request = request();
            if (! $request->attributes->has('current_barbershop_branding')) {
                $publicTenant = $request->attributes->get('public_booking_tenant');
                $tenantId = app(TenantContext::class)->id();
                $tenant = $publicTenant instanceof Tenant
                    ? $publicTenant
                    : ($tenantId === null ? null : Tenant::query()->find($tenantId));
                $request->attributes->set('current_barbershop_branding', $tenant);
            }

            $view->with('currentBarbershop', $request->attributes->get('current_barbershop_branding'));
        });
    }
}
