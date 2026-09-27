@extends('layouts.app')
@section('title', 'New Bulk Upload')
@section('page-title', 'New Bulk Upload')

@section('content')
{{-- The mode radios are visually hidden, so the card itself has to show keyboard focus. --}}
<style>
    .mode-card:focus-within { outline: 2px solid #439fc1; outline-offset: 2px; }
</style>

{{-- Full-bleed, matching the upload dashboard --}}
<div x-data="uploadForm()">

    {{-- Full-page loading overlay — shown while the server scans OneDrive + processes all images --}}
    <div x-show="loading" x-cloak
         class="fixed inset-0 z-50 flex items-center justify-center bg-gray-900/60 p-4 backdrop-blur-sm">
        <div class="w-full max-w-sm space-y-5 rounded-2xl bg-white px-10 py-8 text-center shadow-2xl">
            <div class="flex justify-center">
                <div class="spinner h-12 w-12"></div>
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
          class="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_20rem] xl:grid-cols-[minmax(0,1fr)_23rem]">
        @csrf

        {{--
            Four steps, numbered, because this genuinely is a sequence: you
            cannot choose how folders are matched before you have said where
            they are. Each step is its own card rather than a band in one long
            panel — a heading followed by a sentence of explanation, four times
            over, read as a document instead of as a form.
        --}}
        <div class="space-y-4">

            {{-- 1 · Source --}}
            <section class="rounded-xl border border-gray-200 bg-white p-5">
                <div class="mb-4 flex items-center gap-3">
                    <span class="figure grid h-7 w-7 place-items-center rounded-full bg-brand-600 text-sm text-white">1</span>
                    <h2 class="font-display text-lg leading-none text-gray-900">Where are the images?</h2>
                </div>

                <div class="grid gap-4 2xl:grid-cols-2">
                    <div>
                        <div class="relative">
                            <svg class="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400"
                                 fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.8">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M13.828 10.172a4 4 0 010 5.656l-3 3a4 4 0 01-5.656-5.656l1.5-1.5m4.5-4.5l1.5-1.5a4 4 0 015.656 5.656l-3 3a4 4 0 01-5.656 0"/>
                            </svg>
                            <input id="onedrive_link" name="onedrive_link" type="url"
                                   value="{{ old('onedrive_link') }}"
                                   placeholder="Paste the OneDrive folder link"
                                   required
                                   class="w-full rounded-lg border py-3 pl-10 pr-4 text-sm transition focus:border-brand-500 focus:outline-none focus:ring-4 focus:ring-brand-500/15 {{ $errors->has('onedrive_link') ? 'border-red-400' : 'border-gray-300' }}">
                        </div>
                        @error('onedrive_link')
                            <p class="mt-1.5 text-xs text-red-600">{{ $message }}</p>
                        @else
                            <p class="mt-1.5 text-xs text-gray-400">Shared as <strong class="font-semibold text-gray-500">anyone with the link can view</strong>.</p>
                        @enderror
                    </div>

                    <div>
                        <input id="name" name="name" type="text" value="{{ old('name') }}"
                               placeholder="Name this run (optional)"
                               class="w-full rounded-lg border border-gray-300 px-4 py-3 text-sm transition focus:border-brand-500 focus:outline-none focus:ring-4 focus:ring-brand-500/15">
                        <p class="mt-1.5 text-xs text-gray-400">Helps you find it in Upload History.</p>
                    </div>
                </div>
            </section>

            {{-- 2 · Matching --}}
            <fieldset class="rounded-xl border border-gray-200 bg-white p-5">
                <div class="mb-4 flex items-center gap-3">
                    <span class="figure grid h-7 w-7 place-items-center rounded-full bg-brand-600 text-sm text-white">2</span>
                    <legend class="font-display text-lg leading-none text-gray-900">How should a folder find its product?</legend>
                </div>

                <div class="grid gap-3 sm:grid-cols-2">
                    @foreach ([
                        ['sku_barcode', 'SKU / Barcode', 'Matched to the SKU, then the barcode. Joins the variant and the gallery.'],
                        ['style_code',  'Style Code',    'Matched to the code starting the product title. Joins the gallery only.'],
                    ] as [$value, $label, $help])
                        <label class="mode-card group relative cursor-pointer rounded-xl border p-4 transition-all"
                               :class="matchingMode === '{{ $value }}'
                                   ? 'border-brand-600 bg-brand-50/70 ring-1 ring-brand-600'
                                   : 'border-gray-200 hover:border-brand-300 hover:bg-brand-50/30'">
                            {{-- @checked keeps a mode selected even before Alpine boots --}}
                            <input type="radio" name="matching_mode" value="{{ $value }}" x-model="matchingMode" class="sr-only"
                                   @checked(old('matching_mode', 'sku_barcode') === $value)>
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <span class="text-sm font-semibold text-gray-900">{{ $label }}</span>
                                    <p class="mt-1 text-xs leading-relaxed text-gray-500">{{ $help }}</p>
                                </div>
                                <span class="mt-0.5 grid h-5 w-5 shrink-0 place-items-center rounded-full border transition-colors"
                                      :class="matchingMode === '{{ $value }}' ? 'border-brand-600 bg-brand-600' : 'border-gray-300'">
                                    <svg class="h-3 w-3 text-white" x-show="matchingMode === '{{ $value }}'" x-cloak
                                         fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="3">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                                    </svg>
                                </span>
                            </div>
                        </label>
                    @endforeach
                </div>
            </fieldset>

            {{-- 3 · Size --}}
            <section class="rounded-xl border border-gray-200 bg-white p-5">
                <div class="mb-4 flex flex-wrap items-center gap-3">
                    <span class="figure grid h-7 w-7 place-items-center rounded-full bg-brand-600 text-sm text-white">3</span>
                    <h2 class="font-display text-lg leading-none text-gray-900">Output size</h2>
                    {{-- The consequence of the choice, where the choice is made. --}}
                    <span class="ml-auto rounded-full bg-gray-100 px-3 py-1 text-xs font-medium text-gray-600">
                        <span x-show="!width || !height">Original size · under 1 MB</span>
                        <span x-show="width && height" x-cloak x-text="width + ' × ' + height + ' px · cropped to fill'"></span>
                    </span>
                </div>

                <div class="inline-flex flex-wrap gap-1 rounded-xl bg-gray-100 p-1">
                    <button type="button" @click="clearDimensions()"
                        :class="!width && !height && !customMode ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500 hover:text-gray-800'"
                        class="rounded-lg px-3 py-1.5 text-xs font-semibold transition-all">Keep original</button>
                    @foreach ($dimensionPresets as $preset)
                        <button type="button" @click="setDimensions({{ $preset['width'] }}, {{ $preset['height'] }})"
                            :class="width == {{ $preset['width'] }} && height == {{ $preset['height'] }} ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500 hover:text-gray-800'"
                            class="rounded-lg px-3 py-1.5 text-xs font-semibold transition-all">
                            {{ $preset['width'] }} × {{ $preset['height'] }}
                        </button>
                    @endforeach
                    <button type="button" @click="customMode = true; width = width || ''; height = height || ''"
                        :class="customMode ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500 hover:text-gray-800'"
                        class="rounded-lg px-3 py-1.5 text-xs font-semibold transition-all">Custom</button>
                </div>

                {{-- Width × Height only matter once you leave the default, so they stay out of the way until then --}}
                <div x-show="customMode || width || height" x-cloak class="mt-4 flex items-end gap-3">
                    <div class="flex-1">
                        <label for="image_width" class="mb-1 block text-xs text-gray-500">Width</label>
                        <input type="number" name="image_width" id="image_width" x-model="width"
                               min="100" max="5000" @input="customMode = true"
                               class="w-full rounded-lg border px-4 py-2.5 text-center text-sm font-semibold transition focus:border-brand-500 focus:outline-none focus:ring-4 focus:ring-brand-500/15 {{ $errors->has('image_width') ? 'border-red-400' : 'border-gray-300' }}">
                    </div>
                    <div class="pb-2.5 text-lg font-bold text-gray-300">×</div>
                    <div class="flex-1">
                        <label for="image_height" class="mb-1 block text-xs text-gray-500">Height</label>
                        <input type="number" name="image_height" id="image_height" x-model="height"
                               min="100" max="5000" @input="customMode = true"
                               class="w-full rounded-lg border px-4 py-2.5 text-center text-sm font-semibold transition focus:border-brand-500 focus:outline-none focus:ring-4 focus:ring-brand-500/15 {{ $errors->has('image_height') ? 'border-red-400' : 'border-gray-300' }}">
                    </div>
                </div>

                @error('image_width') <p class="mt-1.5 text-xs text-red-600">{{ $message }}</p> @enderror
                @error('image_height') <p class="mt-1.5 text-xs text-red-600">{{ $message }}</p> @enderror
            </section>

            {{-- 4 · Existing images --}}
            <fieldset class="rounded-xl border border-gray-200 bg-white p-5">
                <div class="mb-4 flex items-center gap-3">
                    <span class="figure grid h-7 w-7 place-items-center rounded-full bg-brand-600 text-sm text-white">4</span>
                    <legend class="font-display text-lg leading-none text-gray-900">If the SKU already has an image</legend>
                </div>

                <div class="grid gap-3 sm:grid-cols-2">
                    @foreach ([
                        ['replace', 'Overwrite it', 'The old photo is deleted. One shared with another colour is left alone.'],
                        ['skip',    'Leave it alone', 'Reported as Already Has Image. Nothing on Shopify changes.'],
                    ] as [$value, $label, $help])
                        <label class="mode-card group relative cursor-pointer rounded-xl border p-4 transition-all"
                               :class="duplicateHandling === '{{ $value }}'
                                   ? 'border-brand-600 bg-brand-50/70 ring-1 ring-brand-600'
                                   : 'border-gray-200 hover:border-brand-300 hover:bg-brand-50/30'">
                            {{-- @checked keeps a choice selected even before Alpine boots --}}
                            <input type="radio" name="duplicate_handling" value="{{ $value }}" x-model="duplicateHandling" class="sr-only"
                                   @checked(old('duplicate_handling', 'replace') === $value)>
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <span class="text-sm font-semibold text-gray-900">{{ $label }}</span>
                                    <p class="mt-1 text-xs leading-relaxed text-gray-500">{{ $help }}</p>
                                </div>
                                <span class="mt-0.5 grid h-5 w-5 shrink-0 place-items-center rounded-full border transition-colors"
                                      :class="duplicateHandling === '{{ $value }}' ? 'border-brand-600 bg-brand-600' : 'border-gray-300'">
                                    <svg class="h-3 w-3 text-white" x-show="duplicateHandling === '{{ $value }}'" x-cloak
                                         fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="3">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                                    </svg>
                                </span>
                            </div>
                        </label>
                    @endforeach
                </div>

                {{-- The one thing on this page that cannot be undone. --}}
                <div x-show="duplicateHandling === 'replace'" x-cloak
                     class="mt-3 flex items-start gap-2.5 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3">
                    <svg class="mt-px h-4 w-4 shrink-0 text-amber-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
                    </svg>
                    <p class="text-xs leading-relaxed text-amber-800">
                        Deleting a Shopify photo cannot be undone from here, including photos added by hand in the admin.
                    </p>
                </div>
            </fieldset>

            {{-- Start. Sticky, so it is reachable from any step rather than only
                 from the bottom of a page four cards long. --}}
            <div class="sticky bottom-4 z-10 flex items-center justify-between gap-3 rounded-xl border border-gray-200 bg-white/95 px-5 py-3.5 shadow-lg backdrop-blur">
                <a href="{{ route('upload.dashboard') }}" x-show="!loading" class="text-sm text-gray-500 transition-colors hover:text-gray-800">Cancel</a>
                <button type="submit" :disabled="loading"
                    class="inline-flex items-center gap-2 rounded-lg bg-brand-600 px-6 py-2.5 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-brand-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60">
                    <span x-show="loading" x-cloak class="spinner h-4 w-4"></span>
                    <span x-text="loading ? 'Starting…' : 'Start upload'"></span>
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

            {{--
                What this run will actually do, as its own settings rather than
                as advice. It was four sentences of prose; the two that change
                what happens are now chips that track the form, and the one
                warning that matters stays as a line.
            --}}
            <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
                <div class="border-b border-gray-100 px-4 py-3">
                    <h2 class="text-sm font-semibold text-gray-800">This run</h2>
                </div>
                <div class="space-y-2.5 px-4 py-4">
                    <div class="flex items-center gap-2">
                        <span class="h-1.5 w-1.5 shrink-0 rounded-full {{ $activeStore ? 'bg-emerald-500' : 'bg-red-400' }}"></span>
                        <span class="text-xs text-gray-500">Uploads to</span>
                        <span class="truncate rounded-md bg-gray-100 px-2 py-0.5 text-xs font-semibold text-gray-800">
                            {{ $activeStore?->name ?? 'No store selected' }}
                        </span>
                    </div>

                    <div class="flex items-center gap-2">
                        <span class="h-1.5 w-1.5 shrink-0 rounded-full"
                              :class="duplicateHandling === 'replace' ? 'bg-amber-500' : 'bg-gray-300'"></span>
                        <span class="text-xs text-gray-500">Existing images</span>
                        <span class="rounded-md px-2 py-0.5 text-xs font-semibold"
                              :class="duplicateHandling === 'replace' ? 'bg-amber-50 text-amber-800' : 'bg-gray-100 text-gray-700'"
                              x-text="duplicateHandling === 'replace' ? 'Overwritten' : 'Left alone'"></span>
                    </div>

                    <p class="border-t border-gray-100 pt-2.5 text-xs text-gray-400">
                        Keep this tab open while the run scans and uploads.
                    </p>
                </div>
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
        duplicateHandling: '{{ old('duplicate_handling', 'replace') }}',

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
