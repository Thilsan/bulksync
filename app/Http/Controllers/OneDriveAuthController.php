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
        session(['onedrive_oauth_state' => $state]);

        $authUrl = 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize?' . http_build_query([
            'client_id'     => $clientId,
            'response_type' => 'code',
            'redirect_uri'  => route('onedrive.auth.callback'),
            'scope'         => self::SCOPES,
            'state'         => $state,
            'prompt'        => 'select_account',
        ]);

        return redirect($authUrl);
    }

    public function callback(Request $request)
    {
        if ($request->state !== session('onedrive_oauth_state')) {
            return redirect()->route('settings.index')
                ->withErrors(['OAuth state mismatch. Please try again.']);
        }

        if ($request->has('error')) {
            return redirect()->route('settings.index')
                ->withErrors(['OneDrive login failed: ' . $request->error_description]);
        }

        $response = Http::asForm()->post(
            'https://login.microsoftonline.com/common/oauth2/v2.0/token',
            [
                'client_id'     => Setting::get('onedrive_client_id'),
                'client_secret' => Setting::get('onedrive_client_secret'),
                'code'          => $request->code,
                'redirect_uri'  => route('onedrive.auth.callback'),
                'grant_type'    => 'authorization_code',
            ]
        );

        if (!$response->successful() || !$response->json('access_token')) {
            return redirect()->route('settings.index')
                ->withErrors(['Failed to get OneDrive access token. Check your Client Secret.']);
        }

        $data = $response->json();

        Setting::set('onedrive_access_token',  $data['access_token']);
        Setting::set('onedrive_refresh_token', $data['refresh_token'] ?? '');
        Setting::set('onedrive_token_expiry',  (string) (time() + ($data['expires_in'] ?? 3600)));

        session()->forget('onedrive_oauth_state');

        return redirect()->route('settings.index')
            ->with('success', 'OneDrive connected successfully!');
    }
}
