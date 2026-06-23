<?php

namespace App\Providers;

use Illuminate\Pagination\Paginator;
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
        Paginator::useTailwind();

        View::composer('layouts.app', function ($view) {
            try {
                $user = auth()->user();
                $storeQuery = \App\Models\Store::orderBy('name');
                if ($user && !$user->is_super_admin) {
                    $storeQuery->whereHas('users', fn ($q) => $q->where('user_id', $user->id));
                }
                $view->with([
                    'activeStore' => \App\Models\Store::getActive(),
                    'allStores'   => $storeQuery->get(),
                ]);
            } catch (\Throwable) {
                $view->with(['activeStore' => null, 'allStores' => collect()]);
            }
        });
    }
}
