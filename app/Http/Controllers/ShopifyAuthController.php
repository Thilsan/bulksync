<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class ShopifyAuthController extends Controller
{
    public function redirect()
    {
        $shop     = preg_replace('#^https?://#', '', rtrim(Setting::get('shopify_domain'), '/'));
        $clientId = Setting::get('shopify_client_id');

        if (!$shop || !$clientId) {
            return redirect()->route('settings.index')
                ->withErrors(['Save your Shop Domain and Client ID first, then try connecting.']);
        }

        $state = Str::random(40);
        session(['shopify_oauth_state' => $state]);

        $authUrl = "https://{$shop}/admin/oauth/authorize?" . http_build_query([
            'client_id'    => $clientId,
            'scope'        => 'read_products,write_products',
            'redirect_uri' => route('shopify.auth.callback'),
            'state'        => $state,
        ]);

        return redirect($authUrl);
    }

    public function callback(Request $request)
    {
        if ($request->state !== session('shopify_oauth_state')) {
            return redirect()->route('settings.index')
                ->withErrors(['OAuth state mismatch. Please try again.']);
        }

        $response = Http::post("https://{$request->shop}/admin/oauth/access_token", [
            'client_id'     => Setting::get('shopify_client_id'),
            'client_secret' => Setting::get('shopify_client_secret'),
            'code'          => $request->code,
        ]);

        if (!$response->successful() || !$response->json('access_token')) {
            return redirect()->route('settings.index')
                ->withErrors(['Failed to get access token from Shopify. Check your Client Secret.']);
        }

        Setting::set('shopify_access_token', $response->json('access_token'));
        Setting::set('shopify_domain', $request->shop);

        session()->forget('shopify_oauth_state');

        return redirect()->route('settings.index')
            ->with('success', 'Shopify connected successfully!');
    }
}
