@extends('layouts.app')
@section('title', 'Photo Editor')
@section('page-title', 'Photo Editor')

@section('content')
{{-- The option cards hide their radios, so the card itself has to show focus. --}}
<style>
    .opt-card:focus-within { outline: 2px solid #439fc1; outline-offset: 2px; }
</style>

<div x-data="photoEditForm()">

    {{-- Held open while the scan starts; the review page takes over from there. --}}
    <div x-show="loading" x-cloak
         class="fixed inset-0 z-50 flex items-center justify-center bg-gray-900/60 p-4 backdrop-blur-sm">
        <div class="w-full max-w-sm space-y-5 rounded-2xl bg-white px-10 py-8 text-center shadow-2xl">
            <div class="flex justify-center">
                <svg class="h-12 w-12 animate-spin text-brand-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-20" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                    <path class="opacity-80" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 22 6.477 22 12h-4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"/>
                </svg>
            </div>
            <p class="text-lg font-semibold text-gray-900">Starting the edit</p>
            <p class="text-sm text-gray-500">Reading your OneDrive folder…</p>
        </div>
    </div>

    @if (session('error'))
    <div class="mb-5 rounded-xl border border-red-200 bg-red-50 px-5 py-4 text-sm text-red-800">
        {{ session('error') }}
    </div>
    @endif

    {{-- Nothing works without these two, so they come before the form. --}}
    @if (!$photoroomConfigured || !$onedriveConfigured)
    <div class="mb-5 flex gap-3 rounded-xl border border-amber-300 bg-amber-50 px-5 py-4">
        <svg class="mt-0.5 h-5 w-5 shrink-0 text-amber-500" fill="currentColor" viewBox="0 0 20 20">
            <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
        </svg>
        <div>
            <p class="text-sm font-medium text-amber-800">Editing can't run yet</p>
            <p class="mt-0.5 text-sm text-amber-700">
                @if (!$photoroomConfigured) No Photoroom API key is configured — add <code class="rounded bg-amber-100 px-1">PHOTOROOM_API_KEY</code> to the environment. @endif
                @if (!$onedriveConfigured) OneDrive is not connected. <a href="{{ route('settings.index') }}" class="font-medium underline">Open Settings →</a> @endif
            </p>
        </div>
    </div>
    @endif

    {{-- A sandbox key returns watermarked images. Without saying so, the first
         result looks like a broken edit rather than a free one. --}}
    @if ($isSandbox)
    <div class="mb-5 flex gap-3 rounded-xl border border-brand-200 bg-brand-50 px-5 py-4">
        <svg class="mt-0.5 h-5 w-5 shrink-0 text-brand-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.8">
            <path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
        </svg>
        <div>
            <p class="text-sm font-medium text-brand-800">Sandbox key in use</p>
            <p class="mt-0.5 text-sm text-brand-700">
                Edits are free (1,000 a month) but every result comes back <strong>watermarked</strong>.
                Swap in the live key when you're ready to push real images to Shopify.
            </p>
        </div>
    </div>
    @endif

    <form method="POST" action="{{ route('photo-editor.store') }}" @submit="loading = true"
          class="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_20rem] xl:grid-cols-[minmax(0,1fr)_23rem]">
        @csrf

        {{-- ─────────────────────────── Main column ─────────────────────────── --}}
        <div class="space-y-6">

            {{-- ── Source ────────────────────────────────────────────────── --}}
            <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
                <div class="border-b border-gray-100 px-6 py-4">
                    <h2 class="text-sm font-semibold text-gray-800">1 &middot; Where are the photos?</h2>
                </div>

                <div class="space-y-4 px-6 py-5">
                    <div>
                        <label for="onedrive_link" class="mb-1.5 block text-sm font-medium text-gray-700">
                            OneDrive shared folder link <span class="text-red-500">*</span>
                        </label>
                        <div class="relative">
                            <svg class="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400"
                                 fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.8">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M13.828 10.172a4 4 0 010 5.656l-3 3a4 4 0 01-5.656-5.656l1.5-1.5m4.5-4.5l1.5-1.5a4 4 0 015.656 5.656l-3 3a4 4 0 01-5.656 0"/>
                            </svg>
                            <input id="onedrive_link" name="onedrive_link" type="url" required
                                   value="{{ old('onedrive_link') }}"
                                   placeholder="https://1drv.ms/f/s!…  or  https://company.sharepoint.com/:f:/…"
                                   class="w-full rounded-lg border py-2.5 pl-10 pr-4 text-sm transition focus:border-brand-500 focus:outline-none focus:ring-4 focus:ring-brand-500/15 {{ $errors->has('onedrive_link') ? 'border-red-400' : 'border-gray-300' }}">
                        </div>
                        @error('onedrive_link') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        <p class="mt-1.5 text-xs text-gray-400">
                            Share it as <strong class="font-semibold text-gray-500">"Anyone with the link can view"</strong>, then paste the link.
                        </p>
                    </div>

                    <div>
                        <label for="name" class="mb-1.5 block text-sm font-medium text-gray-700">
                            Session name <span class="font-normal text-gray-400">(optional)</span>
                        </label>
                        <input id="name" name="name" type="text" value="{{ old('name') }}"
                               placeholder="e.g. Autumn dresses — cutouts"
                               class="w-full rounded-lg border border-gray-300 px-4 py-2.5 text-sm transition focus:border-brand-500 focus:outline-none focus:ring-4 focus:ring-brand-500/15">
                    </div>

                    <div>
                        <span class="mb-2 block text-sm font-medium text-gray-700">How should each photo find its product?</span>
                        <div class="grid gap-3 sm:grid-cols-2">
                            @foreach ([
                                ['sku_barcode', 'SKU / Barcode', 'Folder name is matched to the product SKU, falling back to the barcode. The image joins the variant and the gallery.'],
                                ['style_code',  'Style Code',    'Folder or filename is matched to the style code starting the product title. The image joins the gallery only.'],
                            ] as [$value, $label, $help])
                            <label class="opt-card cursor-pointer rounded-xl border p-4 transition-colors"
                                   :class="matchingMode === '{{ $value }}' ? 'border-brand-500 bg-brand-50/60 ring-1 ring-brand-500' : 'border-gray-200 hover:border-gray-300'">
                                <input type="radio" name="matching_mode" value="{{ $value }}" x-model="matchingMode" class="sr-only"
                                       @checked(old('matching_mode', 'sku_barcode') === $value)>
                                <span class="text-sm font-semibold text-gray-800">{{ $label }}</span>
                                <p class="mt-1 text-xs leading-relaxed text-gray-500">{{ $help }}</p>
                            </label>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>

            {{-- ── Background ────────────────────────────────────────────── --}}
            <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
                <div class="border-b border-gray-100 px-6 py-4">
                    <h2 class="text-sm font-semibold text-gray-800">2 &middot; Background</h2>
                </div>

                <div class="space-y-4 px-6 py-5">
                    <label class="flex cursor-pointer items-start gap-3">
                        <input type="checkbox" name="remove_background" value="1" x-model="removeBackground"
                               class="mt-0.5 h-4 w-4 rounded border-gray-300 text-brand-600 focus:ring-brand-500">
                        <span>
                            <span class="block text-sm font-medium text-gray-800">Remove the background</span>
                            <span class="block text-xs text-gray-500">Cuts the product out of whatever it was shot against.</span>
                        </span>
                    </label>

                    <div x-show="removeBackground" x-cloak class="grid gap-3 sm:grid-cols-3">
                        @foreach ([
                            ['white',       'Solid white',  'The safe choice for Shopify listings.'],
                            ['transparent', 'Transparent',  'Saved as PNG. Shows through onto the theme.'],
                            ['custom',      'Custom colour','Pick any brand background.'],
                        ] as [$value, $label, $help])
                        <label class="opt-card cursor-pointer rounded-xl border p-3.5 transition-colors"
                               :class="backgroundMode === '{{ $value }}' ? 'border-brand-500 bg-brand-50/60 ring-1 ring-brand-500' : 'border-gray-200 hover:border-gray-300'">
                            <input type="radio" name="background_mode" value="{{ $value }}" x-model="backgroundMode" class="sr-only"
                                   @checked(old('background_mode', 'white') === $value)>
                            <span class="text-sm font-semibold text-gray-800">{{ $label }}</span>
                            <p class="mt-0.5 text-xs leading-relaxed text-gray-500">{{ $help }}</p>
                        </label>
                        @endforeach
                    </div>

                    <div x-show="removeBackground && backgroundMode === 'custom'" x-cloak class="flex items-center gap-3">
                        <input type="color" x-model="backgroundColor"
                               class="h-10 w-14 cursor-pointer rounded-lg border border-gray-300 bg-white p-1">
                        <input type="text" name="background_color" x-model="backgroundColor" maxlength="7"
                               class="w-32 rounded-lg border border-gray-300 px-3 py-2 font-mono text-sm uppercase focus:border-brand-500 focus:outline-none focus:ring-4 focus:ring-brand-500/15">
                        @error('background_color') <p class="text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>

                    {{-- Not a warning to hide: a transparent PNG on a white theme
                         looks fine, on a dark one it does not. --}}
                    <div x-show="removeBackground && backgroundMode === 'transparent'" x-cloak
                         class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-xs text-amber-700">
                        Transparent images are saved as PNG — larger files, and they take on whatever colour your
                        storefront theme sits them on. Solid white is the usual choice for product listings.
                    </div>
                </div>
            </div>

            {{-- ── Clothing ──────────────────────────────────────────────── --}}
            <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
                <div class="border-b border-gray-100 px-6 py-4">
                    <h2 class="text-sm font-semibold text-gray-800">3 &middot; Clothing treatment</h2>
                    <p class="mt-0.5 text-xs text-gray-500">Only affects apparel — other products pass through untouched.</p>
                </div>

                <div class="grid gap-3 px-6 py-5 sm:grid-cols-3">
                    @foreach ([
                        ['none',            'Leave as shot',   'No reshaping. Background settings still apply.'],
                        ['ghost_mannequin', 'Remove mannequin','Takes out the mannequin or dress form and rebuilds the garment so it holds its own shape.'],
                        ['flat_lay',        'Flat lay',        'Rebuilds the garment laid flat, as if photographed from above.'],
                    ] as [$value, $label, $help])
                    <label class="opt-card cursor-pointer rounded-xl border p-4 transition-colors"
                           :class="apparelMode === '{{ $value }}' ? 'border-brand-500 bg-brand-50/60 ring-1 ring-brand-500' : 'border-gray-200 hover:border-gray-300'">
                        <input type="radio" name="apparel_mode" value="{{ $value }}" x-model="apparelMode" class="sr-only"
                               @checked(old('apparel_mode', 'none') === $value)>
                        <span class="text-sm font-semibold text-gray-800">{{ $label }}</span>
                        <p class="mt-1 text-xs leading-relaxed text-gray-500">{{ $help }}</p>
                    </label>
                    @endforeach
                </div>

                <div x-show="apparelMode !== 'none'" x-cloak class="border-t border-gray-100 bg-amber-50/60 px-6 py-3">
                    <p class="text-xs text-amber-700">
                        These redraw the garment rather than just masking it, so they take longer per image and are
                        worth checking one by one on the review screen before pushing.
                    </p>
                </div>
            </div>

            {{-- ── Finishing ─────────────────────────────────────────────── --}}
            <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
                <div class="border-b border-gray-100 px-6 py-4">
                    <h2 class="text-sm font-semibold text-gray-800">4 &middot; Finishing <span class="font-normal text-gray-400">(optional)</span></h2>
                </div>

                <div class="space-y-5 px-6 py-5">
                    <div>
                        <span class="mb-2 block text-sm font-medium text-gray-700">Drop shadow</span>
                        <div class="flex flex-wrap gap-2">
                            @foreach ([['', 'None'], ['ai.soft', 'Soft'], ['ai.hard', 'Hard'], ['ai.floating', 'Floating']] as [$value, $label])
                            <button type="button" @click="shadow = '{{ $value }}'"
                                :class="shadow === '{{ $value }}' ? 'border-brand-600 bg-brand-600 text-white' : 'border-gray-300 bg-white text-gray-700 hover:border-brand-400'"
                                class="rounded-lg border px-3.5 py-1.5 text-xs font-medium transition-colors">{{ $label }}</button>
                            @endforeach
                        </div>
                        <input type="hidden" name="shadow" :value="shadow">
                    </div>

                    <div>
                        <span class="mb-2 block text-sm font-medium text-gray-700">Remove text from the image</span>
                        <div class="flex flex-wrap gap-2">
                            @foreach ([['', 'Keep all text'], ['ai.artificial', 'Added graphics only'], ['ai.natural', 'Printed on the product'], ['ai.all', 'Everything']] as [$value, $label])
                            <button type="button" @click="textRemoval = '{{ $value }}'"
                                :class="textRemoval === '{{ $value }}' ? 'border-brand-600 bg-brand-600 text-white' : 'border-gray-300 bg-white text-gray-700 hover:border-brand-400'"
                                class="rounded-lg border px-3.5 py-1.5 text-xs font-medium transition-colors">{{ $label }}</button>
                            @endforeach
                        </div>
                        <input type="hidden" name="text_removal" :value="textRemoval">
                    </div>

                    <div class="grid gap-3 sm:grid-cols-2">
                        <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-gray-200 p-4 transition-colors hover:border-gray-300">
                            <input type="checkbox" name="lighting" value="1"
                                   class="mt-0.5 h-4 w-4 rounded border-gray-300 text-brand-600 focus:ring-brand-500"
                                   @checked(old('lighting'))>
                            <span>
                                <span class="block text-sm font-medium text-gray-800">Even out the lighting</span>
                                <span class="block text-xs text-gray-500">Fixes harsh or uneven studio light.</span>
                            </span>
                        </label>

                        <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-gray-200 p-4 transition-colors hover:border-gray-300">
                            <input type="checkbox" name="upscale" value="1"
                                   class="mt-0.5 h-4 w-4 rounded border-gray-300 text-brand-600 focus:ring-brand-500"
                                   @checked(old('upscale'))>
                            <span>
                                <span class="block text-sm font-medium text-gray-800">Upscale</span>
                                <span class="block text-xs text-gray-500">Adds detail to small or soft source photos.</span>
                            </span>
                        </label>
                    </div>
                </div>
            </div>

            {{-- ── Size and placement ────────────────────────────────────── --}}
            <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
                <div class="border-b border-gray-100 px-6 py-4">
                    <h2 class="text-sm font-semibold text-gray-800">5 &middot; Size &amp; placement</h2>
                </div>

                <div class="space-y-5 px-6 py-5">
                    <div>
                        <span class="mb-2 block text-sm font-medium text-gray-700">Output size</span>
                        <div class="flex flex-wrap gap-2">
                            <button type="button" @click="clearDimensions()"
                                :class="!width && !height && !customMode ? 'border-gray-800 bg-gray-800 text-white' : 'border-gray-300 bg-white text-gray-600 hover:border-gray-400'"
                                class="rounded-lg border px-3 py-1.5 text-xs font-medium transition-colors">
                                Keep original
                            </button>
                            @foreach ([[2048, 2048, 'Shopify recommended'], [1200, 1200, null], [1000, 1000, null], [800, 800, null], [600, 600, null]] as [$w, $h, $note])
                            <button type="button" @click="setDimensions({{ $w }}, {{ $h }})"
                                :class="width == {{ $w }} && height == {{ $h }} ? 'border-brand-600 bg-brand-600 text-white' : 'border-gray-300 bg-white text-gray-700 hover:border-brand-400 hover:text-brand-600'"
                                class="rounded-lg border px-3 py-1.5 text-xs font-medium transition-colors">
                                {{ $w }} × {{ $h }}@if ($note)<span class="ml-1 opacity-70">({{ $note }})</span>@endif
                            </button>
                            @endforeach
                            <button type="button" @click="customMode = true"
                                :class="customMode ? 'border-gray-800 bg-gray-800 text-white' : 'border-gray-300 bg-white text-gray-600 hover:border-gray-400'"
                                class="rounded-lg border px-3 py-1.5 text-xs font-medium transition-colors">
                                Custom…
                            </button>
                        </div>

                        <div x-show="customMode || (width && height)" x-cloak class="mt-4 flex items-end gap-3">
                            <div class="flex-1 max-w-[10rem]">
                                <label for="image_width" class="mb-1 block text-xs text-gray-500">Width (px)</label>
                                <input type="number" name="image_width" id="image_width" x-model="width" min="100" max="5000"
                                       @input="customMode = true"
                                       class="w-full rounded-lg border border-gray-300 px-4 py-2.5 text-center text-sm font-semibold focus:border-brand-500 focus:outline-none focus:ring-4 focus:ring-brand-500/15">
                            </div>
                            <div class="pb-2.5 text-lg font-bold text-gray-400">×</div>
                            <div class="flex-1 max-w-[10rem]">
                                <label for="image_height" class="mb-1 block text-xs text-gray-500">Height (px)</label>
                                <input type="number" name="image_height" id="image_height" x-model="height" min="100" max="5000"
                                       @input="customMode = true"
                                       class="w-full rounded-lg border border-gray-300 px-4 py-2.5 text-center text-sm font-semibold focus:border-brand-500 focus:outline-none focus:ring-4 focus:ring-brand-500/15">
                            </div>
                        </div>
                        @error('image_width') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        @error('image_height') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <div class="mb-2 flex items-baseline justify-between">
                            <span class="text-sm font-medium text-gray-700">Breathing room around the product</span>
                            <span class="text-xs font-semibold tabular-nums text-brand-700" x-text="Math.round(padding * 100) + '%'"></span>
                        </div>
                        <input type="range" name="padding" x-model="padding" min="0" max="0.4" step="0.01"
                               class="w-full accent-brand-600">
                        <p class="mt-1 text-xs text-gray-400">Blank margin kept between the product and the edge of the frame.</p>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-3">
                        <div>
                            <label for="h_align" class="mb-1.5 block text-xs font-medium text-gray-600">Horizontal position</label>
                            <select id="h_align" name="h_align" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none">
                                @foreach (['center' => 'Centred', 'left' => 'Left', 'right' => 'Right'] as $value => $label)
                                    <option value="{{ $value }}" @selected(old('h_align', 'center') === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="v_align" class="mb-1.5 block text-xs font-medium text-gray-600">Vertical position</label>
                            <select id="v_align" name="v_align" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none">
                                @foreach (['center' => 'Centred', 'top' => 'Top', 'bottom' => 'Bottom'] as $value => $label)
                                    <option value="{{ $value }}" @selected(old('v_align', 'center') === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="scaling" class="mb-1.5 block text-xs font-medium text-gray-600">Scaling</label>
                            <select id="scaling" name="scaling" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none">
                                <option value="fit" @selected(old('scaling', 'fit') === 'fit')>Fit — show the whole product</option>
                                <option value="fill" @selected(old('scaling') === 'fill')>Fill — crop to the edges</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="flex items-center justify-between gap-3 border-t border-gray-100 bg-gray-50 px-6 py-4">
                    <a href="{{ route('photo-editor.history') }}" x-show="!loading" class="text-sm text-gray-500 hover:text-gray-700">Cancel</a>
                    <button type="submit" :disabled="loading || !{{ $photoroomConfigured ? 'true' : 'false' }}"
                        class="inline-flex items-center gap-2 rounded-lg bg-brand-600 px-6 py-2.5 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-brand-700 disabled:cursor-not-allowed disabled:opacity-60">
                        <span x-text="loading ? 'Starting…' : 'Fetch & edit photos'"></span>
                    </button>
                </div>
            </div>
        </div>

        {{-- ─────────────────────────── Helper column ─────────────────────────── --}}
        <aside class="space-y-4 lg:sticky lg:top-6">

            {{-- Cost is the one thing you cannot undo after the fact. --}}
            <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
                <div class="border-b border-gray-100 px-4 py-3">
                    <h2 class="text-sm font-semibold text-gray-800">What this run costs</h2>
                </div>
                <div class="space-y-2.5 px-4 py-4 text-xs leading-relaxed text-gray-600">
                    <p>Photoroom charges <strong class="text-gray-800">one credit per image</strong>, whatever
                       combination of edits you pick — so 40 photos is 40 credits.</p>
                    <p>A folder holding more than <strong class="text-gray-800">{{ number_format($maxImages) }}</strong>
                       images is refused before anything is sent, so a link pasted one level too high can't quietly
                       spend the month's quota.</p>
                    <p class="text-gray-500">Nothing reaches Shopify until you pick the images on the next screen.</p>
                </div>
            </div>

            <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
                <div class="border-b border-gray-100 px-4 py-3">
                    <h2 class="text-sm font-semibold text-gray-800">Folder layout</h2>
                    <p class="mt-0.5 text-xs text-gray-500">Same layout the bulk uploader reads.</p>
                </div>
                <div class="px-4 py-4">
                    <pre class="overflow-x-auto text-[11px] leading-relaxed text-gray-600"><code>Shared folder/
├── <span class="font-semibold text-brand-700">AB-1234</span>/        <span class="text-gray-400">SKU</span>
│   ├── front.jpg
│   └── back.jpg
└── <span class="font-semibold text-brand-700">5901234123457</span>/  <span class="text-gray-400">barcode</span>
    └── main.jpg</code></pre>
                </div>
            </div>

            <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
                <div class="border-b border-gray-100 px-4 py-3">
                    <h2 class="text-sm font-semibold text-gray-800">What happens next</h2>
                </div>
                <ol class="divide-y divide-gray-100 text-xs">
                    @foreach ([
                        'Every image in the folder is fetched and edited.',
                        'You compare before and after, side by side.',
                        'You tick the ones worth keeping.',
                        'Only those get pushed to ' . ($activeStore?->name ?? 'your store') . '.',
                    ] as $i => $step)
                    <li class="flex gap-2.5 px-4 py-3">
                        <span class="grid h-4 w-4 shrink-0 place-items-center rounded-full bg-brand-100 text-[10px] font-bold text-brand-700">{{ $i + 1 }}</span>
                        <span class="text-gray-600">{{ $step }}</span>
                    </li>
                    @endforeach
                </ol>
                <div class="border-t border-gray-100 bg-gray-50 px-4 py-3">
                    <p class="text-[11px] leading-relaxed text-gray-500">
                        Edited files are kept for {{ $retentionDays }} days so you can come back to a run,
                        then cleared automatically.
                    </p>
                </div>
            </div>

            @if ($recent->isNotEmpty())
            <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
                <div class="flex items-center justify-between border-b border-gray-100 px-4 py-3">
                    <h2 class="text-sm font-semibold text-gray-800">Recent runs</h2>
                    <a href="{{ route('photo-editor.history') }}" class="text-xs font-medium text-brand-600 hover:text-brand-700">All →</a>
                </div>
                <div class="divide-y divide-gray-100">
                    @foreach ($recent as $run)
                    <a href="{{ route('photo-editor.show', $run) }}" class="block px-4 py-3 transition-colors hover:bg-gray-50">
                        <p class="truncate text-xs font-medium text-gray-800">{{ $run->name }}</p>
                        <p class="mt-0.5 truncate text-[11px] text-gray-400">
                            {{ $run->edited_files }} edited · {{ $run->pushed_files }} pushed · {{ $run->created_at->diffForHumans() }}
                        </p>
                    </a>
                    @endforeach
                </div>
            </div>
            @endif
        </aside>
    </form>
</div>

<script>
function photoEditForm() {
    return {
        loading:          false,
        matchingMode:     '{{ old('matching_mode', 'sku_barcode') }}',
        removeBackground: {{ old('remove_background', '1') ? 'true' : 'false' }},
        backgroundMode:   '{{ old('background_mode', 'white') }}',
        backgroundColor:  '{{ old('background_color', '#FFFFFF') }}',
        apparelMode:      '{{ old('apparel_mode', 'none') }}',
        shadow:           '{{ old('shadow', '') }}',
        textRemoval:      '{{ old('text_removal', '') }}',
        width:            {{ old('image_width', 'null') ?: 'null' }},
        height:           {{ old('image_height', 'null') ?: 'null' }},
        customMode:       false,
        padding:          {{ old('padding', '0') ?: '0' }},

        setDimensions(w, h) {
            this.width = w;
            this.height = h;
            this.customMode = false;
        },

        clearDimensions() {
            this.width = null;
            this.height = null;
            this.customMode = false;
        },
    };
}
</script>
@endsection
