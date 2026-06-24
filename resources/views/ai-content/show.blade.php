@extends('layouts.app')

@section('title', 'AI Content — Review')
@section('page-title', 'AI Content — Review & Push')

@section('content')
<div class="space-y-5"
     x-data="aiContentShow({{ $aiContentSession->id }}, '{{ $aiContentSession->status }}')"
     x-init="init()">

    {{-- Header --}}
    <div class="flex items-center justify-between">
        <a href="{{ route('ai-content.index') }}"
           class="inline-flex items-center gap-1.5 text-sm text-gray-500 hover:text-gray-700">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
            </svg>
            Back to AI Content
        </a>
        <div class="flex items-center gap-3">
            <span class="text-sm text-gray-500">
                {{ $aiContentSession->store?->name ?? 'No store' }} &bull;
                {{ $aiContentSession->created_at->format('d M Y H:i') }}
            </span>
            <form method="POST" action="{{ route('ai-content.destroy', $aiContentSession) }}"
                  onsubmit="return confirm('Delete this session?')">
                @csrf @method('DELETE')
                <button type="submit" class="text-xs text-red-400 hover:text-red-600">Delete</button>
            </form>
        </div>
    </div>

    {{-- Processing indicator --}}
    <div x-show="status === 'pending' || status === 'processing'" x-cloak
         class="bg-white rounded-xl border border-gray-200 shadow-sm p-8 text-center">
        <div class="w-12 h-12 rounded-full border-4 border-brand-200 border-t-brand-600 animate-spin mx-auto mb-4"></div>
        <p class="text-gray-700 font-medium mb-1">Generating AI content…</p>
        <p class="text-sm text-gray-400 mb-4">Analyzing product images and generating descriptions. This may take a few minutes.</p>

        <div class="max-w-xs mx-auto">
            <div class="flex justify-between text-xs text-gray-500 mb-1">
                <span>Progress</span>
                <span x-text="processedItems + ' / ' + totalItems"></span>
            </div>
            <div class="h-2 bg-gray-100 rounded-full overflow-hidden">
                <div class="h-full bg-brand-500 rounded-full transition-all duration-500"
                     :style="`width: ${progress}%`"></div>
            </div>
        </div>
    </div>

    {{-- Failed state --}}
    <div x-show="status === 'failed'" x-cloak
         class="bg-red-50 border border-red-200 rounded-xl p-6 text-center">
        <p class="text-red-700 font-medium">Generation failed</p>
        <p class="text-sm text-red-500 mt-1">{{ $aiContentSession->error_message }}</p>
    </div>

    {{-- Ready — Items table --}}
    <div x-show="status === 'ready' || status === 'done'" x-cloak>
        <form method="POST" action="{{ route('ai-content.push', $aiContentSession) }}" id="pushForm">
            @csrf

            <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
                    <div>
                        <h2 class="text-base font-semibold text-gray-800">Review Generated Content</h2>
                        <p class="text-sm text-gray-500 mt-0.5">Edit content if needed, check the items you want to push, then click Push to Shopify.</p>
                    </div>
                    <div class="flex items-center gap-3">
                        <label class="flex items-center gap-2 text-sm text-gray-600 cursor-pointer select-none">
                            <input type="checkbox" @change="toggleAll($event)"
                                class="w-4 h-4 rounded border-gray-300 text-brand-600 focus:ring-brand-500">
                            Select All
                        </label>
                        <button type="submit"
                            class="inline-flex items-center gap-2 bg-brand-600 hover:bg-brand-700 text-white text-sm font-medium px-5 py-2 rounded-lg transition-colors">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/>
                            </svg>
                            Push to Shopify
                        </button>
                    </div>
                </div>

                {{-- Items loaded via JS --}}
                <div id="itemsContainer">
                    <template x-if="items.length === 0 && (status === 'ready' || status === 'done')">
                        <div class="px-6 py-12 text-center text-gray-400 text-sm">No items found.</div>
                    </template>

                    <template x-for="item in items" :key="item.id">
                        <div class="border-b border-gray-100 last:border-0 p-5">
                            <div class="flex gap-4">
                                {{-- Confirm checkbox --}}
                                <div class="pt-1 shrink-0" x-show="item.status === 'done' || item.status === 'pushed'">
                                    <input type="checkbox" :name="`confirmed[]`" :value="item.id"
                                        x-model="item.confirmed"
                                        class="confirm-checkbox w-4 h-4 rounded border-gray-300 text-brand-600 focus:ring-brand-500">
                                </div>

                                {{-- Image --}}
                                <div class="shrink-0">
                                    <template x-if="item.image_url">
                                        <img :src="item.image_url" :alt="item.sku"
                                            class="w-20 h-20 object-cover rounded-lg border border-gray-200">
                                    </template>
                                    <template x-if="!item.image_url">
                                        <div class="w-20 h-20 bg-gray-100 rounded-lg border border-gray-200 flex items-center justify-center">
                                            <svg class="w-6 h-6 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                    d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                                            </svg>
                                        </div>
                                    </template>
                                </div>

                                {{-- Content --}}
                                <div class="flex-1 min-w-0 space-y-3">
                                    <div class="flex items-center gap-3 flex-wrap">
                                        <span class="font-mono text-sm font-semibold text-gray-800" x-text="item.sku"></span>
                                        <span class="text-sm text-gray-500" x-text="item.product_title"></span>
                                        {{-- Status badge --}}
                                        <span x-text="ucfirst(item.status)"
                                            :class="{
                                                'bg-gray-100 text-gray-600':   item.status === 'pending',
                                                'bg-blue-100 text-blue-700':   item.status === 'processing',
                                                'bg-green-100 text-green-700': item.status === 'done',
                                                'bg-red-100 text-red-700':     item.status === 'failed',
                                                'bg-emerald-100 text-emerald-700': item.status === 'pushed',
                                            }"
                                            class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium">
                                        </span>
                                    </div>

                                    {{-- Error message --}}
                                    <template x-if="item.status === 'failed'">
                                        <p class="text-xs text-red-500" x-text="item.error_message"></p>
                                    </template>

                                    {{-- Editable fields --}}
                                    <template x-if="item.status === 'done' || item.status === 'pushed'">
                                        <div class="space-y-2">
                                            <div>
                                                <label class="block text-xs font-medium text-gray-500 mb-1">Description</label>
                                                <textarea :name="`description[${item.id}]`" rows="3"
                                                    x-model="item.ai_description"
                                                    class="w-full rounded-md border border-gray-200 px-3 py-2 text-sm text-gray-700 focus:outline-none focus:ring-2 focus:ring-brand-400 focus:border-transparent resize-y"></textarea>
                                            </div>
                                            <div class="grid grid-cols-2 gap-3">
                                                <div>
                                                    <label class="block text-xs font-medium text-gray-500 mb-1">
                                                        Meta Title <span class="text-gray-400" x-text="`(${(item.ai_meta_title || '').length}/60)`"></span>
                                                    </label>
                                                    <input type="text" :name="`meta_title[${item.id}]`" maxlength="60"
                                                        x-model="item.ai_meta_title"
                                                        class="w-full rounded-md border border-gray-200 px-3 py-2 text-sm text-gray-700 focus:outline-none focus:ring-2 focus:ring-brand-400 focus:border-transparent">
                                                </div>
                                                <div>
                                                    <label class="block text-xs font-medium text-gray-500 mb-1">
                                                        Meta Description <span class="text-gray-400" x-text="`(${(item.ai_meta_description || '').length}/160)`"></span>
                                                    </label>
                                                    <input type="text" :name="`meta_description[${item.id}]`" maxlength="160"
                                                        x-model="item.ai_meta_description"
                                                        class="w-full rounded-md border border-gray-200 px-3 py-2 text-sm text-gray-700 focus:outline-none focus:ring-2 focus:ring-brand-400 focus:border-transparent">
                                                </div>
                                            </div>
                                        </div>
                                    </template>
                                </div>
                            </div>
                        </div>
                    </template>
                </div>

                {{-- Bottom action bar --}}
                <div class="px-6 py-4 bg-gray-50 border-t border-gray-100 flex justify-end">
                    <button type="submit"
                        class="inline-flex items-center gap-2 bg-brand-600 hover:bg-brand-700 text-white text-sm font-medium px-5 py-2 rounded-lg transition-colors">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/>
                        </svg>
                        Push Selected to Shopify
                    </button>
                </div>
            </div>
        </form>
    </div>

