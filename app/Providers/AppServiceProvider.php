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
                // Top-bar bell. Only queried for users who can see the module,
                // and capped at 8 rows so this stays cheap on every page load.
                $notifications = collect();
                $unreadCount   = 0;

                if ($user && $user->hasFeature('product_request')) {
                    // Unread only: the bell is a to-read list, not history.
                    // Everything ever sent lives on the notifications page.
                    $notifications = $user->unreadNotifications()->latest()->limit(8)->get();
                    $unreadCount   = $user->unreadNotifications()->count();
                }

                $view->with([
                    'activeStore'       => \App\Models\Store::getActive(),
                    'allStores'         => $storeQuery->get(),
                    'bellNotifications' => $notifications,
                    'bellUnreadCount'   => $unreadCount,
                ]);
            } catch (\Throwable) {
                $view->with([
                    'activeStore'       => null,
                    'allStores'         => collect(),
                    'bellNotifications' => collect(),
                    'bellUnreadCount'   => 0,
                ]);
            }
        });
    }
}
