<?php

namespace App\Providers;

use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        View::composer('layouts.app', function ($view) {
            try {
                $view->with([
                    'activeStore' => \App\Models\Store::getActive(),
                    'allStores'   => \App\Models\Store::orderBy('name')->get(),
                ]);
            } catch (\Throwable) {
                $view->with(['activeStore' => null, 'allStores' => collect()]);
            }
        });
    }
}
