@extends('layouts.app')
@section('title', 'New Bulk Upload')
@section('page-title', 'New Bulk Upload')

@section('content')
{{-- The mode radios are visually hidden, so the card itself has to show keyboard focus. --}}
<style>
    .mode-card:focus-within { outline: 2px solid #439fc1; outline-offset: 2px; }
</style>

<div class="mx-auto max-w-6xl" x-data="uploadForm()">

    {{-- Full-page loading overlay — shown while the server scans OneDrive + processes all images --}}
    <div x-show="loading" x-cloak
         class="fixed inset-0 z-50 flex items-center justify-center bg-gray-900/60 p-4 backdrop-blur-sm">
        <div class="w-full max-w-sm space-y-5 rounded-2xl bg-white px-10 py-8 text-center shadow-2xl">
            <div class="flex justify-center">
                <svg class="h-12 w-12 animate-spin text-brand-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-20" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                    <path class="opacity-80" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 22 6.477 22 12h-4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"/>
                </svg>
            </div>
            <div>
                <p class="text-lg font-semibold text-gray-900">Uploading to Shopify</p>
                <p class="mt-1 text-sm text-gray-500">Scanning OneDrive folders and processing images…</p>
            </div>
            <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3">
                <p class="text-xs font-medium text-amber-700">Please keep this tab open.</p>
                <p class="mt-0.5 text-xs text-amber-600">This can take a few minutes depending on the number of images.</p>
            </div>
        </div>
    </div>

    {{-- Config warnings --}}
    @if (!$shopifyConfigured || !$onedriveConfigured)
    <div class="mb-5 flex gap-3 rounded-xl border border-amber-300 bg-amber-50 px-5 py-4">
        <svg class="mt-0.5 h-5 w-5 shrink-0 text-amber-500" fill="currentColor" viewBox="0 0 20 20">
            <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
        </svg>
        <div>
            <p class="text-sm font-medium text-amber-800">Configuration incomplete</p>
            <p class="mt-0.5 text-sm text-amber-700">
                @if (!$shopifyConfigured) Shopify credentials missing. @endif
                @if (!$onedriveConfigured) OneDrive credentials missing. @endif
                <a href="{{ route('settings.index') }}" class="font-medium underline">Go to Settings →</a>
            </p>
        </div>
    </div>
    @endif

    <form method="POST" action="{{ route('upload.store') }}" @submit="loading = true"
          class="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_19rem]">
        @csrf

        {{-- ─────────────────────────── Main column ─────────────────────────── --}}
        <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">

            {{-- Matching mode --}}
            <fieldset class="px-6 py-5">
                <legend class="text-sm font-semibold text-gray-800">How should images find their product?</legend>
                <p class="mt-1 text-sm text-gray-500">This decides what each folder name is compared against.</p>

                <div class="mt-4 grid gap-3 sm:grid-cols-2">
                    <label class="mode-card relative cursor-pointer rounded-xl border p-4 transition-colors"
                           :class="matchingMode === 'sku_barcode'
                               ? 'border-brand-500 bg-brand-50/60 ring-1 ring-brand-500'
                               : 'border-gray-200 hover:border-gray-300'">
                        {{-- @checked keeps a mode selected even before Alpine boots --}}
                        <input type="radio" name="matching_mode" value="sku_barcode" x-model="matchingMode" class="sr-only"
                               @checked(old('matching_mode', 'sku_barcode') === 'sku_barcode')>
                        <div class="flex items-start justify-between gap-2">
                            <span class="text-sm font-semibold text-gray-800">SKU / Barcode</span>
                            <svg class="h-4 w-4 shrink-0 text-brand-600" x-show="matchingMode === 'sku_barcode'" x-cloak
                                 fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                            </svg>
                        </div>
                        <p class="mt-1 text-xs leading-relaxed text-gray-500">
                            Folder name is matched to the product SKU, falling back to the barcode.
                            Images join the variant and the gallery.
                        </p>
                    </label>

                    <label class="mode-card relative cursor-pointer rounded-xl border p-4 transition-colors"
                           :class="matchingMode === 'style_code'
                               ? 'border-brand-500 bg-brand-50/60 ring-1 ring-brand-500'
                               : 'border-gray-200 hover:border-gray-300'">
                        <input type="radio" name="matching_mode" value="style_code" x-model="matchingMode" class="sr-only"
                               @checked(old('matching_mode') === 'style_code')>
                        <div class="flex items-start justify-between gap-2">
                            <span class="text-sm font-semibold text-gray-800">Style Code</span>
                            <svg class="h-4 w-4 shrink-0 text-brand-600" x-show="matchingMode === 'style_code'" x-cloak
                                 fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                            </svg>
                        </div>
                        <p class="mt-1 text-xs leading-relaxed text-gray-500">
                            Folder or filename is matched to the style code starting the product title.
                            Images join the gallery only.
                        </p>
                    </label>
                </div>
            </fieldset>

            {{-- Source --}}
            <div class="border-t border-gray-100 px-6 py-5">
                <h2 class="text-sm font-semibold text-gray-800">Where are the images?</h2>

                <div class="mt-4 space-y-4">
                    <div>
                        <label for="onedrive_link" class="mb-1.5 block text-sm font-medium text-gray-700">
                            OneDrive shared folder link <span class="text-red-500">*</span>
                        </label>
                        <div class="relative">
                            <svg class="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400"
                                 fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.8">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M13.828 10.172a4 4 0 010 5.656l-3 3a4 4 0 01-5.656-5.656l1.5-1.5m4.5-4.5l1.5-1.5a4 4 0 015.656 5.656l-3 3a4 4 0 01-5.656 0"/>
                            </svg>
                            <input id="onedrive_link" name="onedrive_link" type="url"
                                   value="{{ old('onedrive_link') }}"
                                   placeholder="https://1drv.ms/f/s!…  or  https://company.sharepoint.com/:f:/…"
                                   required
                                   class="w-full rounded-lg border py-2.5 pl-10 pr-4 text-sm transition focus:border-brand-500 focus:outline-none focus:ring-4 focus:ring-brand-500/15 {{ $errors->has('onedrive_link') ? 'border-red-400' : 'border-gray-300' }}">
                        </div>
                        @error('onedrive_link')
                            <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                        @enderror
                        <p class="mt-1.5 text-xs text-gray-400">
                            Share the folder with <strong class="font-semibold text-gray-500">"Anyone with the link can view"</strong>, then paste the link.
                        </p>
                    </div>

                    <div>
                        <label for="name" class="mb-1.5 block text-sm font-medium text-gray-700">
                            Session name <span class="font-normal text-gray-400">(optional)</span>
                        </label>
                        <input id="name" name="name" type="text" value="{{ old('name') }}"
                               placeholder="e.g. Summer 2024 Product Photos"
                               class="w-full rounded-lg border border-gray-300 px-4 py-2.5 text-sm transition focus:border-brand-500 focus:outline-none focus:ring-4 focus:ring-brand-500/15">
                        <p class="mt-1.5 text-xs text-gray-400">Helps you find this run later in Upload History.</p>
                    </div>
                </div>
            </div>

            {{-- Output size --}}
            <div class="border-t border-gray-100 px-6 py-5">
                <h2 class="text-sm font-semibold text-gray-800">Output image size</h2>
                <p class="mt-1 text-sm text-gray-500">Leave on <em>keep original</em> unless your theme needs a fixed size.</p>

                <div class="mt-4 flex flex-wrap gap-2">
                    <button type="button" @click="clearDimensions()"
                        :class="!width && !height && !customMode
                            ? 'border-gray-800 bg-gray-800 text-white'
                            : 'border-gray-300 bg-white text-gray-600 hover:border-gray-400'"
                        class="rounded-lg border px-3 py-1.5 text-xs font-medium transition-colors">
                        No resize (keep original)
                    </button>
                    @foreach ($dimensionPresets as $preset)
                    <button type="button" @click="setDimensions({{ $preset['width'] }}, {{ $preset['height'] }})"
                        :class="width == {{ $preset['width'] }} && height == {{ $preset['height'] }}
                            ? 'border-brand-600 bg-brand-600 text-white'
                            : 'border-gray-300 bg-white text-gray-700 hover:border-brand-400 hover:text-brand-600'"
                        class="rounded-lg border px-3 py-1.5 text-xs font-medium transition-colors">
                        {{ $preset['width'] }} × {{ $preset['height'] }}
                        @if(str_contains($preset['label'], 'recommended'))
                            <span class="ml-1 opacity-70">(recommended)</span>
                        @endif
                    </button>
                    @endforeach
                    <button type="button" @click="customMode = true; width = width || ''; height = height || ''"
                        :class="customMode
                            ? 'border-gray-800 bg-gray-800 text-white'
                            : 'border-gray-300 bg-white text-gray-600 hover:border-gray-400'"
                        class="rounded-lg border px-3 py-1.5 text-xs font-medium transition-colors">
                        Custom…
                    </button>
                </div>

                {{-- Width × Height only matter once you leave the default, so they stay out of the way until then --}}
                <div x-show="customMode || width || height" x-cloak class="mt-4 flex items-end gap-3">
                    <div class="flex-1">
                        <label for="image_width" class="mb-1 block text-xs text-gray-500">Width (px)</label>
                        <input type="number" name="image_width" id="image_width" x-model="width"
                               min="100" max="5000" @input="customMode = true"
                               class="w-full rounded-lg border px-4 py-2.5 text-center text-sm font-semibold transition focus:border-brand-500 focus:outline-none focus:ring-4 focus:ring-brand-500/15 {{ $errors->has('image_width') ? 'border-red-400' : 'border-gray-300' }}">
                    </div>
                    <div class="pb-2.5 text-lg font-bold text-gray-400">×</div>
                    <div class="flex-1">
                        <label for="image_height" class="mb-1 block text-xs text-gray-500">Height (px)</label>
                        <input type="number" name="image_height" id="image_height" x-model="height"
                               min="100" max="5000" @input="customMode = true"
                               class="w-full rounded-lg border px-4 py-2.5 text-center text-sm font-semibold transition focus:border-brand-500 focus:outline-none focus:ring-4 focus:ring-brand-500/15 {{ $errors->has('image_height') ? 'border-red-400' : 'border-gray-300' }}">
                    </div>
                    <div class="pb-3 text-xs text-gray-400">px</div>
                </div>

                @error('image_width') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                @error('image_height') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror

                <div class="mt-3 flex items-center gap-3 rounded-lg border border-brand-100 bg-brand-50 px-4 py-3">
                    <svg class="h-4 w-4 shrink-0 text-brand-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round"
                            d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                    </svg>
                    <p class="text-xs text-brand-700" x-show="width && height">
                        Images will be resized to exactly
                        <strong x-text="width + ' × ' + height + ' px'"></strong>, cropped to fill if needed.
                        Quality starts at <strong>100%</strong> and drops only if the file exceeds <strong>1 MB</strong>.
                    </p>
                    <p class="text-xs text-brand-700" x-show="!width || !height">
                        <strong>Original dimensions kept</strong> — images are only compressed to stay under <strong>1 MB</strong> if needed.
                    </p>
                </div>
            </div>

            {{-- Duplicate handling: don't re-upload if the SKU/barcode already has an image on Shopify --}}
            <input type="hidden" name="duplicate_handling" value="skip">

            {{-- Actions --}}
            <div class="flex items-center justify-between gap-3 border-t border-gray-100 bg-gray-50 px-6 py-4">
                <a href="{{ route('upload.dashboard') }}" x-show="!loading" class="text-sm text-gray-500 hover:text-gray-700">Cancel</a>
                <button type="submit" :disabled="loading"
                    class="inline-flex items-center gap-2 rounded-lg bg-brand-600 px-6 py-2.5 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-brand-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60">
                    <svg x-show="loading" x-cloak class="h-4 w-4 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 22 6.477 22 12h-4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"/>
                    </svg>
                    <span x-text="loading ? 'Starting…' : 'Start Upload'"></span>
                </button>
            </div>
        </div>

        {{-- ─────────────────────────── Helper column ─────────────────────────── --}}
        <aside class="space-y-4 lg:sticky lg:top-6">

            {{-- Folder layout, matched to the mode you picked --}}
            <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
                <div class="border-b border-gray-100 px-4 py-3">
                    <h2 class="text-sm font-semibold text-gray-800">Folder layout</h2>
                    <p class="mt-0.5 text-xs text-gray-500" x-show="matchingMode === 'sku_barcode'">
                        Name each subfolder after the item code.
                    </p>
                    <p class="mt-0.5 text-xs text-gray-500" x-show="matchingMode === 'style_code'" x-cloak>
                        Name each subfolder after the style code.
                    </p>
                </div>

                <div class="px-4 py-4">
                    <pre class="overflow-x-auto text-[11px] leading-relaxed text-gray-600" x-show="matchingMode === 'sku_barcode'"><code>Shared folder/
├── <span class="font-semibold text-brand-700">AB-1234</span>/        <span class="text-gray-400">SKU</span>
│   ├── front.jpg
│   └── back.jpg
└── <span class="font-semibold text-brand-700">5901234123457</span>/  <span class="text-gray-400">barcode</span>
    └── main.jpg</code></pre>

                    <pre class="overflow-x-auto text-[11px] leading-relaxed text-gray-600" x-show="matchingMode === 'style_code'" x-cloak><code>Shared folder/
├── <span class="font-semibold text-brand-700">STYLE-CODE</span>/
│   ├── 01.jpg
│   └── 02.jpg
└── <span class="font-semibold text-brand-700">STYLE-CODE</span>_front.jpg</code></pre>

                    <p class="mt-3 text-xs leading-relaxed text-gray-500" x-show="matchingMode === 'style_code'" x-cloak>
                        Not organised into subfolders? The style code has to appear in the
                        <strong class="font-semibold text-gray-600">filename</strong> instead.
                    </p>
                </div>
            </div>

            {{-- Facts worth knowing before committing a few thousand images --}}
            <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
                <div class="border-b border-gray-100 px-4 py-3">
                    <h2 class="text-sm font-semibold text-gray-800">Before you start</h2>
                </div>
                <ul class="divide-y divide-gray-100 text-xs">
                    <li class="flex gap-2.5 px-4 py-3">
                        <svg class="mt-px h-3.5 w-3.5 shrink-0 text-emerald-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                        </svg>
                        <span class="text-gray-600">
                            Uploading to
                            <strong class="font-semibold text-gray-800">{{ $activeStore?->name ?? 'no store selected' }}</strong>
                            — switch stores from the top bar.
                        </span>
                    </li>
                    <li class="flex gap-2.5 px-4 py-3">
                        <svg class="mt-px h-3.5 w-3.5 shrink-0 text-emerald-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                        </svg>
                        <span class="text-gray-600">Products that already have an image are skipped, so a re-run is safe.</span>
                    </li>
                    <li class="flex gap-2.5 px-4 py-3">
                        <svg class="mt-px h-3.5 w-3.5 shrink-0 text-emerald-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                        </svg>
                        <span class="text-gray-600">Folders that match nothing are reported as <em>no match</em> rather than failing the run.</span>
                    </li>
                    <li class="flex gap-2.5 px-4 py-3">
                        <svg class="mt-px h-3.5 w-3.5 shrink-0 text-amber-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m0 3.25h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
                        </svg>
                        <span class="text-gray-600">Keep this tab open while the run scans and uploads.</span>
                    </li>
                </ul>
            </div>
        </aside>
    </form>
</div>

<script>
function uploadForm() {
    return {
        width:       {{ old('image_width', 'null') }},
        height:      {{ old('image_height', 'null') }},
        customMode:  false,
        loading:     false,
        matchingMode: '{{ old('matching_mode', 'sku_barcode') }}',

        setDimensions(w, h) {
            this.width      = w;
            this.height     = h;
            this.customMode = false;
        },

        clearDimensions() {
            this.width      = null;
            this.height     = null;
            this.customMode = false;
        },
    };
}
</script>
@endsection
