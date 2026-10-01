@extends('layouts.app')

@section('title', 'AI Content — Review')
@section('page-title', 'AI Content — Review & Push')

@section('content')
<div class="space-y-5"
     x-data="aiContentShow({{ $aiContentSession->id }}, '{{ $aiContentSession->status }}', {{ Js::from($tagTaxonomy) }})"
     x-init="init()">

    {{-- Header --}}
    <div class="flex items-center justify-between">
        {{-- Back to the overview, not the generator form --}}
        <a href="{{ route('ai-content.dashboard') }}"
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
    <div x-show="status === 'pending' || status === 'processing' || status === 'translating'" x-cloak
         class="bg-white rounded-xl border border-gray-200 shadow-sm p-8 text-center">
        <div class="w-12 h-12 rounded-full border-4 border-brand-200 border-t-brand-600 animate-spin mx-auto mb-4"></div>
        <p class="text-gray-700 font-medium mb-1"
           x-text="status === 'translating' ? 'Translating to Arabic…' : 'Generating AI content…'"></p>
        <p class="text-sm text-gray-400 mb-4"
           x-text="status === 'translating' ? 'Translating descriptions, meta content and alt text with Fanar AI. This may take a few minutes.' : 'Analyzing product images and generating descriptions. This may take a few minutes.'"></p>

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

    {{-- Stopped early but with usable results: the failure screen above is
         hidden in this case, so the reason would otherwise go unsaid. --}}
    @if ($aiContentSession->error_message)
        <div x-show="status === 'ready' || status === 'done'" x-cloak
             class="bg-amber-50 border border-amber-200 rounded-xl p-4 mb-4">
            <p class="text-amber-800 font-medium text-sm">Generation stopped before finishing</p>
            <p class="text-sm text-amber-700 mt-1">{{ $aiContentSession->error_message }}</p>
            <p class="text-xs text-amber-600 mt-2">The items below generated successfully and can still be pushed.</p>
        </div>
    @endif

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
                        <button type="submit" formaction="{{ route('ai-content.translate', $aiContentSession) }}"
                            class="inline-flex items-center gap-2 bg-white border border-brand-600 text-brand-700 hover:bg-brand-50 text-sm font-medium px-5 py-2 rounded-lg transition-colors">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M3 5h12M9 3v2m1.048 9.5A18.022 18.022 0 016.412 9m6.088 9h7M11 21l5-10 5 10M12.751 5C11.783 10.77 8.07 15.61 3 18.129"/>
                            </svg>
                            Generate Arabic
                        </button>
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
                                        <span class="font-mono text-sm font-semibold text-gray-800" x-text="item.all_skus || item.sku"></span>
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
                                        <div class="space-y-2" x-data="{ editMode: false, lang: 'en' }">

                                            {{-- Language toggle --}}
                                            <div class="flex items-center gap-2">
                                                <button type="button" @click="lang = 'en'"
                                                    :class="lang === 'en' ? 'bg-brand-600 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'"
                                                    class="text-xs font-medium px-3 py-1 rounded-full">English</button>
                                                <button type="button" @click="lang = 'ar'"
                                                    :class="lang === 'ar' ? 'bg-brand-600 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'"
                                                    class="text-xs font-medium px-3 py-1 rounded-full">العربية (Arabic)</button>
                                                <span class="text-xs text-gray-400"
                                                    x-text="item.ai_description_ar ? 'Arabic is saved but not yet pushed to Shopify — English only for now.' : 'No Arabic yet — review the English, then click Generate Arabic above.'"></span>
                                            </div>

                                            <div>
                                                <div class="flex items-center justify-between mb-1">
                                                    <label class="block text-xs font-medium text-gray-500">
                                                        Product Title <span class="text-gray-400" x-text="`(${(item.ai_title || '').length}/80)`"></span>
                                                    </label>
                                                    <label class="flex items-center gap-1.5 text-xs text-gray-500 cursor-pointer select-none">
                                                        <input type="checkbox" :name="`overwrite_title[${item.id}]`" value="1"
                                                            x-model="item.overwrite_title"
                                                            class="w-3.5 h-3.5 rounded border-gray-300 text-brand-600 focus:ring-brand-500">
                                                        Overwrite Shopify title with this
                                                    </label>
                                                </div>
                                                <input type="text" maxlength="80" :name="`title[${item.id}]`"
                                                    x-model="item.ai_title"
                                                    :disabled="!item.overwrite_title"
                                                    :class="!item.overwrite_title ? 'bg-gray-50 text-gray-400' : 'text-gray-800'"
                                                    class="w-full rounded-md border border-gray-200 px-3 py-2 text-sm font-medium focus:outline-none focus:ring-2 focus:ring-brand-400 focus:border-transparent">
                                                <p class="text-xs text-gray-400 mt-1" x-show="!item.overwrite_title">Unchecked — the existing Shopify title will be kept as-is.</p>
                                            </div>

                                            <div>
                                                <div class="flex items-center justify-between mb-1">
                                                    <label class="block text-xs font-medium text-gray-500">Description</label>
                                                    <button type="button" @click="editMode = !editMode"
                                                        class="text-xs text-brand-600 hover:text-brand-800 font-medium"
                                                        x-text="editMode ? 'Preview' : 'Edit HTML'"></button>
                                                </div>

                                                {{-- English --}}
                                                <template x-if="lang === 'en'">
                                                    <div>
                                                        <div x-show="!editMode"
                                                            class="text-sm text-gray-700 leading-relaxed border border-gray-200 rounded-md px-3 py-2 bg-gray-50 max-h-64 overflow-y-auto [&_p]:mb-2 [&_ul]:list-disc [&_ul]:pl-5 [&_ul]:space-y-1 [&_strong]:font-semibold"
                                                            x-html="item.ai_description"></div>
                                                        <textarea x-show="editMode" rows="10"
                                                            x-model="item.ai_description"
                                                            class="w-full rounded-md border border-gray-200 px-3 py-2 text-sm text-gray-700 font-mono focus:outline-none focus:ring-2 focus:ring-brand-400 focus:border-transparent resize-y"></textarea>
                                                    </div>
                                                </template>

                                                {{-- Arabic --}}
                                                <template x-if="lang === 'ar'">
                                                    <div>
                                                        <div x-show="!editMode" dir="rtl"
                                                            class="text-sm text-gray-700 leading-relaxed border border-gray-200 rounded-md px-3 py-2 bg-gray-50 max-h-64 overflow-y-auto text-right [&_p]:mb-2 [&_ul]:list-disc [&_ul]:pr-5 [&_ul]:space-y-1 [&_strong]:font-semibold"
                                                            x-html="item.ai_description_ar || '<span class=&quot;text-gray-400&quot;>No Arabic translation</span>'"></div>
                                                        <textarea x-show="editMode" rows="10" dir="rtl"
                                                            x-model="item.ai_description_ar"
                                                            class="w-full rounded-md border border-gray-200 px-3 py-2 text-sm text-gray-700 font-mono text-right focus:outline-none focus:ring-2 focus:ring-brand-400 focus:border-transparent resize-y"></textarea>
                                                    </div>
                                                </template>

                                                {{-- Hidden inputs always submit both languages regardless of active tab --}}
                                                <input type="hidden" :name="`description[${item.id}]`" :value="item.ai_description">
                                                <input type="hidden" :name="`description_ar[${item.id}]`" :value="item.ai_description_ar">
                                            </div>

                                            <div class="grid grid-cols-2 gap-3">
                                                <template x-if="lang === 'en'">
                                                    <div>
                                                        <label class="block text-xs font-medium text-gray-500 mb-1">
                                                            Meta Title <span class="text-gray-400" x-text="`(${(item.ai_meta_title || '').length}/60)`"></span>
                                                        </label>
                                                        <input type="text" maxlength="60"
                                                            x-model="item.ai_meta_title"
                                                            class="w-full rounded-md border border-gray-200 px-3 py-2 text-sm text-gray-700 focus:outline-none focus:ring-2 focus:ring-brand-400 focus:border-transparent">
                                                    </div>
                                                </template>
                                                <template x-if="lang === 'ar'">
                                                    <div>
                                                        <label class="block text-xs font-medium text-gray-500 mb-1">Meta Title (Arabic)</label>
                                                        <input type="text" dir="rtl"
                                                            x-model="item.ai_meta_title_ar"
                                                            class="w-full rounded-md border border-gray-200 px-3 py-2 text-sm text-gray-700 text-right focus:outline-none focus:ring-2 focus:ring-brand-400 focus:border-transparent">
                                                    </div>
                                                </template>

                                                <template x-if="lang === 'en'">
                                                    <div>
                                                        <label class="block text-xs font-medium text-gray-500 mb-1">
                                                            Meta Description <span class="text-gray-400" x-text="`(${(item.ai_meta_description || '').length}/160)`"></span>
                                                        </label>
                                                        <input type="text" maxlength="160"
                                                            x-model="item.ai_meta_description"
                                                            class="w-full rounded-md border border-gray-200 px-3 py-2 text-sm text-gray-700 focus:outline-none focus:ring-2 focus:ring-brand-400 focus:border-transparent">
                                                    </div>
                                                </template>
                                                <template x-if="lang === 'ar'">
                                                    <div>
                                                        <label class="block text-xs font-medium text-gray-500 mb-1">Meta Description (Arabic)</label>
                                                        <input type="text" dir="rtl"
                                                            x-model="item.ai_meta_description_ar"
                                                            class="w-full rounded-md border border-gray-200 px-3 py-2 text-sm text-gray-700 text-right focus:outline-none focus:ring-2 focus:ring-brand-400 focus:border-transparent">
                                                    </div>
                                                </template>

                                                {{-- These always submit the current value regardless of which language tab is active --}}
                                                <input type="hidden" :name="`meta_title[${item.id}]`" :value="item.ai_meta_title">
                                                <input type="hidden" :name="`meta_title_ar[${item.id}]`" :value="item.ai_meta_title_ar">
                                                <input type="hidden" :name="`meta_description[${item.id}]`" :value="item.ai_meta_description">
                                                <input type="hidden" :name="`meta_description_ar[${item.id}]`" :value="item.ai_meta_description_ar">
                                            </div>

                                            <div x-show="item.images && item.images.length > 0">
                                                <label class="block text-xs font-medium text-gray-500 mb-1">
                                                    Images &amp; Alt Text <span class="text-gray-400" x-text="`(${(item.images || []).length} image${(item.images || []).length === 1 ? '' : 's'})`"></span>
                                                </label>
                                                <div class="space-y-2">
                                                    <template x-for="image in item.images" :key="image.id">
                                                        <div class="flex items-center gap-3">
                                                            <img :src="image.image_url" class="w-12 h-12 object-cover rounded-md border border-gray-200 shrink-0">
                                                            <div class="flex-1 min-w-0">
                                                                <input x-show="lang === 'en'" type="text" :name="`image_alt[${image.id}]`" maxlength="125"
                                                                    x-model="image.ai_alt_text"
                                                                    :disabled="image.status === 'failed' && !image.ai_alt_text"
                                                                    class="w-full rounded-md border border-gray-200 px-3 py-1.5 text-sm text-gray-700 focus:outline-none focus:ring-2 focus:ring-brand-400 focus:border-transparent">
                                                                <input x-show="lang === 'ar'" type="text" dir="rtl" :name="`image_alt_ar[${image.id}]`"
                                                                    x-model="image.ai_alt_text_ar"
                                                                    class="w-full rounded-md border border-gray-200 px-3 py-1.5 text-sm text-gray-700 text-right focus:outline-none focus:ring-2 focus:ring-brand-400 focus:border-transparent">
                                                            </div>
                                                            <span x-show="image.status === 'failed'"
                                                                class="text-xs text-red-500 shrink-0">Failed</span>
                                                        </div>
                                                    </template>
                                                </div>
                                            </div>

                                            {{-- Tags come from the store's own vocabulary, keyed by category
                                                 and type — not from the model, which used to invent near-duplicates
                                                 of tags the store already had. --}}
                                            <div class="rounded-lg border border-gray-200 overflow-hidden">

                                                {{-- Header: what this is, and the state of it at a glance --}}
                                                <div class="flex flex-wrap items-center justify-between gap-x-3 gap-y-1.5 border-b border-gray-100 bg-gray-50/70 px-3 py-2">
                                                    <div class="flex items-center gap-2">
                                                        <svg class="h-3.5 w-3.5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.8">
                                                            <path stroke-linecap="round" stroke-linejoin="round" d="M7 7h.01M7 3h5a2 2 0 011.41.59l7 7a2 2 0 010 2.82l-5 5a2 2 0 01-2.82 0l-7-7A2 2 0 013 10V5a2 2 0 012-2z"/>
                                                        </svg>
                                                        <span class="text-xs font-semibold text-gray-700">Tags</span>
                                                        <span class="text-xs text-gray-400">existing tags are never touched</span>
                                                    </div>

                                                    <div class="flex items-center gap-2" x-show="tagsFor(item).length > 0">
                                                        <span class="rounded-full bg-white px-2 py-0.5 text-[11px] font-medium tabular-nums text-gray-500 ring-1 ring-gray-200">
                                                            <span x-text="item.selected_tags.length"></span> of <span x-text="tagsFor(item).length"></span> selected
                                                        </span>
                                                        <button type="button" @click="toggleAllTags(item)"
                                                            class="text-[11px] font-medium text-brand-600 hover:text-brand-700 hover:underline"
                                                            x-text="allTagsSelected(item) ? 'Clear all' : 'Select all'"></button>
                                                    </div>
                                                </div>

                                                <div class="p-3 space-y-3">

                                                    {{-- Each store merchandises differently, so the vocabulary is
                                                         configured per store. Without one, an empty dropdown would
                                                         read as a bug rather than as work not done yet. --}}
                                                    <div x-show="categories().length === 0"
                                                        class="rounded-md border border-dashed border-amber-200 bg-amber-50/60 px-3 py-3 text-xs text-amber-800">
                                                        No tag vocabulary is set up for
                                                        <strong class="font-semibold">{{ $aiContentSession->store?->name ?? 'this store' }}</strong> yet,
                                                        so no tags can be added from here. Everything else on this page still pushes normally.
                                                    </div>

                                                    {{-- Category narrows the types, so they read as one step each --}}
                                                    <div class="grid gap-2 sm:grid-cols-2" x-show="categories().length > 0">
                                                        <div>
                                                            <label class="mb-1 block text-[11px] font-medium uppercase tracking-wide text-gray-400">Category</label>
                                                            <select x-model="item.tag_category" @change="onCategoryChange(item)"
                                                                class="w-full rounded-md border border-gray-200 bg-white px-3 py-1.5 text-sm text-gray-700 focus:border-transparent focus:outline-none focus:ring-2 focus:ring-brand-400">
                                                                <option value="">Choose a category…</option>
                                                                <template x-for="category in categories()" :key="category">
                                                                    <option :value="category" x-text="category"></option>
                                                                </template>
                                                            </select>
                                                        </div>

                                                        <div>
                                                            <label class="mb-1 block text-[11px] font-medium uppercase tracking-wide text-gray-400">Type</label>
                                                            <select x-model="item.tag_type" @change="onTypeChange(item)"
                                                                :disabled="!item.tag_category"
                                                                class="w-full rounded-md border border-gray-200 bg-white px-3 py-1.5 text-sm text-gray-700 focus:border-transparent focus:outline-none focus:ring-2 focus:ring-brand-400 disabled:cursor-not-allowed disabled:bg-gray-50 disabled:text-gray-400">
                                                                <option value="" x-text="item.tag_category ? 'Choose a type…' : 'Pick a category first'"></option>
                                                                <template x-for="type in typesFor(item.tag_category)" :key="type">
                                                                    <option :value="type" x-text="type"></option>
                                                                </template>
                                                            </select>
                                                        </div>
                                                    </div>

                                                    {{-- The checkbox itself is hidden: at this many tags a grid of
                                                         small squares reads as noise, where filled pills read as a set. --}}
                                                    <div class="flex flex-wrap gap-1.5" x-show="tagsFor(item).length > 0">
                                                        <template x-for="tag in tagsFor(item)" :key="tag">
                                                            <label class="cursor-pointer select-none">
                                                                <input type="checkbox" class="peer sr-only" :name="`selected_tags[${item.id}][]`" :value="tag"
                                                                    x-model="item.selected_tags">
                                                                <span class="inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs font-medium transition-colors peer-focus-visible:ring-2 peer-focus-visible:ring-brand-400 peer-focus-visible:ring-offset-1"
                                                                    :class="item.selected_tags.includes(tag)
                                                                        ? 'border-brand-200 bg-brand-50 text-brand-700'
                                                                        : 'border-gray-200 bg-white text-gray-400 hover:border-gray-300 hover:bg-gray-50 hover:text-gray-600'">
                                                                    <svg class="h-3 w-3 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="3">
                                                                        <path x-show="item.selected_tags.includes(tag)" stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                                                                        <path x-show="!item.selected_tags.includes(tag)" stroke-linecap="round" stroke-linejoin="round" d="M12 5v14M5 12h14"/>
                                                                    </svg>
                                                                    <span x-text="tag"></span>
                                                                </span>
                                                            </label>
                                                        </template>
                                                    </div>

                                                    {{-- Nothing chosen yet: say what will happen, not just that it is empty --}}
                                                    <div x-show="!item.tag_type && categories().length > 0"
                                                        class="rounded-md border border-dashed border-gray-200 px-3 py-3 text-center text-xs text-gray-400">
                                                        Pick a category and type to load its tags.
                                                        No tags are added to this product until you do.
                                                    </div>
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
function aiContentShow(sessionId, initialStatus, tagTaxonomy) {
    return {
        sessionId,
        status: initialStatus,
        tagTaxonomy: tagTaxonomy || {},
        progress: 0,
        totalItems: 0,
        processedItems: 0,
        items: [],
        pollTimer: null,
        polling: false,
        itemsLoaded: false,

        init() {
            if (this.status === 'pending' || this.status === 'processing' || this.status === 'translating') {
                this.poll();
            } else if (this.status === 'ready' || this.status === 'done') {
                this.loadItems();
            }
        },

        poll() {
            this.pollTimer = setInterval(async () => {
                // Guard against overlapping ticks (e.g. a backgrounded tab firing
                // several queued ticks in a burst) — without this, two ticks can
                // both call loadItems() and silently reset every checkbox/edit.
                if (this.polling) return;
                this.polling = true;

                try {
                    const res  = await fetch(`/ai-content/${this.sessionId}/status`);
                    const data = await res.json();

                    this.status         = data.status;
                    this.progress       = data.progress;
                    this.totalItems     = data.total_items;
                    this.processedItems = data.processed_items;

                    if (this.status === 'ready' || this.status === 'done' || this.status === 'failed') {
                        clearInterval(this.pollTimer);
                        this.pollTimer = null;
                        if (this.status === 'ready' || this.status === 'done') {
                            this.loadItems();
                        }
                    }
                } finally {
                    this.polling = false;
                }
            }, 3000);
        },

        async loadItems() {
            // Only ever populate items once — reloading here would wipe out
            // any checkboxes/edits the user has already made in the browser.
            if (this.itemsLoaded) return;
            this.itemsLoaded = true;

            const res  = await fetch(`/ai-content/${this.sessionId}/items`);
            const data = await res.json();
            this.items = data.map(i => ({
                ...i,
                confirmed: i.is_confirmed,
                overwrite_title: false,
                tag_category: '',
                tag_type: '',
                selected_tags: [],
            }));
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

        categories() {
            return Object.keys(this.tagTaxonomy);
        },

        typesFor(category) {
            return Object.keys(this.tagTaxonomy[category]?.types || {});
        },

        // Base tags for the category plus the type's own, de-duplicated so a
        // tag listed in both is offered once.
        tagsFor(item) {
            const category = this.tagTaxonomy[item.tag_category];
            if (!category || !item.tag_type) return [];

            return [...new Set([...(category.base || []), ...(category.types?.[item.tag_type] || [])])];
        },

        onCategoryChange(item) {
            // The old type belongs to the old category; keeping it would leave
            // stale tags checked under a category that never had them.
            item.tag_type = '';
            item.selected_tags = [];
        },

        allTagsSelected(item) {
            const tags = this.tagsFor(item);
            return tags.length > 0 && tags.every(tag => item.selected_tags.includes(tag));
        },

        toggleAllTags(item) {
            item.selected_tags = this.allTagsSelected(item) ? [] : this.tagsFor(item);
        },

        onTypeChange(item) {
            // Every tag in the set is checked by default — unchecking is how
            // you opt out of one, which is the rarer case.
            item.selected_tags = this.tagsFor(item);
        },

        ucfirst(str) {
            if (!str) return '';
            return str.charAt(0).toUpperCase() + str.slice(1);
        },
    };
}
</script>
@endsection
