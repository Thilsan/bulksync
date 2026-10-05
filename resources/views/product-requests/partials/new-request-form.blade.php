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

        <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between shrink-0">
            <h2 class="text-base font-semibold text-gray-900">New Product Creation Request</h2>
            <button type="button" @click="newRequestOpen = false" class="text-gray-400 hover:text-gray-600 shrink-0">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>

        <form method="POST" action="{{ route('product-requests.store') }}" enctype="multipart/form-data"
              class="flex-1 flex flex-col overflow-hidden"
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

            <div class="flex-1 overflow-y-auto px-6 py-5 space-y-6">

                {{-- Details --}}
                <section>
                    <h3 class="text-sm font-semibold text-gray-800 mb-3">Details</h3>

                    <div class="mb-4">
                        <label class="block text-xs font-medium text-gray-600 mb-1.5">Request name <span class="text-gray-400 font-normal">(optional)</span></label>
                        <input type="text" name="name" value="{{ old('name') }}" maxlength="255"
                               placeholder="e.g. New Balance Running SS26 launch"
                               class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent">
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-4">
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1.5">Website <span class="text-red-500">*</span></label>
                            @if($stores->isEmpty())
                                <p class="text-sm text-red-600 bg-red-50 border border-red-200 rounded-lg px-3 py-2">
                                    You don't have access to any website yet. Ask an admin to grant store access before raising a request.
                                </p>
                            @else
                                <select name="store_id" x-model="storeId" required
                                        class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent">
                                    @foreach($stores as $site)
                                        <option value="{{ $site->id }}">{{ $site->name }}</option>
                                    @endforeach
                                </select>
                            @endif
                        </div>

                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1.5">Brand <span class="text-red-500">*</span></label>
                            <div class="flex gap-5 h-[38px] items-center">
                                @foreach(['new_brand' => 'New Brand', 'existing_brand' => 'Existing Brand'] as $value => $label)
                                    <label class="flex items-center gap-2 cursor-pointer">
                                        <input type="radio" name="request_type" value="{{ $value }}"
                                               {{ old('request_type', 'new_brand') === $value ? 'checked' : '' }}
                                               class="text-brand-600 focus:ring-brand-500">
                                        <span class="text-sm text-gray-700">{{ $label }}</span>
                                    </label>
                                @endforeach
                            </div>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1.5">Brand name <span class="text-red-500">*</span></label>
                            <input type="text" name="brand" value="{{ old('brand') }}" required placeholder="e.g. New Balance"
                                   class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1.5">Category <span class="text-red-500">*</span></label>
                            <select name="category" x-model="category" required
                                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent">
                                <option value="">Select a category</option>
                                @foreach(\App\Models\ProductRequest::CATEGORIES as $category)
                                    <option value="{{ $category }}" {{ old('category') === $category ? 'selected' : '' }}>{{ $category }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                </section>

                {{-- SKUs --}}
                <section>
                    <div class="flex items-center justify-between mb-3">
                        <h3 class="text-sm font-semibold text-gray-800">SKUs <span class="text-red-500">*</span></h3>
                        <div class="inline-flex rounded-lg border border-gray-200 p-0.5 bg-gray-50">
                            <button type="button" @click="skuInput = 'type'"
                                    :class="skuInput === 'type' ? 'bg-brand-600 text-white' : 'text-gray-600 hover:text-gray-900'"
                                    class="px-3 py-1.5 text-xs font-medium rounded-md transition-colors">Type</button>
                            <button type="button" @click="skuInput = 'csv'"
                                    :class="skuInput === 'csv' ? 'bg-brand-600 text-white' : 'text-gray-600 hover:text-gray-900'"
                                    class="px-3 py-1.5 text-xs font-medium rounded-md transition-colors">Upload file</button>
                        </div>
                    </div>

                    <div x-show="skuInput === 'type'">
                        <textarea name="skus" rows="6" placeholder="NB1001&#10;NB1002&#10;NB1003"
                                  class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent resize-y">{{ old('skus') }}</textarea>
                    </div>

                    <div x-show="skuInput === 'csv'" x-cloak>
                        <input type="file" name="sku_csv" accept=".csv,.txt"
                               class="w-full text-sm text-gray-600 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-medium file:bg-brand-50 file:text-brand-700 hover:file:bg-brand-100 cursor-pointer">
                    </div>
                </section>

                {{-- Launch --}}
                <section>
                    <h3 class="text-sm font-semibold text-gray-800 mb-3">Launch</h3>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1.5">
                                Go-live date &amp; time <span class="text-red-500">*</span>
                            </label>
                            <input type="datetime-local" name="online_launch_date" x-model="onlineDate" required
                                   class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent">
                            <p x-show="onlineDate && onlineDate < todayIso" x-cloak class="text-xs text-amber-600 mt-1">
                                This date is in the past.
                            </p>
                        </div>
                    </div>
                </section>

                {{-- Images --}}
                <section>
                    <h3 class="text-sm font-semibold text-gray-800 mb-3">Images <span class="text-red-500">*</span></h3>

                    {{-- One answer, three real options. This used to be two yes/no
                         questions that could contradict each other. --}}
                    <div class="space-y-2">
                        @foreach(\App\Models\ProductRequest::selectableImageSources() as $value => $meta)
                            <label class="flex items-center gap-2.5 cursor-pointer rounded-lg border px-3 py-2.5 transition-colors"
                                   :class="imageSource === '{{ $value }}' ? 'border-brand-300 bg-brand-50/50' : 'border-gray-200 hover:bg-gray-50'">
                                <input type="radio" name="image_source" value="{{ $value }}" x-model="imageSource" required
                                       class="text-brand-600 focus:ring-brand-500">
                                <span class="text-sm text-gray-800">{{ $meta['label'] }}</span>
                            </label>
                        @endforeach
                    </div>

                    {{-- Only supplier images have a location to record — a photoshoot
                         has nothing to point at until it has happened. --}}
                    <div x-show="imageSource === '{{ \App\Models\ProductRequest::IMG_SUPPLIER }}'" x-cloak
                         class="mt-3 rounded-lg border border-gray-200 bg-gray-50/60 px-3 py-3">
                        <label class="block text-xs font-medium text-gray-600 mb-2">
                            Where are the images? <span class="text-red-500">*</span>
                        </label>

                        <div class="flex flex-wrap gap-4">
                            @foreach(\App\Models\ProductRequest::IMAGE_LOCATIONS as $value => $label)
                                <label class="flex items-center gap-2 cursor-pointer">
                                    <input type="radio" name="images_location" value="{{ $value }}" x-model="imagesAt"
                                           class="text-brand-600 focus:ring-brand-500">
                                    <span class="text-sm text-gray-700">{{ $label }}</span>
                                </label>
                            @endforeach
                        </div>

                        <div x-show="imagesAt === '{{ \App\Models\ProductRequest::IMAGES_AT_URL }}'" x-cloak class="mt-2.5">
                            <input type="url" name="images_url" x-model="imagesUrl" maxlength="2048"
                                   placeholder="Paste the folder link"
                                   class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent">
                        </div>
                    </div>
                </section>

                {{-- Content --}}
                <section>
                    <h3 class="text-sm font-semibold text-gray-800 mb-3">Product content <span class="text-red-500">*</span></h3>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                        <label class="flex items-center gap-2.5 cursor-pointer rounded-lg border px-3 py-2.5 transition-colors"
                               :class="useAi === '1' ? 'border-brand-300 bg-brand-50/50' : 'border-gray-200 hover:bg-gray-50'">
                            <input type="radio" name="use_ai_content" value="1" x-model="useAi" required
                                   class="text-brand-600 focus:ring-brand-500">
                            <span class="text-sm text-gray-800">Write it with AI</span>
                        </label>
                        <label class="flex items-center gap-2.5 cursor-pointer rounded-lg border px-3 py-2.5 transition-colors"
                               :class="useAi === '0' ? 'border-brand-300 bg-brand-50/50' : 'border-gray-200 hover:bg-gray-50'">
                            <input type="radio" name="use_ai_content" value="0" x-model="useAi"
                                   class="text-brand-600 focus:ring-brand-500">
                            <span class="text-sm text-gray-800">Brand team will provide it</span>
                        </label>
                    </div>

                    <div x-show="useAi === '0'" x-cloak class="mt-3 rounded-lg border border-gray-200 bg-gray-50/60 px-3 py-3">
                        <label class="block text-xs font-medium text-gray-600 mb-1.5">
                            Content sheet <span class="text-gray-400 font-normal">(optional · Excel or CSV, up to {{ \App\Models\ProductRequestAttachment::maxUploadLabel() }})</span>
                        </label>
                        <input type="file" name="content_sheet" accept=".csv,.xlsx,.xls"
                               class="w-full text-sm text-gray-600 file:mr-3 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-medium file:bg-brand-50 file:text-brand-700 hover:file:bg-brand-100 cursor-pointer">
                    </div>
                </section>

                {{-- 6. Team --}}
                <section x-data="{
                        allRoles: {{ Illuminate\Support\Js::from(collect(\App\Models\ProductRequest::assignableRoles())->map(fn ($label, $key) => ['key' => $key, 'label' => $label, 'task' => \App\Models\ProductRequest::taskForRole($key)])->values()) }},
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

                    <h3 class="text-sm font-semibold text-gray-800 mb-3">Team</h3>

                    <template x-if="!category">
                        <p class="text-xs text-gray-500 rounded-lg border border-dashed border-gray-300 px-3 py-3">
                            Pick a category to see the team.
                        </p>
                    </template>

                    <template x-if="category">
                        <div>
                            <div class="rounded-lg border border-gray-200 divide-y divide-gray-100 overflow-hidden">
                                <template x-for="r in activeRoles" :key="r.key">
                                    <div class="flex items-center justify-between gap-4 px-3 py-2.5">
                                        <p class="text-sm text-gray-600" x-text="r.label"></p>
                                        <p class="text-sm shrink-0 text-right"
                                           :class="personFor(r.key) ? 'text-gray-800 font-medium' : 'text-amber-700'"
                                           x-text="personFor(r.key) || 'Unassigned'"></p>
                                    </div>
                                </template>
                            </div>

                            <p x-show="!categoryOwner" x-cloak class="text-xs text-amber-700 mt-2">
                                No owner set for <span class="font-medium" x-text="category"></span> yet.
                            </p>

                            <p x-show="needsPhotoshoot && !photoshootCoordinator" x-cloak class="text-xs text-amber-700 mt-2">
                                No photoshoot coordinator set yet.
                            </p>
                        </div>
                    </template>
                </section>

                {{-- Priority & notes --}}
                <section>
                    <h3 class="text-sm font-semibold text-gray-800 mb-3">Priority &amp; notes</h3>

                                        <div class="flex gap-5 mb-4">
                        @foreach(\App\Models\ProductRequest::PRIORITIES as $value => $label)
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="radio" name="priority" value="{{ $value }}"
                                       {{ old('priority', 'medium') === $value ? 'checked' : '' }} required
                                       class="text-brand-600 focus:ring-brand-500">
                                <span class="text-sm text-gray-700">{{ $label }}</span>
                            </label>
                        @endforeach
                    </div>

                    <textarea name="notes" rows="3" placeholder="Notes (optional)"
                              class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent resize-y">{{ old('notes') }}</textarea>
                </section>

            </div>

            <div class="px-6 py-4 border-t border-gray-100 flex justify-end gap-3 shrink-0">
                <button type="button" @click="newRequestOpen = false"
                        class="border border-gray-300 text-gray-700 text-sm font-medium px-5 py-2.5 rounded-lg hover:bg-gray-50 transition-colors">
                    Cancel
                </button>
                <button type="submit"
                        class="text-white text-sm font-medium px-5 py-2.5 rounded-lg bg-brand-600 hover:bg-brand-700 transition-colors">
                    Submit Request
                </button>
            </div>
        </form>
    </div>
</div>
