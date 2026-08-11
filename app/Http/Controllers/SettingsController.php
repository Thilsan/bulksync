<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Services\MailConfigurator;
use App\Services\OneDriveService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\View\View;

class SettingsController extends Controller
{
    public function index(): View
    {
        $user = Auth::user();

        $settings = [
            'onedrive_tenant_id'     => Setting::get('onedrive_tenant_id'),
            'onedrive_client_id'     => Setting::get('onedrive_client_id'),
            'onedrive_client_secret' => Setting::get('onedrive_client_secret'),
            // The refresh token is what makes a connection last. An access token
            // is good for an hour, so judging by that reported "connected" for
            // months after the connection had actually died.
            'onedrive_connected'     => !empty($user->onedrive_refresh_token),
            'onedrive_expires_at'    => $user->onedrive_token_expiry
                ? \Illuminate\Support\Carbon::createFromTimestamp((int) $user->onedrive_token_expiry)
                : null,
            'onedrive_stale'         => !empty($user->onedrive_access_token) && empty($user->onedrive_refresh_token),
        ];

        $mail = collect(MailConfigurator::KEYS)
            ->mapWithKeys(fn ($key) => [$key => Setting::get($key)])
            ->all();

        // Never render the stored password back into the page.
        $mail['mail_password_set'] = filled($mail['mail_password']);
        unset($mail['mail_password']);

        return view('settings.index', [
            'settings'  => $settings,
            'mail'      => $mail,
            'mailState' => MailConfigurator::effective(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        if (!Auth::user()->is_super_admin) {
            abort(403);
        }

        $validated = $request->validate([
            'onedrive_tenant_id'     => ['nullable', 'string', 'max:255'],
            'onedrive_client_id'     => ['nullable', 'string', 'max:255'],
            'onedrive_client_secret' => ['nullable', 'string', 'max:500'],
        ]);

        foreach ($validated as $key => $value) {
            Setting::set($key, $value ?: null);
        }

        return back()->with('success', 'Settings saved successfully.');
    }

    public function updateMail(Request $request): RedirectResponse
    {
        if (!Auth::user()->is_super_admin) {
            abort(403);
        }

        $validated = $request->validate([
            'mail_enabled'      => ['nullable', 'boolean'],
            'mail_host'         => ['nullable', 'string', 'max:255'],
            'mail_port'         => ['nullable', 'integer', 'min:1', 'max:65535'],
            'mail_username'     => ['nullable', 'string', 'max:255'],
            'mail_password'     => ['nullable', 'string', 'max:500'],
            'mail_scheme'       => ['nullable', 'in:auto,smtps'],
            'mail_from_address' => ['nullable', 'email', 'max:255'],
            'mail_from_name'    => ['nullable', 'string', 'max:255'],
        ]);

        if ($request->boolean('mail_enabled') && blank($validated['mail_host'] ?? null)) {
            return back()->withErrors(['mail_host' => 'An SMTP host is required to send mail from these settings.']);
        }

        Setting::set('mail_enabled', $request->boolean('mail_enabled') ? '1' : null);

        foreach (['mail_host', 'mail_port', 'mail_username', 'mail_scheme', 'mail_from_address', 'mail_from_name'] as $key) {
            Setting::set($key, $validated[$key] ?? null);
        }

        // Blank means "keep the stored password", so a save doesn't wipe it just
        // because the field is never pre-filled.
        if (filled($validated['mail_password'] ?? null)) {
            Setting::set('mail_password', $validated['mail_password']);
        }

        MailConfigurator::flush();
        MailConfigurator::apply();

        return back()->with('success', 'Mail settings saved. Send a test email to confirm they work.');
    }

    public function testMail(Request $request): RedirectResponse
    {
        if (!Auth::user()->is_super_admin) {
            abort(403);
        }

        $data = $request->validate(['test_email' => ['required', 'email']]);

        MailConfigurator::flush();
        MailConfigurator::apply();

        if (config('mail.default') === 'log') {
            return back()->with('warning',
                'Mail is set to the "log" driver, so nothing was sent. Enable the SMTP settings above, or set MAIL_MAILER in .env.');
        }

        try {
            // Sent immediately rather than queued: the point is to see the result now.
            Mail::raw(
                "This is a test email from AI E-Commerce Studio.

"
                . 'Sent ' . now()->format('D d M Y, H:i') . ' by ' . Auth::user()->name . '.',
                fn ($message) => $message->to($data['test_email'])->subject('AI E-Commerce Studio — SMTP test')
            );

            return back()->with('success', "Test email sent to {$data['test_email']}. If it does not arrive, check the spam folder and your SPF/DKIM records.");
        } catch (\Throwable $e) {
            report($e);

            return back()->withErrors([
                'test_email' => 'Sending failed: ' . $e->getMessage(),
            ]);
        }
    }

    public function testOnedrive(): \Illuminate\Http\JsonResponse
    {
        try {
            app(OneDriveService::class)->checkConnection();

            return response()->json(['ok' => true, 'message' => 'OneDrive connected!']);
        } catch (\Throwable $e) {
            // Microsoft's own wording, trimmed to something readable on screen.
            return response()->json([
                'ok'      => false,
                'message' => \Illuminate\Support\Str::limit($e->getMessage(), 400),
            ]);
        }
    }

    /**
     * Can the server reach Gemini? Answers the question that a session stuck on
     * 0% cannot: is this the key, or is it outbound network access.
     */
    public function testGemini(): \Illuminate\Http\JsonResponse
    {
        $result = app(\App\Services\GeminiService::class)->ping();

        return response()->json(['ok' => $result['ok'], 'message' => $result['message']]);
    }

    public function clearCache(): RedirectResponse
    {
        if (!Auth::user()->is_super_admin) {
            abort(403);
        }

        \Illuminate\Support\Facades\Artisan::call('optimize:clear');

        return back()->with('success', 'Cache cleared successfully.');
    }
}
