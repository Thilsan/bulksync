<?php

namespace App\Http\Controllers;

use App\Models\Store;
use App\Services\ShopifyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class StoreController extends Controller
{
    public function index(): View
    {
        $stores = Store::orderByDesc('is_active')->orderBy('name')->get();
        return view('stores.index', compact('stores'));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name'                  => ['required', 'string', 'max:255'],
            'shopify_domain'        => ['required', 'string', 'max:255'],
            'shopify_client_id'     => ['nullable', 'string', 'max:255'],
            'shopify_client_secret' => ['nullable', 'string', 'max:500'],
            'shopify_access_token'  => ['nullable', 'string', 'max:500'],
        ]);

        $isFirst = Store::count() === 0;
        $store   = Store::create(array_merge($validated, ['is_active' => $isFirst]));

        return back()->with('success', "Store \"{$store->name}\" added.");
    }

    public function update(Request $request, Store $store): RedirectResponse
    {
        $validated = $request->validate([
            'name'                 => ['required', 'string', 'max:255'],
            'shopify_domain'       => ['required', 'string', 'max:255'],
            'shopify_access_token' => ['nullable', 'string', 'max:500'],
        ]);

        $store->update($validated);

        return back()->with('success', "Store \"{$store->name}\" updated.");
    }

    public function destroy(Store $store): RedirectResponse
    {
        if ($store->is_active) {
            Store::where('id', '!=', $store->id)->first()?->update(['is_active' => true]);
        }

        $name = $store->name;
        $store->delete();

        return back()->with('success', "Store \"{$name}\" removed.");
    }

    public function switch(Store $store): RedirectResponse
    {
        Store::switchTo($store->id);
        return back()->with('success', "Switched to \"{$store->name}\".");
    }

    public function test(Store $store): JsonResponse
    {
        $ok = (new ShopifyService($store))->testConnection();

        return response()->json([
            'ok'      => $ok,
            'message' => $ok ? 'Connected!' : 'Connection failed. Check domain and access token.',
        ]);
    }
}
