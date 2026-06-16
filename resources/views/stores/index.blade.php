@extends('layouts.app')
@section('title', 'Stores')
@section('page-title', 'Stores')

@section('content')
<div class="max-w-2xl mx-auto space-y-4" x-data="storesPage()">

    {{-- Store list --}}
    @forelse($stores as $store)
    <div class="bg-white rounded-xl border border-gray-200 overflow-hidden"
         x-data="{ editing: false }">

        {{-- View mode --}}
        <div x-show="!editing">
            <div class="px-6 py-4 flex items-center justify-between gap-4">
                <div class="flex items-center gap-3 min-w-0">
                    <div class="w-2.5 h-2.5 rounded-full shrink-0 {{ $store->is_active ? 'bg-green-500' : 'bg-gray-300' }}"></div>
                    <div class="min-w-0">
                        <div class="flex items-center gap-2">
                            <p class="font-semibold text-gray-900 truncate">{{ $store->name }}</p>
                            @if($store->is_active)
                            <span class="text-xs bg-green-100 text-green-700 px-2 py-0.5 rounded-full font-medium">Active</span>
                            @endif
                        </div>
                        <p class="text-sm text-gray-400 truncate">{{ $store->shopify_domain }}</p>
                    </div>
                </div>

                <div class="flex items-center gap-2 shrink-0">
                    {{-- Shopify connect status + button --}}
                    @if($store->shopify_access_token)
                    <span class="flex items-center gap-1 text-xs text-green-600">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                        </svg>
                        Connected
                    </span>
                    @else
                    @if($store->is_active && $store->shopify_client_id)
                    <a href="{{ route('shopify.auth.redirect') }}"
                       class="text-xs bg-green-600 hover:bg-green-700 text-white px-3 py-1.5 rounded-lg transition-colors font-medium">
                        Connect Shopify
                    </a>
                    @endif
                    @endif

                    {{-- Test --}}
                    <button type="button" @click="testStore({{ $store->id }}, $event)"
                        class="text-xs border border-gray-200 text-gray-500 px-3 py-1.5 rounded-lg hover:bg-gray-50 transition-colors">
                        Test
                    </button>

                    {{-- Set active --}}
                    @if(!$store->is_active)
                    <form method="POST" action="{{ route('stores.switch', $store) }}">
                        @csrf
                        <button type="submit"
                            class="text-xs border border-gray-200 text-gray-500 px-3 py-1.5 rounded-lg hover:bg-gray-50 transition-colors">
                            Set Active
                        </button>
                    </form>
                    @endif

                    {{-- Edit --}}
                    <button type="button" @click="editing = true"
                        class="text-xs border border-gray-200 text-gray-500 px-3 py-1.5 rounded-lg hover:bg-gray-50 transition-colors">
                        Edit
                    </button>

                    {{-- Delete --}}
                    <form method="POST" action="{{ route('stores.destroy', $store) }}"
                          onsubmit="return confirm('Remove {{ addslashes($store->name) }}?')">
                        @csrf
                        @method('DELETE')
                        <button type="submit"
                            class="text-xs border border-red-200 text-red-500 px-3 py-1.5 rounded-lg hover:bg-red-50 transition-colors">
                            Delete
                        </button>
                    </form>
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
                        <label class="block text-xs font-medium text-gray-600 mb-1">Access Token <span class="text-gray-400 font-normal">(paste directly or use Connect Shopify)</span></label>
                        <input type="password" name="shopify_access_token" value="{{ $store->shopify_access_token }}"
                            placeholder="shpat_xxxxxxxxxxxx — leave blank to keep existing"
                            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent">
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
    <div class="bg-white rounded-xl border border-gray-200 px-6 py-10 text-center">
        <p class="text-gray-400 text-sm">No stores added yet. Add your first store below.</p>
    </div>
    @endforelse

    {{-- Add new store --}}
    <div class="bg-white rounded-xl border border-gray-200 overflow-hidden"
         x-data="{ open: {{ $stores->isEmpty() ? 'true' : 'false' }} }">

        <button type="button" @click="open = !open"
            class="w-full flex items-center justify-between px-6 py-4 text-left hover:bg-gray-50 transition-colors">
            <span class="text-sm font-semibold text-gray-700">Add New Store</span>
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
