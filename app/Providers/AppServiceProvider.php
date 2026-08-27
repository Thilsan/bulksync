<?php

namespace App\Providers;

use App\Listeners\BccEveryMessage;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Event;
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

        // Every outgoing message is blind-copied to config('mail.bcc'). Hooked
        // here rather than on each Mailable: one forgotten ->bcc() would be
        // invisible, because nobody notices a copy that never arrives.
        Event::listen(MessageSending::class, BccEveryMessage::class);

        // ...and again before every queued job. A queue worker is a long-lived
        // process: it applied the config once at boot, so settings saved after
        // it started were invisible to it. Notifications would then see
        // mail.default = "log", skip the mail channel entirely, and deliver
        // in-app only — silently, with nothing failed to look at.
        Queue::before(fn () => \App\Services\MailConfigurator::apply());

        // Queue history. Laravel remembers what failed and what is still waiting,
        // but nothing about what ran and finished — so "what happened overnight,
        // and how long did it take" had no answer, and a job in progress was
        // invisible. The recorder never throws; see the class for why that matters.
        Queue::before([\App\Support\JobRunRecorder::class, 'starting']);
        Queue::after([\App\Support\JobRunRecorder::class, 'finished']);
        Queue::failing([\App\Support\JobRunRecorder::class, 'failed']);

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
                    // Unread, and only what is actually this person's — a whole
                    // team is told when a request moves, and a bell that rings for
                    // all of it stops meaning anything. The team's updates are
                    // still on the notifications page under "Everything".
                    $notifications = $user->unreadOwnNotifications()->latest()->limit(8)->get();
                    $unreadCount   = $user->unreadOwnNotifications()->count();
                }

                $view->with([
                    'activeStore'       => \App\Models\Store::getActive(),
                    'allStores'         => $storeQuery->get(),
                    'bellNotifications' => $notifications,
                    'bellUnreadCount'   => $unreadCount,
                    // Waiting chat messages, read from the chat cache store. Only
                    // for pages that already have a user; costs one small file
                    // read per peer and no queries beyond the user list.
                    'chatUnreadCount'   => $user ? \App\Support\EphemeralChat::totalUnread($user->id) : 0,
                ]);
            } catch (\Throwable) {
                $view->with([
                    'activeStore'       => null,
                    'allStores'         => collect(),
                    'bellNotifications' => collect(),
                    'bellUnreadCount'   => 0,
                    'chatUnreadCount'   => 0,
                ]);
            }
        });
    }
}
