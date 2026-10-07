@extends('layouts.app')
@section('title', 'Stores')
@section('page-title', 'Stores')

@section('content')
<div class="mx-auto max-w-3xl space-y-3" x-data="storesPage()">

    {{-- Store list --}}
    @forelse($stores as $store)
    {{-- Active is per person, so it is the viewer's own choice being shown here. --}}
    @php $isActive = $store->id === $activeStoreId; @endphp
    <div class="bg-white rounded-xl border border-gray-200"
         x-data="{ editing: false }">

        {{-- View mode --}}
        <div x-show="!editing">
            <div class="flex items-center justify-between gap-4 px-5 py-4">
                <div class="flex min-w-0 items-center gap-3">
                    <span class="h-2 w-2 shrink-0 rounded-full {{ $isActive ? 'pulse-dot bg-emerald-500 text-emerald-500' : 'bg-gray-300' }}"></span>
                    <div class="min-w-0">
                        <div class="flex items-center gap-2">
                            <p class="truncate font-semibold text-gray-900">{{ $store->name }}</p>
                            @if($isActive)
                                <span class="rounded-full bg-emerald-50 px-2 py-0.5 text-[11px] font-semibold text-emerald-700">Active</span>
                            @endif
                        </div>
                        <p class="flex items-center gap-2 truncate text-sm text-gray-400">
                            <span class="truncate">{{ $store->shopify_domain }}</span>
                            @if($store->shopify_access_token)
                                <span class="shrink-0 text-emerald-600">· Connected</span>
                            @else
                                <span class="shrink-0 text-amber-600">· Not connected</span>
                            @endif
                        </p>
                    </div>
                </div>

                {{--
                    One visible action, the rest behind a menu. The row used to
                    carry five buttons of equal weight — Connect, Test, Set
                    Active, Edit and Delete — so removing a store looked exactly
                    as routine as renaming one.
                --}}
                <div class="flex shrink-0 items-center gap-2">
                    @if(!$store->shopify_access_token && $isActive && $store->shopify_client_id)
                        <a href="{{ route('shopify.auth.redirect') }}"
                           class="rounded-lg bg-brand-600 px-3 py-1.5 text-xs font-semibold text-white shadow-sm transition-colors hover:bg-brand-700">
                            Connect Shopify
                        </a>
                    @elseif(!$isActive)
                        <form method="POST" action="{{ route('stores.switch', $store) }}">
                            @csrf
                            <button type="submit"
                                class="rounded-lg border border-gray-200 px-3 py-1.5 text-xs font-semibold text-gray-600 transition-colors hover:border-brand-300 hover:text-brand-700">
                                Switch to this
                            </button>
                        </form>
                    @endif

                    <div x-data="{ menu: false }" class="relative" @click.outside="menu = false">
                        <button type="button" @click="menu = !menu" aria-label="More actions for {{ $store->name }}"
                                class="grid h-8 w-8 place-items-center rounded-lg text-gray-400 transition-colors hover:bg-gray-100 hover:text-gray-700">
                            <svg class="h-4 w-4" fill="currentColor" viewBox="0 0 20 20">
                                <path d="M10 6a1.5 1.5 0 110-3 1.5 1.5 0 010 3zm0 5.5a1.5 1.5 0 110-3 1.5 1.5 0 010 3zm0 5.5a1.5 1.5 0 110-3 1.5 1.5 0 010 3z"/>
                            </svg>
                        </button>

                        <div x-show="menu" x-cloak @click="menu = false"
                             x-transition:enter="transition ease-out duration-150"
                             x-transition:enter-start="opacity-0 -translate-y-1"
                             x-transition:enter-end="opacity-100 translate-y-0"
                             class="absolute right-0 z-20 mt-1 w-48 overflow-hidden rounded-xl border border-gray-200 bg-white py-1 shadow-lg">
                            <button type="button" @click="testStore({{ $store->id }}, $event)"
                                class="block w-full px-4 py-2 text-left text-sm text-gray-600 transition-colors hover:bg-gray-50 hover:text-gray-900">
                                Test connection
                            </button>
                            <button type="button" @click="editing = true"
                                class="block w-full px-4 py-2 text-left text-sm text-gray-600 transition-colors hover:bg-gray-50 hover:text-gray-900">
                                Edit details
                            </button>
                            <form method="POST" action="{{ route('stores.destroy', $store) }}"
                                  onsubmit="return confirm('Remove {{ addslashes($store->name) }}? Anything pointing at it stops working.')"
                                  class="border-t border-gray-100">
                                @csrf
                                @method('DELETE')
                                <button type="submit"
                                    class="block w-full px-4 py-2 text-left text-sm text-red-600 transition-colors hover:bg-red-50">
                                    Remove store
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Test result --}}
            <div x-show="testResults[{{ $store->id }}]" x-cloak class="px-6 pb-4">
                <div :class="testOk[{{ $store->id }}] ? 'bg-green-50 text-green-700 border-green-200' : 'bg-red-50 text-red-700 border-red-200'"
                     class="border rounded-lg px-3 py-2 text-sm"
                     x-text="testResults[{{ $store->id }}]">
                </div>
            </div>
        </div>

        {{-- Edit mode --}}
        <div x-show="editing" x-cloak>
            <form method="POST" action="{{ route('stores.update', $store) }}">
                @csrf
                @method('PUT')
                <div class="px-6 py-4 space-y-3">
                    <p class="text-sm font-semibold text-gray-700">Edit Store</p>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Store Name</label>
                        <input type="text" name="name" value="{{ $store->name }}" required
                            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Shopify Domain</label>
                        <input type="text" name="shopify_domain" value="{{ $store->shopify_domain }}" required
                            placeholder="your-store.myshopify.com"
                            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent">
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">Client ID</label>
                            <input type="text" name="shopify_client_id" value="{{ $store->shopify_client_id }}"
                                placeholder="From Partner Dashboard"
                                class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">Client Secret</label>
                            <input type="password" name="shopify_client_secret" value="{{ $store->shopify_client_secret }}"
                                placeholder="Leave blank to keep"
                                class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent">
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">GA4 Property ID <span class="text-gray-400 font-normal">(for sessions and visitors)</span></label>
                        <input type="text" name="ga4_property_id" value="{{ $store->ga4_property_id }}"
                            inputmode="numeric" placeholder="123456789 — digits only, not G-XXXXXXX"
                            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent">
                        <p class="mt-1 text-xs text-gray-400">
                            Google Analytics → Admin → Property Settings. Leave blank if this website has no GA4 property.
                        </p>
                        @error('ga4_property_id')
                            <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
                        @enderror
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Search Console site <span class="text-gray-400 font-normal">(for impressions, clicks and ranking)</span></label>
                        <input type="text" name="gsc_site_url" value="{{ $store->gsc_site_url }}"
                            placeholder="sc-domain:example.com"
                            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent">
                        <p class="mt-1 text-xs text-gray-400">
                            Copy it exactly as Search Console shows it — <code class="rounded bg-gray-100 px-1">sc-domain:example.com</code>
                            for a domain property, or the full address with its trailing slash. The service
                            account must be added as a user on the property.
                        </p>
                        @error('gsc_site_url')
                            <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
                        @enderror
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Access Token <span class="text-gray-400 font-normal">(paste directly or use Connect Shopify)</span></label>
                        <input type="password" name="shopify_access_token" value="{{ $store->shopify_access_token }}"
                            placeholder="shpat_xxxxxxxxxxxx — leave blank to keep existing"
                            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent">
                    </div>
                    <div class="pt-1 border-t border-gray-100">
                        <label class="flex items-start gap-2.5 cursor-pointer select-none pt-3">
                            <input type="checkbox" name="requires_sku_mapping" value="1" {{ $store->requires_sku_mapping ? 'checked' : '' }}
                                class="w-4 h-4 mt-0.5 rounded border-gray-300 text-brand-600 focus:ring-brand-500">
                            <span>
                                <span class="text-sm text-gray-700">SKUs are mapped in Cegid for this website</span>
                                <span class="block text-xs text-gray-400">
                                    Product creation requests for this website go through the
                                    <span class="font-medium">Waiting for Mapping</span> stage with the brand manager.
                                    Leave off and requests skip straight to SKUs Checked.
                                </span>
                            </span>
                        </label>
                    </div>
                </div>
                <div class="px-6 py-3 bg-gray-50 border-t border-gray-100 flex gap-2 justify-end">
                    <button type="button" @click="editing = false"
                        class="text-sm border border-gray-200 text-gray-500 px-4 py-2 rounded-lg hover:bg-white transition-colors">
                        Cancel
                    </button>
                    <button type="submit"
                        class="text-sm bg-brand-600 hover:bg-brand-700 text-white px-4 py-2 rounded-lg transition-colors">
                        Save Changes
                    </button>
                </div>
            </form>
        </div>

    </div>
    @empty
    <div class="rounded-xl border border-dashed border-gray-300 bg-white px-6 py-12 text-center">
        <p class="font-display text-xl text-gray-900">No stores yet</p>
        <p class="mt-1 text-sm text-gray-400">Add one below to start syncing.</p>
    </div>
    @endforelse

    {{-- Add new store --}}
    <div class="bg-white rounded-xl border border-gray-200 overflow-hidden"
         x-data="{ open: {{ $stores->isEmpty() ? 'true' : 'false' }} }">

        <button type="button" @click="open = !open"
            class="flex w-full items-center justify-between px-5 py-4 text-left transition-colors hover:bg-gray-50">
            <span class="text-sm font-semibold text-brand-700">Add a store</span>
            <svg :class="open ? 'rotate-45' : ''" class="w-5 h-5 text-gray-400 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
            </svg>
        </button>

        <div x-show="open" x-cloak>
            <form method="POST" action="{{ route('stores.store') }}">
                @csrf
                <div class="px-6 pb-4 space-y-3 border-t border-gray-100 pt-4">
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Store Name</label>
                        <input type="text" name="name" required placeholder="e.g. My Main Store"
                            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Shopify Domain</label>
                        <input type="text" name="shopify_domain" required placeholder="your-store.myshopify.com"
                            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent">
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">Client ID</label>
                            <input type="text" name="shopify_client_id" placeholder="From Partner Dashboard"
                                class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">Client Secret</label>
                            <input type="password" name="shopify_client_secret" placeholder="From Partner Dashboard"
                                class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent">
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Access Token <span class="text-gray-400 font-normal">(optional — or use Connect Shopify after saving)</span></label>
                        <input type="password" name="shopify_access_token" placeholder="shpat_xxxxxxxxxxxx"
                            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">GA4 Property ID <span class="text-gray-400 font-normal">(optional)</span></label>
                        <input type="text" name="ga4_property_id" inputmode="numeric"
                            placeholder="123456789 — digits only, not G-XXXXXXX"
                            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Search Console site <span class="text-gray-400 font-normal">(optional)</span></label>
                        <input type="text" name="gsc_site_url"
                            placeholder="sc-domain:example.com"
                            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent">
                    </div>
                    <div class="pt-1 border-t border-gray-100">
                        <label class="flex items-start gap-2.5 cursor-pointer select-none pt-3">
                            <input type="checkbox" name="requires_sku_mapping" value="1"
                                class="w-4 h-4 mt-0.5 rounded border-gray-300 text-brand-600 focus:ring-brand-500">
                            <span>
                                <span class="text-sm text-gray-700">SKUs are mapped in Cegid for this website</span>
                                <span class="block text-xs text-gray-400">
                                    Product creation requests for this website go through the
                                    <span class="font-medium">Waiting for Mapping</span> stage with the brand manager.
                                </span>
                            </span>
                        </label>
                    </div>
                </div>
                <div class="px-6 py-3 bg-gray-50 border-t border-gray-100 flex justify-end">
                    <button type="submit"
                        class="text-sm bg-brand-600 hover:bg-brand-700 text-white px-4 py-2 rounded-lg transition-colors font-medium">
                        Add Store
                    </button>
                </div>
            </form>
        </div>
    </div>

</div>

<script>
function storesPage() {
    return {
        testResults: {},
        testOk: {},

        async testStore(id, event) {
            const btn = event.target;
            const original = btn.textContent;
            btn.textContent = 'Testing…';
            btn.disabled = true;

            try {
                const res  = await fetch(`/stores/${id}/test`, {
                    headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content }
                });
                const data = await res.json();
                this.testOk[id]      = data.ok;
                this.testResults[id] = data.message;
            } catch {
                this.testOk[id]      = false;
                this.testResults[id] = 'Request failed.';
            } finally {
                btn.textContent = original;
                btn.disabled = false;
            }
        },
    };
}
</script>
@endsection
