{{--
    "New Product Creation Request" panel, filling the page area.
    Expects the parent Alpine scope to expose `newRequestOpen`.
--}}
<div x-show="newRequestOpen" x-cloak @keydown.escape.window="newRequestOpen = false"
     {{-- Covers the page area only: the sidebar and top bar stay in view. --}}
     x-data="{ top: 0, measure() { const bar = document.querySelector('header.topbar'); this.top = bar ? bar.getBoundingClientRect().bottom : 0; } }"
     x-init="measure()" x-effect="if (newRequestOpen) measure()" @resize.window="measure()"
     :style="{ top: top + 'px' }"
     {{-- No opacity here: a fading parent stops the browser drawing the blur
          until the fade ends. This only holds the panel open while its
          children animate out. --}}
     x-transition:leave="duration-200"
     class="fixed inset-x-0 bottom-0 lg:left-64 z-30 flex">

    {{-- The glass: fades and blurs in together. It never moves — moving a blurred layer stutters. --}}
    <div class="absolute inset-0 bg-white/40 backdrop-blur-2xl backdrop-saturate-150" @click="newRequestOpen = false"
         x-show="newRequestOpen"
         x-transition:enter="transition-[opacity,backdrop-filter] ease-out duration-300" x-transition:enter-start="opacity-0 backdrop-blur-none" x-transition:enter-end="opacity-100 backdrop-blur-2xl"
         x-transition:leave="transition-[opacity,backdrop-filter] ease-in duration-200" x-transition:leave-start="opacity-100 backdrop-blur-2xl" x-transition:leave-end="opacity-0 backdrop-blur-none"></div>

    {{-- The form glides up a touch as the glass fades in. --}}
    <div class="relative w-full h-full flex flex-col overflow-clip will-change-transform"
         x-show="newRequestOpen"
         x-transition:enter="transition ease-[cubic-bezier(.16,1,.3,1)] duration-500" x-transition:enter-start="opacity-0 translate-y-6" x-transition:enter-end="opacity-100 translate-y-0"
         x-transition:leave="transition ease-in duration-200" x-transition:leave-start="opacity-100 translate-y-0" x-transition:leave-end="opacity-0 translate-y-3">

        <div class="px-4 sm:px-6 py-4 bg-white/60 border-b border-white/60 shrink-0">
            <div class="max-w-6xl mx-auto flex items-center justify-between">
                <h2 class="text-base font-semibold text-gray-900">New Product Creation Request</h2>
                {{-- Actions live up here, Shopify-style: always in view, never under the chat button. --}}
                <div class="flex items-center gap-3">
                    <button type="submit" form="new-request-form"
                            class="text-white text-sm font-medium px-4 py-2 rounded-lg bg-brand-600 hover:bg-brand-700 shadow-sm transition-colors">
                        Submit request
                    </button>
                    <button type="button" @click="newRequestOpen = false" aria-label="Close" title="Close"
                            class="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-red-50 text-red-600 ring-1 ring-red-100 transition-colors hover:bg-red-600 hover:text-white hover:ring-red-600 focus:outline-none focus:ring-2 focus:ring-red-400">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>
            </div>
        </div>

        <form id="new-request-form" method="POST" action="{{ route('product-requests.store') }}" enctype="multipart/form-data"
              class="flex-1 flex flex-col min-h-0 overflow-clip"
              x-init="$watch('imageSource', () => clearLocationIfNotSupplier())"
              x-data="{
                  useAi: '{{ old('use_ai_content', '1') }}',
                  imageSource: '{{ old('image_source', '') }}',
                  imagesAt: '{{ old('images_location', '') }}',
                  imagesUrl: '{{ old('images_url', '') }}',
                  get needsPhotoshoot() { return this.imageSource === '{{ \App\Models\ProductRequest::IMG_PHOTOSHOOT }}'; },
                  get needsEditing() { return this.imageSource !== '' && this.imageSource !== '{{ \App\Models\ProductRequest::IMG_SUPPLIER }}'; },
                  clearLocationIfNotSupplier() {
                      if (this.imageSource !== '{{ \App\Models\ProductRequest::IMG_SUPPLIER }}') {
                          this.imagesAt = '';
                          this.imagesUrl = '';
                      }
                  },
                  onlineDate: '{{ old('online_launch_date') }}',
                  todayIso: '{{ now()->format('Y-m-d\TH:i') }}',
                  {{-- Js::from, not a quoted string — "Men's Fashion" would break out of it. --}}
                  category: {{ Illuminate\Support\Js::from(old('category', '')) }},
                  categoryOwners: {{ Illuminate\Support\Js::from($categoryOwnerNames ?? []) }},
                  categoryBrandManagers: {{ Illuminate\Support\Js::from($categoryBrandManagerNames ?? []) }},
                  photoshootCoordinator: {{ Illuminate\Support\Js::from($photoshootCoordinator ?? null) }},
                  get categoryOwner() { return this.categoryOwners[this.category] || ''; },
                  // No brand manager set for the category: the owner keeps the role.
                  get categoryBrandManager() { return this.categoryBrandManagers[this.category] || this.categoryOwner; },
                  storeId: '{{ old('store_id', $stores->contains('id', $activeStoreId) ? $activeStoreId : $stores->first()?->id) }}',
                  mappingSites: {{ Illuminate\Support\Js::from($stores->where('requires_sku_mapping', true)->pluck('id')->map(fn ($id) => (string) $id)->values()) }},
                  get usesMapping() { return this.mappingSites.includes(String(this.storeId)) },
              }">
            @csrf

            <div class="flex-1 min-h-0 overflow-y-auto px-4 pt-6 pb-24 sm:px-6">
                <div class="max-w-6xl mx-auto grid grid-cols-1 lg:grid-cols-3 gap-5 items-start">

                    <div class="lg:col-span-2 space-y-4">
                        {{-- Request: name + notes, the "title and description" of it --}}
                        <section class="bg-white/90 rounded-xl border border-white shadow-[0_8px_30px_-12px_rgba(15,23,42,.18)] p-4 space-y-4">
                            <div>
                                <label class="block text-sm text-gray-700 mb-1">Request name <span class="text-gray-400">(optional)</span></label>
                                <input type="text" name="name" value="{{ old('name') }}" maxlength="255"
                                       placeholder="e.g. New Balance Running SS26 launch" class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent">
                            </div>
                            <div>
                                <label class="block text-sm text-gray-700 mb-1">Notes <span class="text-gray-400">(optional)</span></label>
                                <textarea name="notes" rows="3" placeholder="Anything the team should know"
                                          class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent resize-y">{{ old('notes') }}</textarea>
                            </div>
                        </section>

                        {{-- Products --}}
                        <section class="bg-white/90 rounded-xl border border-white shadow-[0_8px_30px_-12px_rgba(15,23,42,.18)] p-4">
                            <h3 class="text-sm font-semibold text-gray-900 mb-3">Products</h3>

                            <label x-data="{ fileName: '', over: false }"
                                   @dragover.prevent="over = true" @dragleave.prevent="over = false"
                                   @drop.prevent="over = false; $refs.skuCsv.files = $event.dataTransfer.files; fileName = $refs.skuCsv.files[0]?.name || ''"
                                   class="relative flex flex-col items-center justify-center gap-1.5 cursor-pointer rounded-lg border-2 border-dashed px-4 py-8 text-center transition-colors"
                                   :class="over ? 'border-brand-500 bg-brand-50' : (fileName ? 'border-brand-300 bg-brand-50/50' : 'border-gray-300 hover:bg-gray-50')">
                                <svg class="w-7 h-7" :class="fileName ? 'text-brand-600' : 'text-gray-400'" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path x-show="!fileName" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/>
                                    <path x-show="fileName" x-cloak stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                                </svg>
                                <span class="text-sm font-medium text-gray-800" x-text="fileName || 'Upload SKU file'"></span>
                                <span class="text-xs text-gray-400" x-text="fileName ? 'Click to change' : 'CSV · drag and drop or click'"></span>
                                <input type="file" name="sku_csv" accept=".csv,.txt" x-ref="skuCsv" required class="sr-only"
                                       @change="fileName = $el.files[0]?.name || ''">
                            </label>
                        </section>

                        {{-- Images --}}
                        <section class="bg-white/90 rounded-xl border border-white shadow-[0_8px_30px_-12px_rgba(15,23,42,.18)] p-4">
                            <h3 class="text-sm font-semibold text-gray-900 mb-3">Images</h3>

                            {{-- One answer, three real options. This used to be two yes/no
                                 questions that could contradict each other. --}}
                            <div class="space-y-2">
                                @foreach(\App\Models\ProductRequest::selectableImageSources() as $value => $meta)
                                    <label class="flex items-center gap-3 cursor-pointer rounded-lg border px-3 py-2.5 transition-colors"
                                           :class="imageSource === '{{ $value }}' ? 'border-brand-500 bg-brand-50' : 'border-gray-200 bg-white hover:bg-gray-50'">
                                        <input type="radio" name="image_source" value="{{ $value }}" x-model="imageSource" required class="text-brand-600 focus:ring-brand-500">
                                        <span class="text-sm text-gray-800">{{ $meta['label'] }}</span>
                                    </label>
                                @endforeach
                            </div>

                            {{-- Only supplier images have a location to record — a photoshoot
                                 has nothing to point at until it has happened. --}}
                            <div x-show="imageSource === '{{ \App\Models\ProductRequest::IMG_SUPPLIER }}'" x-cloak class="mt-4 pt-4 border-t border-gray-100">
                                <label class="block text-sm text-gray-700 mb-1">Where are they?</label>
                                <div class="flex rounded-lg bg-gray-100 p-1 gap-1 sm:w-2/3">
                                    @foreach(\App\Models\ProductRequest::IMAGE_LOCATIONS as $value => $label)
                                        <label class="relative flex-1 text-center cursor-pointer rounded-md px-3 py-1.5 text-sm text-gray-600 transition-colors hover:text-gray-900 has-[:checked]:bg-white has-[:checked]:text-gray-900 has-[:checked]:font-medium has-[:checked]:shadow-sm">
                                            <input type="radio" name="images_location" value="{{ $value }}" x-model="imagesAt" class="sr-only">
                                            {{ $label }}
                                        </label>
                                    @endforeach
                                </div>

                                <div x-show="imagesAt === '{{ \App\Models\ProductRequest::IMAGES_AT_URL }}'" x-cloak class="mt-3">
                                    <input type="url" name="images_url" x-model="imagesUrl" maxlength="2048"
                                           placeholder="Paste the folder link" class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent">
                                </div>
                            </div>
                        </section>

                        {{-- Content --}}
                        <section class="bg-white/90 rounded-xl border border-white shadow-[0_8px_30px_-12px_rgba(15,23,42,.18)] p-4">
                            <h3 class="text-sm font-semibold text-gray-900 mb-3">Product descriptions</h3>

                            <div class="space-y-2">
                                <label class="flex items-center gap-3 cursor-pointer rounded-lg border px-3 py-2.5 transition-colors"
                                       :class="useAi === '1' ? 'border-brand-500 bg-brand-50' : 'border-gray-200 bg-white hover:bg-gray-50'">
                                    <input type="radio" name="use_ai_content" value="1" x-model="useAi" required class="text-brand-600 focus:ring-brand-500">
                                    <span class="text-sm text-gray-800">Write them with AI</span>
                                </label>
                                <label class="flex items-center gap-3 cursor-pointer rounded-lg border px-3 py-2.5 transition-colors"
                                       :class="useAi === '0' ? 'border-brand-500 bg-brand-50' : 'border-gray-200 bg-white hover:bg-gray-50'">
                                    <input type="radio" name="use_ai_content" value="0" x-model="useAi" class="text-brand-600 focus:ring-brand-500">
                                    <span class="text-sm text-gray-800">Brand team will send them</span>
                                </label>
                            </div>

                            <div x-show="useAi === '0'" x-cloak class="mt-4 pt-4 border-t border-gray-100">
                                <label class="block text-sm text-gray-700 mb-1">Content sheet <span class="text-gray-400">(optional)</span></label>
                                <input type="file" name="content_sheet" accept=".csv,.xlsx,.xls"
                                       class="w-full text-sm text-gray-600 file:mr-3 file:py-2 file:px-3 file:rounded-lg file:border file:border-gray-300 file:bg-white file:text-sm file:font-medium file:text-gray-700 hover:file:bg-gray-50 cursor-pointer">
                                <p class="text-xs text-gray-400 mt-1">Excel or CSV, up to {{ \App\Models\ProductRequestAttachment::maxUploadLabel() }}</p>
                            </div>
                        </section>

                    </div>

                    <div class="space-y-4">
                        <section class="bg-white/90 rounded-xl border border-white shadow-[0_8px_30px_-12px_rgba(15,23,42,.18)] p-4">
                            <div>
                                <h3 class="text-sm font-semibold text-gray-900 mb-3">Website</h3>
                                @if($stores->isEmpty())
                                    <p class="text-sm text-red-600 bg-red-50 border border-red-200 rounded-lg px-3 py-2">
                                        You don't have access to any website yet. Ask an admin to grant store access before raising a request.
                                    </p>
                                @else
                                    @include('product-requests.partials.pretty-select', [
                                        'name' => 'store_id', 'model' => 'storeId', 'placeholder' => 'Select a website',
                                        'options' => $stores->pluck('name', 'id'),
                                    ])
                                @endif
                            </div>
                        </section>

                        <section class="bg-white/90 rounded-xl border border-white shadow-[0_8px_30px_-12px_rgba(15,23,42,.18)] p-4 space-y-4">
                            <h3 class="text-sm font-semibold text-gray-900">Brand</h3>
                            <div>
                                <input type="text" name="brand" value="{{ old('brand') }}" required placeholder="e.g. Mosafer" aria-label="Brand name" class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent">
                            </div>
                            <div>
                                <label class="block text-sm text-gray-700 mb-1">Category</label>
                                @include('product-requests.partials.pretty-select', [
                                    'name' => 'category', 'model' => 'category', 'placeholder' => 'Select a category',
                                    'options' => array_combine(\App\Models\ProductRequest::CATEGORIES, \App\Models\ProductRequest::CATEGORIES),
                                ])
                            </div>
                            <div>
                                <label class="block text-sm text-gray-700 mb-1">New to the website?</label>
                                <div class="flex rounded-lg bg-gray-100 p-1 gap-1">
                                    @foreach(['new_brand' => 'Yes', 'existing_brand' => 'No'] as $value => $label)
                                        <label class="relative flex-1 text-center cursor-pointer rounded-md px-3 py-1.5 text-sm text-gray-600 transition-colors hover:text-gray-900 has-[:checked]:bg-white has-[:checked]:text-gray-900 has-[:checked]:font-medium has-[:checked]:shadow-sm">
                                            <input type="radio" name="request_type" value="{{ $value }}" class="sr-only"
                                                   {{ old('request_type', 'new_brand') === $value ? 'checked' : '' }}>
                                            {{ $label }}
                                        </label>
                                    @endforeach
                                </div>
                            </div>
                        </section>

                        {{-- Go-live --}}
                        <section class="bg-white/90 rounded-xl border border-white shadow-[0_8px_30px_-12px_rgba(15,23,42,.18)] p-4">
                            <h3 class="text-sm font-semibold text-gray-900 mb-3">Go-live date</h3>
                            <input type="datetime-local" name="online_launch_date" x-model="onlineDate" required class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent">
                            <p x-show="onlineDate && onlineDate < todayIso" x-cloak class="text-sm text-amber-700 mt-2">
                                This date is in the past.
                            </p>
                        </section>

                        <section class="bg-white/90 rounded-xl border border-white shadow-[0_8px_30px_-12px_rgba(15,23,42,.18)] p-4">
                            <h3 class="text-sm font-semibold text-gray-900 mb-3">Priority</h3>
                            <div class="flex rounded-lg bg-gray-100 p-1 gap-1">
                                @foreach(\App\Models\ProductRequest::PRIORITIES as $value => $label)
                                    <label class="relative flex-1 text-center cursor-pointer rounded-md px-3 py-1.5 text-sm text-gray-600 transition-colors hover:text-gray-900 has-[:checked]:bg-white has-[:checked]:text-gray-900 has-[:checked]:font-medium has-[:checked]:shadow-sm">
                                        <input type="radio" name="priority" value="{{ $value }}" class="sr-only" required
                                               {{ old('priority', 'medium') === $value ? 'checked' : '' }}>
                                        {{ $label }}
                                    </label>
                                @endforeach
                            </div>
                        </section>

                        {{-- Team --}}
                        <section class="bg-white/90 rounded-xl border border-white shadow-[0_8px_30px_-12px_rgba(15,23,42,.18)] p-4" x-data="{
                                allRoles: {{ Illuminate\Support\Js::from(collect(\App\Models\ProductRequest::assignableRoles())->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values()) }},
                                // Only the roles this request will actually use: no shoot
                                // means no coordinator, and no Cegid means no mapping.
                                get activeRoles() {
                                    return this.allRoles.filter(r =>
                                        (r.key !== 'photographer_id' || needsPhotoshoot) &&
                                        (r.key !== 'supply_chain_id' || usesMapping)
                                    );
                                },
                                // The category owner runs the request; the shoot and the
                                // brand-side task are the two that can be someone else's.
                                personFor(key) {
                                    if (key === 'photographer_id')  return photoshootCoordinator;
                                    if (key === 'brand_manager_id') return categoryBrandManager;
                                    return categoryOwner;
                                },
                             }">

                            <h3 class="text-sm font-semibold text-gray-900 mb-3">Team</h3>

                            <template x-if="!category">
                                <p class="text-sm text-gray-400">Pick a category to see the team.</p>
                            </template>

                            <template x-if="category">
                                <div class="divide-y divide-gray-100 -my-2">
                                    <template x-for="r in activeRoles" :key="r.key">
                                        <div class="flex items-center justify-between gap-4 py-2">
                                            <p class="text-sm text-gray-500" x-text="r.label"></p>
                                            <p class="text-sm text-right"
                                               :class="personFor(r.key) ? 'text-gray-900 font-medium' : 'text-amber-700'"
                                               x-text="personFor(r.key) || 'Not set yet'"></p>
                                        </div>
                                    </template>
                                </div>
                            </template>
                        </section>

                    </div>

                </div>
            </div>

        </form>
    </div>
</div>
