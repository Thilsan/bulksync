<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;

/**
 * Lets the SMTP details be managed in Settings instead of the server's .env.
 *
 * Editing .env means SSH access, and getting it wrong silently stops every
 * notification in the module. These values are applied over config/mail.php at
 * boot, so a super admin can change them from the UI and see a test result
 * immediately.
 *
 * .env stays the fallback: leave this switched off and nothing is overridden.
 */
class MailConfigurator
{
    public const KEYS = [
        'mail_enabled',
        'mail_host',
        'mail_port',
        'mail_username',
        'mail_password',
        'mail_scheme',
        'mail_from_address',
        'mail_from_name',
    ];

    private const CACHE_KEY = 'mail_settings';

    /**
     * Read the stored settings. Cached because this runs on every request and
     * every queued job — and flushed the moment they are saved, so a worker
     * never keeps sending with credentials that have been replaced.
     */
    public static function settings(): array
    {
        try {
            return Cache::remember(self::CACHE_KEY, now()->addHour(), function () {
                $rows = Setting::whereIn('key', self::KEYS)->pluck('value', 'key')->all();

                return collect(self::KEYS)
                    ->mapWithKeys(fn ($key) => [$key => $rows[$key] ?? null])
                    ->all();
            });
        } catch (\Throwable) {
            // Before migrations run there is no settings table — fall back to .env.
            return array_fill_keys(self::KEYS, null);
        }
    }

    public static function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    public static function isEnabled(): bool
    {
        $settings = self::settings();

        return (bool) ($settings['mail_enabled'] ?? false) && filled($settings['mail_host'] ?? null);
    }

    /** Push the stored values over the mail config for this process. */
    public static function apply(): void
    {
        if (!self::isEnabled()) {
            return;
        }

        $s = self::settings();

        Config::set('mail.default', 'smtp');
        Config::set('mail.mailers.smtp.transport', 'smtp');
        Config::set('mail.mailers.smtp.host', $s['mail_host']);
        Config::set('mail.mailers.smtp.port', (int) ($s['mail_port'] ?: 587));
        Config::set('mail.mailers.smtp.username', $s['mail_username'] ?: null);
        Config::set('mail.mailers.smtp.password', $s['mail_password'] ?: null);

        // Null scheme lets Symfony negotiate STARTTLS, which is what port 587
        // and 2525 want. Only 465 needs implicit TLS spelled out.
        Config::set('mail.mailers.smtp.scheme', $s['mail_scheme'] === 'smtps' ? 'smtps' : null);

        if (filled($s['mail_from_address'] ?? null)) {
            Config::set('mail.from.address', $s['mail_from_address']);
        }

        if (filled($s['mail_from_name'] ?? null)) {
            Config::set('mail.from.name', $s['mail_from_name']);
        }
    }

    /** What the app will actually use, whatever the source. */
    public static function effective(): array
    {
        return [
            'source'  => self::isEnabled() ? 'Settings' : 'Server .env',
            'mailer'  => config('mail.default'),
            'host'    => config('mail.mailers.smtp.host'),
            'port'    => config('mail.mailers.smtp.port'),
            'from'    => config('mail.from.address'),
            'name'    => config('mail.from.name'),
            'sending' => config('mail.default') !== 'log',
        ];
    }
}
