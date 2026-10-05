{{--
    "New Product Creation Request" modal.
    Expects the parent Alpine scope to expose `newRequestOpen`.
--}}
<div x-show="newRequestOpen" x-cloak @keydown.escape.window="newRequestOpen = false"
     class="fixed inset-0 z-50 flex items-center justify-center p-4">

    <div class="absolute inset-0 bg-gray-900/50" @click="newRequestOpen = false"
         x-show="newRequestOpen"
         x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
         x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"></div>

    <div class="relative w-full max-w-2xl max-h-[90vh] bg-white rounded-2xl shadow-2xl flex flex-col overflow-hidden"
         x-show="newRequestOpen"
         x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100"
         x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100 scale-100" x-transition:leave-end="opacity-0 scale-95">

        <div class="px-5 py-4 bg-white border-b border-gray-200 flex items-center justify-between shrink-0">
            <h2 class="text-base font-semibold text-gray-900">New Product Creation Request</h2>
            <button type="button" @click="newRequestOpen = false" class="text-gray-400 hover:text-gray-600 shrink-0">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>

        <form method="POST" action="{{ route('product-requests.store') }}" enctype="multipart/form-data"
              class="flex flex-col min-h-0 overflow-hidden"
              x-init="$watch('imageSource', () => clearLocationIfNotSupplier())"
              x-data="{
                  skuInput: 'type',
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

            <div class="min-h-0 overflow-y-auto bg-gray-100 p-4 sm:p-5 space-y-4">

                {{-- Request --}}
                <section class="bg-white rounded-xl border border-gray-200 shadow-sm p-4 space-y-4">
                    <h3 class="text-sm font-semibold text-gray-900">Request</h3>

                    <div>
                        <label class="block text-sm text-gray-700 mb-1">Request name <span class="text-gray-400">(optional)</span></label>
                        <input type="text" name="name" value="{{ old('name') }}" maxlength="255"
                               placeholder="e.g. New Balance Running SS26 launch" class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent">
                    </div>

                    <div>
                        <label class="block text-sm text-gray-700 mb-1">Website</label>
                        @if($stores->isEmpty())
                            <p class="text-sm text-red-600 bg-red-50 border border-red-200 rounded-lg px-3 py-2">
                                You don't have access to any website yet. Ask an admin to grant store access before raising a request.
                            </p>
                        @else
                            <select name="store_id" x-model="storeId" required class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent">
                                @foreach($stores as $site)
                                    <option value="{{ $site->id }}">{{ $site->name }}</option>
                                @endforeach
                            </select>
                        @endif
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm text-gray-700 mb-1">Brand name</label>
                            <input type="text" name="brand" value="{{ old('brand') }}" required placeholder="e.g. New Balance" class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent">
                        </div>
                        <div>
                            <label class="block text-sm text-gray-700 mb-1">Category</label>
                            <select name="category" x-model="category" required class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent">
                                <option value="">Select a category</option>
                                @foreach(\App\Models\ProductRequest::CATEGORIES as $category)
                                    <option value="{{ $category }}" {{ old('category') === $category ? 'selected' : '' }}>{{ $category }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    <div>
                        <label class="block text-sm text-gray-700 mb-1">Is this brand new to the website?</label>
                        <div class="flex rounded-lg bg-gray-100 p-1 gap-1 sm:w-1/2">
                            @foreach(['new_brand' => 'Yes, new brand', 'existing_brand' => 'No, existing'] as $value => $label)
                                <label class="flex-1 text-center cursor-pointer rounded-md px-3 py-1.5 text-sm text-gray-600 transition-colors hover:text-gray-900 has-[:checked]:bg-white has-[:checked]:text-gray-900 has-[:checked]:font-medium has-[:checked]:shadow-sm">
                                    <input type="radio" name="request_type" value="{{ $value }}" class="sr-only"
                                           {{ old('request_type', 'new_brand') === $value ? 'checked' : '' }}>
                                    {{ $label }}
                                </label>
                            @endforeach
                        </div>
                    </div>
                </section>

                {{-- Products --}}
                <section class="bg-white rounded-xl border border-gray-200 shadow-sm p-4">
                    <div class="flex items-center justify-between gap-3 mb-3">
                        <h3 class="text-sm font-semibold text-gray-900">Products</h3>
                        <div class="flex rounded-lg bg-gray-100 p-1 gap-1">
                            <button type="button" @click="skuInput = 'type'"
                                    :class="skuInput === 'type' ? 'bg-white text-gray-900 font-medium shadow-sm' : 'text-gray-600 hover:text-gray-900'"
                                    class="rounded-md px-3 py-1 text-xs transition-colors">Type SKUs</button>
                            <button type="button" @click="skuInput = 'csv'"
                                    :class="skuInput === 'csv' ? 'bg-white text-gray-900 font-medium shadow-sm' : 'text-gray-600 hover:text-gray-900'"
                                    class="rounded-md px-3 py-1 text-xs transition-colors">Upload file</button>
                        </div>
                    </div>

                    <div x-show="skuInput === 'type'">
                        <textarea name="skus" rows="5" placeholder="NB1001&#10;NB1002&#10;NB1003"
                                  class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent font-mono resize-y">{{ old('skus') }}</textarea>
                    </div>

                    <div x-show="skuInput === 'csv'" x-cloak>
                        <label x-data="{ fileName: '' }" class="flex flex-col items-center justify-center gap-1 cursor-pointer rounded-lg border-2 border-dashed border-gray-300 px-4 py-6 text-center hover:bg-gray-50 transition-colors">
                            <svg class="w-6 h-6 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/></svg>
                            <span class="text-sm font-medium text-gray-700" x-text="fileName || 'Choose a CSV file'"></span>
                            <input type="file" name="sku_csv" accept=".csv,.txt" class="sr-only"
                                   @change="fileName = $el.files[0]?.name || ''">
                        </label>
                    </div>
                </section>

                {{-- Go-live --}}
                <section class="bg-white rounded-xl border border-gray-200 shadow-sm p-4">
                    <h3 class="text-sm font-semibold text-gray-900 mb-3">Go-live date</h3>
                    <input type="datetime-local" name="online_launch_date" x-model="onlineDate" required class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent sm:w-1/2">
                    <p x-show="onlineDate && onlineDate < todayIso" x-cloak class="text-sm text-amber-700 mt-2">
                        This date is in the past.
                    </p>
                </section>

                {{-- Images --}}
                <section class="bg-white rounded-xl border border-gray-200 shadow-sm p-4">
                    <h3 class="text-sm font-semibold text-gray-900 mb-3">Images</h3>

                    {{-- One answer, three real options. This used to be two yes/no
                         questions that could contradict each other. --}}
                    <div class="space-y-2">
                        @foreach(\App\Models\ProductRequest::selectableImageSources() as $value => $meta)
                            <label class="flex items-center gap-3 cursor-pointer rounded-lg border border-gray-200 px-3 py-2.5 transition-colors hover:bg-gray-50 has-[:checked]:border-brand-500 has-[:checked]:bg-brand-50/40">
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
                                <label class="flex-1 text-center cursor-pointer rounded-md px-3 py-1.5 text-sm text-gray-600 transition-colors hover:text-gray-900 has-[:checked]:bg-white has-[:checked]:text-gray-900 has-[:checked]:font-medium has-[:checked]:shadow-sm">
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
                <section class="bg-white rounded-xl border border-gray-200 shadow-sm p-4">
                    <h3 class="text-sm font-semibold text-gray-900 mb-3">Product descriptions</h3>

                    <div class="space-y-2">
                        <label class="flex items-center gap-3 cursor-pointer rounded-lg border border-gray-200 px-3 py-2.5 transition-colors hover:bg-gray-50 has-[:checked]:border-brand-500 has-[:checked]:bg-brand-50/40">
                            <input type="radio" name="use_ai_content" value="1" x-model="useAi" required class="text-brand-600 focus:ring-brand-500">
                            <span class="text-sm text-gray-800">Write them with AI</span>
                        </label>
                        <label class="flex items-center gap-3 cursor-pointer rounded-lg border border-gray-200 px-3 py-2.5 transition-colors hover:bg-gray-50 has-[:checked]:border-brand-500 has-[:checked]:bg-brand-50/40">
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

                {{-- Priority & notes --}}
                <section class="bg-white rounded-xl border border-gray-200 shadow-sm p-4 space-y-4">
                    <div>
                        <h3 class="text-sm font-semibold text-gray-900 mb-3">Priority</h3>
                        <div class="flex rounded-lg bg-gray-100 p-1 gap-1">
                            @foreach(\App\Models\ProductRequest::PRIORITIES as $value => $label)
                                <label class="flex-1 text-center cursor-pointer rounded-md px-3 py-1.5 text-sm text-gray-600 transition-colors hover:text-gray-900 has-[:checked]:bg-white has-[:checked]:text-gray-900 has-[:checked]:font-medium has-[:checked]:shadow-sm">
                                    <input type="radio" name="priority" value="{{ $value }}" class="sr-only" required
                                           {{ old('priority', 'medium') === $value ? 'checked' : '' }}>
                                    {{ $label }}
                                </label>
                            @endforeach
                        </div>
                    </div>

                    <div>
                        <label class="block text-sm text-gray-700 mb-1">Notes <span class="text-gray-400">(optional)</span></label>
                        <textarea name="notes" rows="3" placeholder="Anything the team should know"
                                  class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent resize-y">{{ old('notes') }}</textarea>
                    </div>
                </section>

                {{-- Team --}}
                <section class="bg-white rounded-xl border border-gray-200 shadow-sm p-4" x-data="{
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

            <div class="px-5 py-3.5 bg-white border-t border-gray-200 flex justify-end gap-3 shrink-0">
                <button type="button" @click="newRequestOpen = false"
                        class="border border-gray-300 bg-white text-gray-700 text-sm font-medium px-4 py-2 rounded-lg hover:bg-gray-50 transition-colors">
                    Cancel
                </button>
                <button type="submit"
                        class="text-white text-sm font-medium px-4 py-2 rounded-lg bg-brand-600 hover:bg-brand-700 shadow-sm transition-colors">
                    Submit request
                </button>
            </div>
        </form>
    </div>
</div>
