<?php

namespace App\Http\Controllers;

use App\Models\ProductRequest;
use App\Models\Store;
use App\Models\User;
use App\Services\ProductRequestWorkflow;
use App\Services\ShopifyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class StoreController extends Controller
{
    public function __construct(
        private readonly ProductRequestWorkflow $workflow,
    ) {}

    public function index(): View
    {
        $user          = auth()->user();
        $activeStoreId = Store::getActive()?->id;

        // Whatever this person has active sits on top — that is theirs alone,
        // so the ordering cannot come from the database.
        $stores = Store::accessibleBy($user)->orderBy('name')->get()
            ->sortByDesc(fn (Store $store) => $store->id === $activeStoreId)
            ->values();

        return view('stores.index', compact('stores', 'activeStoreId'));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name'                  => ['required', 'string', 'max:255'],
            'shopify_domain'        => ['required', 'string', 'max:255'],
            'shopify_client_id'     => ['nullable', 'string', 'max:255'],
            'shopify_client_secret' => ['nullable', 'string', 'max:500'],
            'shopify_access_token'  => ['nullable', 'string', 'max:500'],
            'requires_sku_mapping'  => ['nullable', 'boolean'],
        ]);

        $validated['requires_sku_mapping'] = $request->boolean('requires_sku_mapping');

        $user = auth()->user();

        $store = Store::create(array_merge($validated, ['user_id' => $user->id]));

        // Nothing picked yet — start them off in the store they just added.
        if (!$user->active_store_id) {
            $user->forceFill(['active_store_id' => $store->id])->save();
        }

        return back()->with('success', "Store \"{$store->name}\" added.");
    }

    public function update(Request $request, Store $store): RedirectResponse
    {
        $validated = $request->validate([
            'name'                  => ['required', 'string', 'max:255'],
            'shopify_domain'        => ['required', 'string', 'max:255'],
            'shopify_client_id'     => ['nullable', 'string', 'max:255'],
            'shopify_client_secret' => ['nullable', 'string', 'max:500'],
            'shopify_access_token'  => ['nullable', 'string', 'max:500'],
            'requires_sku_mapping'  => ['nullable', 'boolean'],
        ]);

        $validated['requires_sku_mapping'] = $request->boolean('requires_sku_mapping');

        $mappingChanged = $store->requires_sku_mapping !== $validated['requires_sku_mapping'];

        $store->update($validated);

        $message = "Store \"{$store->name}\" updated.";

        // The tick is the only thing that decides whether this website's requests
        // wait on Cegid, so flipping it has to reach the requests already open —
        // otherwise they keep the stage they were given under the old setting.
        if ($mappingChanged) {
            $moved = $this->reconcileOpenRequests($store);

            if ($moved > 0) {
                $message .= " {$moved} open request(s) re-checked against the new mapping setting.";
            }
        }

        return back()->with('success', $message);
    }

    /**
     * Re-runs the mapping decision for this website's open requests after the
     * Cegid tick changed. Notifications are suppressed: one admin toggle is not
     * hundreds of individual status-change emails, and the activity log on each
     * request still records the move.
     *
     * @return int how many requests actually changed stage
     */
    private function reconcileOpenRequests(Store $store): int
    {
        $moved = 0;

        ProductRequest::where('store_id', $store->id)
            ->whereIn('status', [ProductRequest::SUBMITTED, ProductRequest::WAITING_MAPPING, ProductRequest::SKU_VERIFIED])
            ->with('store')
            ->chunkById(200, function ($requests) use (&$moved) {
                foreach ($requests as $productRequest) {
                    $before = $productRequest->status;

                    $this->workflow->reconcileMapping($productRequest, auth()->user(), notify: false);

                    if ($productRequest->status !== $before) {
                        $moved++;
                    }
                }
            });

        return $moved;
    }

    public function destroy(Store $store): RedirectResponse
    {
        // Anyone sitting in this store falls back to their first store instead
        // of pointing at a row that is about to disappear.
        User::where('active_store_id', $store->id)->update(['active_store_id' => null]);

        $name = $store->name;
        $store->delete();

        return back()->with('success', "Store \"{$name}\" removed.");
    }

    public function switch(Store $store): RedirectResponse
    {
        abort_unless(Store::switchTo($store->id), 403);

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
