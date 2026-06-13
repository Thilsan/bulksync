<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Services\OneDriveService;
use App\Services\ShopifyService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SettingsController extends Controller
{
    public function index(): View
    {
        $settings = [
            'shopify_domain'          => Setting::get('shopify_domain'),
            'shopify_client_id'       => Setting::get('shopify_client_id'),
            'shopify_client_secret'   => Setting::get('shopify_client_secret'),
            'shopify_access_token'    => Setting::get('shopify_access_token'),
            'onedrive_tenant_id'      => Setting::get('onedrive_tenant_id'),
            'onedrive_client_id'      => Setting::get('onedrive_client_id'),
            'onedrive_client_secret'  => Setting::get('onedrive_client_secret'),
        ];

        return view('settings.index', compact('settings'));
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'shopify_domain'         => ['nullable', 'string', 'max:255'],
            'shopify_client_id'      => ['nullable', 'string', 'max:255'],
            'shopify_client_secret'  => ['nullable', 'string', 'max:500'],
            'onedrive_tenant_id'     => ['nullable', 'string', 'max:255'],
            'onedrive_client_id'     => ['nullable', 'string', 'max:255'],
            'onedrive_client_secret' => ['nullable', 'string', 'max:500'],
        ]);

        foreach ($validated as $key => $value) {
            Setting::set($key, $value ?: null);
        }

        return back()->with('success', 'Settings saved successfully.');
    }

    public function testShopify(): \Illuminate\Http\JsonResponse
    {
        $ok = app(ShopifyService::class)->testConnection();

        return response()->json(['ok' => $ok, 'message' => $ok ? 'Shopify connected!' : 'Connection failed. Check domain and access token.']);
    }

    public function testOnedrive(): \Illuminate\Http\JsonResponse
    {
        $ok = app(OneDriveService::class)->testConnection();

        return response()->json(['ok' => $ok, 'message' => $ok ? 'OneDrive connected!' : 'Connection failed. Check Azure credentials.']);
    }
}
