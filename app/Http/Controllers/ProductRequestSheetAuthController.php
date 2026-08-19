<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Services\OneDriveService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Signs the Product Creation Request automation into its own Azure app.
 *
 * Separate from the shared OneDrive connection in every way: its own app
 * registration, its own consent, and a token stored against the app rather than
 * against a user. The person who owns the tracking sheet signs in once here and
 * needs no further involvement — and nothing about this touches the shared
 * credentials the photo editor and bulk upload run on.
 */
class ProductRequestSheetAuthController extends Controller
{
    private const PROFILE = OneDriveService::PRODUCT_REQUEST_PROFILE;

    public function redirect(): RedirectResponse
    {
        abort_unless(auth()->user()?->is_super_admin, 403, 'Only a super admin can connect the tracking sheet account.');

        $clientId = Setting::get(self::PROFILE . '_client_id');

        if (!$clientId) {
            return redirect()->route('settings.index')
                ->withErrors(['Save the tracking sheet app\'s Client ID first, then connect.']);
        }

        $state = Str::random(40);
        Setting::set(self::PROFILE . '_oauth_state', $state);

        $tenantId = Setting::get(self::PROFILE . '_tenant_id') ?: 'common';

        return redirect("https://login.microsoftonline.com/{$tenantId}/oauth2/v2.0/authorize?" . http_build_query([
            'client_id'     => $clientId,
            'response_type' => 'code',
            'redirect_uri'  => $this->callbackUrl(),
            'scope'         => OneDriveService::SCOPES,
            'state'         => $state,
            // Always ask which account: this is signed in as somebody other than
            // the admin doing the clicking, and a silent re-use of their session
            // would quietly connect the wrong person.
            'prompt'        => 'select_account',
        ]));
    }

    public function callback(Request $request): RedirectResponse
    {
        abort_unless(auth()->user()?->is_super_admin, 403);

        if ($request->state !== Setting::get(self::PROFILE . '_oauth_state')) {
            return redirect()->route('settings.index')->withErrors(['OAuth state mismatch. Please try again.']);
        }

        if ($request->has('error')) {
            return redirect()->route('settings.index')
                ->withErrors(['Sign-in failed: ' . $request->error_description]);
        }

        $tenantId = Setting::get(self::PROFILE . '_tenant_id') ?: 'common';

        $response = Http::asForm()->post(
            "https://login.microsoftonline.com/{$tenantId}/oauth2/v2.0/token",
            [
                'client_id'     => Setting::get(self::PROFILE . '_client_id'),
                'client_secret' => Setting::get(self::PROFILE . '_client_secret'),
                'code'          => $request->code,
                'redirect_uri'  => $this->callbackUrl(),
                'grant_type'    => 'authorization_code',
            ]
        );

        if (!$response->successful() || !$response->json('access_token')) {
            $error = $response->json('error_description') ?? $response->json('error') ?? 'Unknown error';
            return redirect()->route('settings.index')->withErrors(["Token error: {$error}"]);
        }

        $data = $response->json();

        Setting::set(self::PROFILE . '_access_token', $data['access_token']);
        Setting::set(self::PROFILE . '_refresh_token', $data['refresh_token'] ?? '');
        Setting::set(self::PROFILE . '_token_expiry', (string) (time() + ($data['expires_in'] ?? 3600)));
        Setting::set(self::PROFILE . '_oauth_state', null);

        // Whose drive this now reads, so the settings screen can say so rather
        // than showing a connection nobody can identify.
        Setting::set(self::PROFILE . '_account', $this->signedInAs($data['access_token']));

        return redirect()->route('settings.index')
            ->with('success', 'The tracking sheet account is connected.');
    }

    public function disconnect(): RedirectResponse
    {
        abort_unless(auth()->user()?->is_super_admin, 403);

        foreach (['access_token', 'refresh_token', 'token_expiry', 'account'] as $key) {
            Setting::set(self::PROFILE . '_' . $key, null);
        }

        return redirect()->route('settings.index')
            ->with('success', 'The tracking sheet account is disconnected. The sync will not run until it is reconnected.');
    }

    /** Best effort — an unidentified connection still works, it just reads worse. */
    private function signedInAs(string $accessToken): ?string
    {
        try {
            $me = Http::withToken($accessToken)->get('https://graph.microsoft.com/v1.0/me')->json();

            return $me['userPrincipalName'] ?? $me['mail'] ?? null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Azure matches this against the app's registered redirect URIs exactly, and
     * rejects 127.0.0.1 where localhost was registered.
     */
    private function callbackUrl(): string
    {
        return str_replace('http://127.0.0.1', 'http://localhost', route('product-request-sheet.auth.callback'));
    }
}
