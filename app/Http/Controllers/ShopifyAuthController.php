<?php

namespace App\Http\Controllers;

use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class ShopifyAuthController extends Controller
{
    public function redirect()
    {
        $store = Store::getActive();

        if (!$store || !$store->shopify_domain || !$store->shopify_client_id) {
            return redirect()->route('stores.index')
                ->withErrors(['Save your Store Domain and Client ID first, then try connecting.']);
        }

        $shop  = preg_replace('#^https?://#', '', rtrim($store->shopify_domain, '/'));
        $state = Str::random(40);
        session(['shopify_oauth_state' => $state, 'shopify_oauth_store_id' => $store->id]);

        $authUrl = "https://{$shop}/admin/oauth/authorize?" . http_build_query([
            'client_id'    => $store->shopify_client_id,
            'scope'        => 'read_products,write_products',
            'redirect_uri' => route('shopify.auth.callback'),
            'state'        => $state,
        ]);

        return redirect($authUrl);
    }

    public function callback(Request $request)
    {
        if ($request->state !== session('shopify_oauth_state')) {
            return redirect()->route('stores.index')
                ->withErrors(['OAuth state mismatch. Please try again.']);
        }

        $store = Store::find(session('shopify_oauth_store_id')) ?? Store::getActive();

        if (!$store) {
            return redirect()->route('stores.index')
                ->withErrors(['No active store found.']);
        }

        $response = Http::post("https://{$request->shop}/admin/oauth/access_token", [
            'client_id'     => $store->shopify_client_id,
            'client_secret' => $store->shopify_client_secret,
            'code'          => $request->code,
        ]);

        if (!$response->successful() || !$response->json('access_token')) {
            return redirect()->route('stores.index')
                ->withErrors(['Failed to get access token from Shopify. Check your Client Secret.']);
        }

        $store->update([
            'shopify_access_token' => $response->json('access_token'),
            'shopify_domain'       => $request->shop,
        ]);

        session()->forget(['shopify_oauth_state', 'shopify_oauth_store_id']);

        return redirect()->route('stores.index')
            ->with('success', "Shopify connected successfully for \"{$store->name}\"!");
    }
}
