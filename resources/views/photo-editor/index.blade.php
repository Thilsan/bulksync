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
    <div class="mb-5 rounded-xl border border-red-200 bg-red-50 px-5 py-4 text-sm text-red-800">{{ session('error') }}</div>
    @endif

    @if ($errors->any())
    <div class="mb-5 rounded-xl border border-red-200 bg-red-50 px-5 py-4">
        <p class="text-sm font-medium text-red-800">Please check the form</p>
        <ul class="mt-1 list-inside list-disc text-sm text-red-700">
            @foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach
        </ul>
    </div>
    @endif

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

    @if ($isSandbox)
    <div class="mb-5 flex gap-3 rounded-xl border border-brand-200 bg-brand-50 px-5 py-4">
        <svg class="mt-0.5 h-5 w-5 shrink-0 text-brand-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.8">
            <path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
        </svg>
        <div>
            <p class="text-sm font-medium text-brand-800">Sandbox key in use</p>
            <p class="mt-0.5 text-sm text-brand-700">
                Edits are free (1,000 a month) but every result comes back <strong>watermarked</strong>.
                Swap to the live key when you're ready to push real images to Shopify.
            </p>
        </div>
    </div>
    @endif

    <form method="POST" action="{{ route('photo-editor.store') }}" @submit="loading = true"
          class="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_20rem] xl:grid-cols-[minmax(0,1fr)_23rem]">
        @csrf

        <div class="space-y-6">

            {{-- ── 1 · Source ────────────────────────────────────────────── --}}
            <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
                <div class="border-b border-gray-100 px-6 py-4">
                    <h2 class="text-sm font-semibold text-gray-800">1 &middot; Where are the photos?</h2>
                </div>

                <div class="space-y-4 px-6 py-5">
                    <div>
                        <label for="onedrive_link" class="mb-1.5 block text-sm font-medium text-gray-700">
                            OneDrive shared folder link <span class="text-red-500">*</span>
                        </label>
                        <input id="onedrive_link" name="onedrive_link" type="url" required
                               value="{{ old('onedrive_link') }}"
                               placeholder="https://1drv.ms/f/s!…  or  https://company.sharepoint.com/:f:/…"
                               class="w-full rounded-lg border px-4 py-2.5 text-sm transition focus:border-brand-500 focus:outline-none focus:ring-4 focus:ring-brand-500/15 {{ $errors->has('onedrive_link') ? 'border-red-400' : 'border-gray-300' }}">
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
                               class="w-full rounded-lg border border-gray-300 px-4 py-2.5 text-sm focus:border-brand-500 focus:outline-none focus:ring-4 focus:ring-brand-500/15">
                    </div>

                    <div>
                        <span class="mb-2 block text-sm font-medium text-gray-700">How should each photo find its product?</span>
                        <div class="grid gap-3 sm:grid-cols-2">
                            @foreach ([
                                ['sku_barcode', 'SKU / Barcode', 'Folder name is matched to the product SKU, falling back to the barcode.'],
                                ['style_code',  'Style Code',    'Folder or filename is matched to the style code starting the product title.'],
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

                    {{-- Straightening happens here, before the upload. A garment
                         lying across the frame gives every AI step below it the
                         wrong idea of which way is up — and the redrawing ones
                         answer that by inventing a front where the photo had a
                         back. --}}
                    <div class="border-t border-gray-100 pt-4">
                        <span class="mb-2 block text-sm font-medium text-gray-700">Are the photos lying on their side?</span>
                        <div class="grid gap-3 sm:grid-cols-2">
                            <div>
                                <select id="input_rotation" name="input_rotation" x-model="inputRotation"
                                        class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none">
                                    @foreach ($rotations as $value => $label)
                                        <option value="{{ $value }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <label x-show="inputRotation === 'right' || inputRotation === 'left'" x-cloak
                                   class="flex cursor-pointer items-start gap-2.5 text-xs text-gray-600">
                                <input type="checkbox" name="rotate_wide_only" value="1" x-model="rotateWideOnly"
                                       class="mt-0.5 h-4 w-4 rounded border-gray-300 text-brand-600 focus:ring-brand-500">
                                <span>Only turn the ones that are wider than they are tall — leave photos that are already upright alone.</span>
                            </label>
                        </div>
                        <p class="mt-2 text-xs leading-relaxed text-gray-400">
                            Photos that only <em>look</em> sideways because the camera stored a rotation flag are
                            straightened automatically. This is for the ones whose pixels really are on their side.
                        </p>
                    </div>

                </div>
            </div>

                <div class="flex items-center justify-between gap-3 border-t border-gray-100 bg-gray-50 px-6 py-4">
                    <a href="{{ route('photo-editor.history') }}" x-show="!loading" class="text-sm text-gray-500 hover:text-gray-700">Cancel</a>
                    <button type="submit" :disabled="loading || !{{ $photoroomConfigured ? 'true' : 'false' }}"
                        class="inline-flex items-center gap-2 rounded-lg bg-brand-600 px-6 py-2.5 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-brand-700 disabled:cursor-not-allowed disabled:opacity-60">
                        <span x-text="loading ? 'Reading the folder…' : 'Fetch photos'"></span>
                    </button>
                </div>
            </div>
        </div>

        {{-- ─────────────────────────── Helper column ─────────────────────────── --}}
        <aside class="space-y-4 lg:sticky lg:top-6">
            <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
                <div class="border-b border-gray-100 px-4 py-3">
                    <h2 class="text-sm font-semibold text-gray-800">What this run costs</h2>
                </div>
                <div class="space-y-2.5 px-4 py-4 text-xs leading-relaxed text-gray-600">
                    <p>Photoroom charges <strong class="text-gray-800">one credit per image</strong>, whatever
                       combination of edits you pick — so 40 photos is 40 credits.</p>
                    <p>A folder holding more than <strong class="text-gray-800">{{ number_format($maxImages) }}</strong>
                       images is refused before anything is sent.</p>
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
                        Edited files are kept for {{ $retentionDays }} days, then cleared automatically.
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
        loading:      false,
        showAdvanced: false,

        matchingMode: '{{ old('matching_mode', 'sku_barcode') }}',

        // Straightening
        inputRotation:  '{{ old('input_rotation', '') }}',
        {{-- Ticked by default, unlike every other checkbox here — for a mixed
             folder, turning only the sideways photos is the safe answer. An
             unticked box posts nothing, so old() cannot tell "left off" from
             "never submitted"; $errors->any() is what says this is a redisplay
             and the absence is therefore a real choice. --}}
        rotateWideOnly: {{ $errors->any() ? (old('rotate_wide_only') ? 'true' : 'false') : 'true' }},

        // Background
        backgroundMode:       '{{ old('background_mode', 'white') }}',
        backgroundColor:      '{{ old('background_color', '#FFFFFF') }}',
        backgroundPrompt:     @json(old('background_prompt', '')),
        backgroundImageUrl:   @json(old('background_image_url', '')),
        backgroundSeed:       '{{ old('background_seed', '') }}',
        backgroundBlurMode:   '{{ old('background_blur_mode', 'gaussian') }}',
        backgroundBlurRadius: {{ old('background_blur_radius', '0.02') ?: '0.02' }},

        // Clothing
        apparelMode: '{{ old('apparel_mode', 'none') }}',
        ironing:     {{ old('ironing') ? 'true' : 'false' }},

        // Shadow
        shadow:          '{{ old('shadow', '') }}',
        shadowSoftness:  {{ old('shadow_softness', '0.5') ?: '0.5' }},
        shadowIntensity: {{ old('shadow_intensity', '0.5') ?: '0.5' }},
        shadowSpread:    '{{ old('shadow_spread', '') }}',
        shadowDirection: '{{ old('shadow_direction', '') }}',
        shadowPose:      '{{ old('shadow_pose', '') }}',

        // Finishing
        beautify:     '{{ old('beautify', '') }}',
        lighting:     '{{ old('lighting', '') }}',
        upscale:      {{ old('upscale') ? 'true' : 'false' }},
        expand:       {{ old('expand') ? 'true' : 'false' }},

        // Output
        width:        {{ old('image_width', 'null') ?: 'null' }},
        height:       {{ old('image_height', 'null') ?: 'null' }},
        customMode:   false,
        padding:      {{ old('padding', '0') ?: '0' }},
        referenceBox: '{{ old('reference_box', 'subjectBox') }}',
        outputSizeMode: '{{ old('output_size_mode', 'auto') }}',
        exportFormat: '{{ old('export_format', 'auto') }}',
        showAdvanced: false,

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
