<?php

namespace App\Providers;

use App\Models\Tenant;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        /**
         * Força o protocolo HTTPS
         */
        if(env('APP_FORCE_HTTPS') == true){
            URL::forceScheme('https');
        }

        /**
         * Alimenta o modal global de atualização de sistemas com os tenants
         * dedicados que podem ser escolhidos no fluxo individual.
         */
        View::composer('layouts.app', function ($view) {
            $updateSystemDedicatedTenants = Tenant::where('type_installation', 'dedicated')
                ->where('status', true)
                ->orderBy('name')
                ->get(['id', 'name', 'email']);

            $view->with('updateSystemDedicatedTenants', $updateSystemDedicatedTenants);
        });
    }
}
