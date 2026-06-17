<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class OneDriveAuthController extends Controller
{
    private const SCOPES = 'Files.Read offline_access User.Read';

    public function redirect()
    {
        $clientId = Setting::get('onedrive_client_id');

        if (!$clientId) {
            return redirect()->route('settings.index')
                ->withErrors(['Save your OneDrive Client ID first, then try connecting.']);
        }

        $state = Str::random(40);
        Setting::set('onedrive_oauth_state', $state);

        $tenantId = Setting::get('onedrive_tenant_id') ?: 'common';
        $authUrl = "https://login.microsoftonline.com/{$tenantId}/oauth2/v2.0/authorize?" . http_build_query([
            'client_id'     => $clientId,
            'response_type' => 'code',
            'redirect_uri'  => str_replace('http://127.0.0.1', 'http://localhost', route('onedrive.auth.callback')),
            'scope'         => self::SCOPES,
            'state'         => $state,
            'prompt'        => 'select_account',
        ]);

        return redirect($authUrl);
    }

    public function callback(Request $request)
    {
        if ($request->state !== Setting::get('onedrive_oauth_state')) {
            return redirect()->route('settings.index')
                ->withErrors(['OAuth state mismatch. Please try again.']);
        }

        if ($request->has('error')) {
            return redirect()->route('settings.index')
                ->withErrors(['OneDrive login failed: ' . $request->error_description]);
        }

        $tenantId = Setting::get('onedrive_tenant_id') ?: 'common';
        $response = Http::asForm()->post(
            "https://login.microsoftonline.com/{$tenantId}/oauth2/v2.0/token",
            [
                'client_id'     => Setting::get('onedrive_client_id'),
                'client_secret' => Setting::get('onedrive_client_secret'),
                'code'          => $request->code,
                'redirect_uri'  => str_replace('http://127.0.0.1', 'http://localhost', route('onedrive.auth.callback')),
                'grant_type'    => 'authorization_code',
            ]
        );

        if (!$response->successful() || !$response->json('access_token')) {
            $error = $response->json('error_description') ?? $response->json('error') ?? 'Unknown error';
            return redirect()->route('settings.index')
                ->withErrors(["OneDrive token error: {$error}"]);
        }

        $data = $response->json();

        auth()->user()->update([
            'onedrive_access_token'  => $data['access_token'],
            'onedrive_refresh_token' => $data['refresh_token'] ?? '',
            'onedrive_token_expiry'  => (string) (time() + ($data['expires_in'] ?? 3600)),
        ]);

        Setting::set('onedrive_oauth_state', null);

        return redirect()->route('settings.index')
            ->with('success', 'OneDrive connected successfully!');
    }
}