</div>

<script>
function aiContentShow(sessionId, initialStatus) {
    return {
        sessionId,
        status: initialStatus,
        progress: 0,
        totalItems: 0,
        processedItems: 0,
        items: [],
        pollTimer: null,

        init() {
            if (this.status === 'pending' || this.status === 'processing') {
                this.poll();
            } else if (this.status === 'ready' || this.status === 'done') {
                this.loadItems();
            }
        },

        poll() {
            this.pollTimer = setInterval(async () => {
                const res  = await fetch(`/ai-content/${this.sessionId}/status`);
                const data = await res.json();

                this.status         = data.status;
                this.progress       = data.progress;
                this.totalItems     = data.total_items;
                this.processedItems = data.processed_items;

                if (this.status === 'ready' || this.status === 'done' || this.status === 'failed') {
                    clearInterval(this.pollTimer);
                    if (this.status === 'ready' || this.status === 'done') {
                        this.loadItems();
                    }
                }
            }, 3000);
        },

        async loadItems() {
            const res  = await fetch(`/ai-content/${this.sessionId}/items`);
            const data = await res.json();
            this.items = data.map(i => ({ ...i, confirmed: i.is_confirmed }));
        },

        toggleAll(event) {
            const checked = event.target.checked;
            this.items.forEach(item => {
                if (item.status === 'done' || item.status === 'pushed') {
                    item.confirmed = checked;
                }
            });
            document.querySelectorAll('.confirm-checkbox').forEach(cb => {
                if (!cb.disabled) cb.checked = checked;
            });
        },

        ucfirst(str) {
            if (!str) return '';
            return str.charAt(0).toUpperCase() + str.slice(1);
        },
    };
}
</script>
@endsection
