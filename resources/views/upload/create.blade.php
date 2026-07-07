@extends('layouts.app')
@section('title', 'New Bulk Upload')
@section('page-title', 'New Bulk Upload')

@section('content')
<div class="max-w-2xl mx-auto space-y-6" x-data="uploadForm()">

    {{-- Full-page loading overlay — shown while the server scans OneDrive + processes all images --}}
    <div x-show="loading" x-cloak
         class="fixed inset-0 z-50 flex items-center justify-center bg-gray-900/60 backdrop-blur-sm">
        <div class="bg-white rounded-2xl shadow-2xl px-10 py-8 max-w-sm w-full mx-4 text-center space-y-5">
            <div class="flex justify-center">
                <svg class="animate-spin h-12 w-12 text-brand-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-20" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                    <path class="opacity-80" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 22 6.477 22 12h-4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"/>
                </svg>
            </div>
            <div>
                <p class="text-gray-900 font-semibold text-lg">Uploading to Shopify</p>
                <p class="text-gray-500 text-sm mt-1">Scanning OneDrive folders and processing images…</p>
            </div>
            <div class="bg-amber-50 border border-amber-200 rounded-lg px-4 py-3">
                <p class="text-amber-700 text-xs font-medium">Please keep this tab open.</p>
                <p class="text-amber-600 text-xs mt-0.5">This can take a few minutes depending on the number of images.</p>
            </div>
        </div>
    </div>

    {{-- Config warnings --}}
    @if (!$shopifyConfigured || !$onedriveConfigured)
    <div class="bg-amber-50 border border-amber-300 rounded-xl px-5 py-4 flex gap-3">
        <svg class="w-5 h-5 text-amber-500 shrink-0 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
            <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
        </svg>
        <div>
            <p class="font-medium text-amber-800 text-sm">Configuration incomplete</p>
            <p class="text-amber-700 text-sm mt-0.5">
                @if (!$shopifyConfigured) Shopify credentials missing. @endif
                @if (!$onedriveConfigured) OneDrive credentials missing. @endif
                <a href="{{ route('settings.index') }}" class="underline font-medium">Go to Settings →</a>
            </p>
        </div>
    </div>
    @endif

    {{-- Upload form --}}
    <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
        <div class="px-6 py-5 border-b border-gray-100">
            <h2 class="font-semibold text-gray-800 text-lg">Upload Configuration</h2>
            <p class="text-sm text-gray-500 mt-1">
                Organise your OneDrive folder with <strong class="text-gray-700">subfolders named by item code</strong>
                — each subfolder name is matched to the Shopify product SKU, falling back to the barcode if no SKU matches.
                Filenames like "_var1", "_var2" are ignored when matching.
            </p>
        </div>

        <form method="POST" action="{{ route('upload.store') }}" class="px-6 py-5 space-y-6"
              @submit="loading = true">
            @csrf

            {{-- Session name --}}
            <div>
                <label for="name" class="block text-sm font-medium text-gray-700 mb-1.5">
                    Session name <span class="text-gray-400 font-normal">(optional)</span>
                </label>
                <input id="name" name="name" type="text" value="{{ old('name') }}"
                    placeholder="e.g. Summer 2024 Product Photos"
                    class="w-full rounded-lg border border-gray-300 px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent">
            </div>

            {{-- OneDrive link --}}
            <div>
                <label for="onedrive_link" class="block text-sm font-medium text-gray-700 mb-1.5">
                    OneDrive Shared Folder Link <span class="text-red-500">*</span>
                </label>
                <input id="onedrive_link" name="onedrive_link" type="url"
                    value="{{ old('onedrive_link') }}"
                    placeholder="https://1drv.ms/f/s!…  or  https://company.sharepoint.com/:f:/…"
                    required
                    class="w-full rounded-lg border px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent {{ $errors->has('onedrive_link') ? 'border-red-400' : 'border-gray-300' }}">
                @error('onedrive_link')
                    <p class="text-red-600 text-xs mt-1">{{ $message }}</p>
                @enderror
                <p class="text-xs text-gray-400 mt-1.5">
                    Share the folder with <strong>"Anyone with the link can view"</strong> and paste the link above.
                </p>
            </div>

            {{-- Image dimensions --}}
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">
                    Output Image Dimensions
                    <span class="text-gray-400 font-normal text-xs ml-1">(optional — leave blank to keep original size)</span>
                </label>

                {{-- Quick presets --}}
                <div class="flex flex-wrap gap-2 mb-3">
                    <button type="button"
                        @click="clearDimensions()"
                        :class="!width && !height && !customMode ? 'bg-gray-700 text-white border-gray-700' : 'bg-white text-gray-600 border-gray-300 hover:border-gray-400'"
                        class="px-3 py-1.5 rounded-lg border text-xs font-medium transition-colors">
                        No resize (keep original)
                    </button>
                    @foreach ($dimensionPresets as $preset)
                    <button type="button"
                        @click="setDimensions({{ $preset['width'] }}, {{ $preset['height'] }})"
                        :class="width == {{ $preset['width'] }} && height == {{ $preset['height'] }}
                            ? 'bg-brand-600 text-white border-brand-600'
                            : 'bg-white text-gray-700 border-gray-300 hover:border-brand-400 hover:text-brand-600'"
                        class="px-3 py-1.5 rounded-lg border text-xs font-medium transition-colors">
                        {{ $preset['width'] }} × {{ $preset['height'] }}
                        @if(str_contains($preset['label'], 'recommended'))
                            <span class="ml-1 opacity-70">(recommended)</span>
                        @endif
                    </button>
                    @endforeach
                    <button type="button"
                        @click="customMode = true; width = width || ''; height = height || ''"
                        :class="customMode ? 'bg-gray-700 text-white border-gray-700' : 'bg-white text-gray-600 border-gray-300 hover:border-gray-400'"
                        class="px-3 py-1.5 rounded-lg border text-xs font-medium transition-colors">
                        Custom…
                    </button>
                </div>

                {{-- Width × Height inputs --}}
                <div class="flex items-center gap-3">
                    <div class="flex-1">
                        <label class="block text-xs text-gray-500 mb-1">Width (px)</label>
                        <input type="number" name="image_width" id="image_width"
                            x-model="width"
                            min="100" max="5000"
                            @input="customMode = true"
                            class="w-full rounded-lg border px-4 py-2.5 text-sm text-center font-semibold focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent {{ $errors->has('image_width') ? 'border-red-400' : 'border-gray-300' }}">
                    </div>
                    <div class="text-gray-400 font-bold text-lg mt-4">×</div>
                    <div class="flex-1">
                        <label class="block text-xs text-gray-500 mb-1">Height (px)</label>
                        <input type="number" name="image_height" id="image_height"
                            x-model="height"
                            min="100" max="5000"
                            @input="customMode = true"
                            class="w-full rounded-lg border px-4 py-2.5 text-sm text-center font-semibold focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent {{ $errors->has('image_height') ? 'border-red-400' : 'border-gray-300' }}">
                    </div>
                    <div class="mt-4 text-xs text-gray-400">px</div>
                </div>

                @error('image_width') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror
                @error('image_height') <p class="text-red-600 text-xs mt-1">{{ $message }}</p> @enderror

                {{-- Live info box --}}
                <div class="mt-3 bg-brand-50 border border-brand-100 rounded-lg px-4 py-3 flex items-center gap-3">
                    <svg class="w-4 h-4 text-brand-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                    </svg>
                    <p class="text-brand-700 text-xs" x-show="width && height">
                        Images will be resized to exactly
                        <strong x-text="width + ' × ' + height + ' px'"></strong>,
                        cropped to fill if needed.
                        Quality starts at <strong>100%</strong> and is reduced only if the file exceeds <strong>1 MB</strong>.
                    </p>
                    <p class="text-brand-700 text-xs" x-show="!width || !height">
                        <strong>Original dimensions kept</strong> — images will only be compressed to stay under <strong>1 MB</strong> if needed.
                    </p>
                </div>
            </div>

            {{-- Duplicate handling: don't re-upload if the SKU/barcode already has an image on Shopify --}}
            <input type="hidden" name="duplicate_handling" value="skip">

            {{-- Submit --}}
            <div class="pt-1 flex items-center gap-3">
                <button type="submit" :disabled="loading"
                    class="bg-brand-600 hover:bg-brand-700 disabled:opacity-60 disabled:cursor-not-allowed text-white px-6 py-2.5 rounded-lg text-sm font-semibold transition-colors focus:outline-none focus:ring-2 focus:ring-brand-500 focus:ring-offset-2 flex items-center gap-2">
                    <svg x-show="loading" class="animate-spin h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 22 6.477 22 12h-4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"/>
                    </svg>
                    <span x-text="loading ? 'Starting…' : 'Start Upload'"></span>
                </button>
                <a href="{{ route('dashboard') }}" x-show="!loading" class="text-sm text-gray-500 hover:text-gray-700">Cancel</a>
            </div>
        </form>
    </div>


</div>

<script>
function uploadForm() {
    return {
        width:      {{ old('image_width', 'null') }},
        height:     {{ old('image_height', 'null') }},
        customMode: false,
        loading:    false,

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
