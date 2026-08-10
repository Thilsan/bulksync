<?php

namespace App\Providers;

use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Queue;
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

        // SMTP details managed in Settings win over config/mail.php.
        \App\Services\MailConfigurator::apply();

        // ...and again before every queued job. A queue worker is a long-lived
        // process: it applied the config once at boot, so settings saved after
        // it started were invisible to it. Notifications would then see
        // mail.default = "log", skip the mail channel entirely, and deliver
        // in-app only — silently, with nothing failed to look at.
        Queue::before(fn () => \App\Services\MailConfigurator::apply());

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
