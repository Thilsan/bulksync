{{--
    Slide-over "New Product Creation Request" panel.
    Expects the parent Alpine scope to expose `newRequestOpen`.
--}}
<div x-show="newRequestOpen" x-cloak class="fixed inset-0 z-50 flex justify-end">

    <div class="absolute inset-0 bg-gray-900/40" @click="newRequestOpen = false"></div>

    <div class="relative w-full max-w-2xl bg-white h-full shadow-2xl flex flex-col"
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="translate-x-full"
         x-transition:enter-end="translate-x-0">

        <div class="px-6 py-4 border-b border-gray-200 flex items-start justify-between shrink-0">
            <div>
                <h2 class="text-base font-semibold text-gray-900">New Product Creation Request</h2>
                <p class="text-sm text-gray-500 mt-0.5">Submit a request to list new brand / category products on the website.</p>
            </div>
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
                  photoshootCoordinator: {{ Illuminate\Support\Js::from($photoshootCoordinator ?? null) }},
                  get categoryOwner() { return this.categoryOwners[this.category] || ''; },
                  storeId: '{{ old('store_id', $stores->firstWhere('is_active', true)?->id ?? $stores->first()?->id) }}',
                  mappingSites: {{ Illuminate\Support\Js::from($stores->where('requires_sku_mapping', true)->pluck('id')->map(fn ($id) => (string) $id)->values()) }},
                  get usesMapping() { return this.mappingSites.includes(String(this.storeId)) },
              }">
            @csrf

            <div class="flex-1 overflow-y-auto px-6 py-5 space-y-7">

                {{-- 1. Brand & Category --}}
                <section>
                    <h3 class="text-sm font-semibold text-gray-800 mb-3">1. Request, Website &amp; Category Information</h3>

                    <div class="mb-4">
                        <label class="block text-xs font-medium text-gray-600 mb-1.5">Request Name</label>
                        <input type="text" name="name" value="{{ old('name') }}" maxlength="255"
                               placeholder="e.g. New Balance Running SS26 launch"
                               class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent">
                        <p class="text-xs text-gray-400 mt-1">
                            How this request appears in lists. Leave blank and it is named from the brand and category.
                        </p>
                    </div>

                    <div class="mb-4">
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
                            <p x-show="usesMapping" x-cloak class="text-xs text-amber-600 mt-1">
                                SKUs for this website are mapped in Cegid — unmapped SKUs will go to
                                <span class="font-medium">Waiting for Mapping</span> with Supply Chain first.
                            </p>
                            <p x-show="!usesMapping" x-cloak class="text-xs text-gray-400 mt-1">
                                This website has no Cegid mapping step — the request goes straight to
                                <span class="font-medium">SKU Verified</span> after submission.
                            </p>
                        @endif
                    </div>

                    <div class="mb-4">
                        <label class="block text-xs font-medium text-gray-600 mb-1.5">Request Type <span class="text-red-500">*</span></label>
                        <div class="flex gap-5">
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

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1.5">Brand Name <span class="text-red-500">*</span></label>
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

                {{-- 2. SKUs --}}
                <section>
                    <h3 class="text-sm font-semibold text-gray-800 mb-3">2. SKU Information</h3>

                    <label class="block text-xs font-medium text-gray-600 mb-1.5">Add SKUs <span class="text-red-500">*</span></label>
                    <div class="inline-flex rounded-lg border border-gray-200 p-0.5 bg-gray-50 mb-3">
                        <button type="button" @click="skuInput = 'type'"
                                :class="skuInput === 'type' ? 'bg-brand-600 text-white' : 'text-gray-600 hover:text-gray-900'"
                                class="px-3 py-1.5 text-xs font-medium rounded-md transition-colors">Type SKUs</button>
                        <button type="button" @click="skuInput = 'csv'"
                                :class="skuInput === 'csv' ? 'bg-brand-600 text-white' : 'text-gray-600 hover:text-gray-900'"
                                class="px-3 py-1.5 text-xs font-medium rounded-md transition-colors">Upload CSV</button>
                    </div>

                    <div x-show="skuInput === 'type'">
                        <textarea name="skus" rows="6" placeholder="NB1001&#10;NB1002&#10;NB1003"
                                  class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent resize-y">{{ old('skus') }}</textarea>
                        <p class="text-xs text-gray-400 mt-1">One per line, or comma separated.</p>
                    </div>

                    <div x-show="skuInput === 'csv'" x-cloak>
                        <input type="file" name="sku_csv" accept=".csv,.txt"
                               class="w-full text-sm text-gray-600 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-medium file:bg-brand-50 file:text-brand-700 hover:file:bg-brand-100 cursor-pointer">
                        <p class="text-xs text-gray-400 mt-1">First column should contain SKUs. Header row optional.</p>
                    </div>

                    <p x-show="usesMapping" x-cloak class="text-xs text-gray-400 mt-2">
                        You can submit even if the SKUs or brand are not mapped yet — the request will move to
                        <span class="font-medium text-amber-600">Waiting for Mapping</span> and continue automatically once
                        Supply Chain records the mapping. No re-submission needed.
                    </p>
                </section>

                {{-- 3. Launch --}}
                <section>
                    <h3 class="text-sm font-semibold text-gray-800 mb-3">3. Launch</h3>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1.5">
                                Launch Date &amp; Time <span class="text-red-500">*</span>
                            </label>
                            <input type="datetime-local" name="online_launch_date" x-model="onlineDate" required
                                   class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent">
                            <p class="text-xs text-gray-400 mt-1">
                                When these products should be live on the website. Every team's deadline works back from here.
                            </p>
                            <p x-show="onlineDate && onlineDate < todayIso" x-cloak class="text-xs text-amber-600 mt-1">
                                That is in the past — please confirm it is intentional.
                            </p>
                        </div>
                    </div>
                </section>

                {{-- 4. Images --}}
                <section>
                    <h3 class="text-sm font-semibold text-gray-800 mb-3">4. Product Images</h3>

                    <label class="block text-xs font-medium text-gray-600 mb-2">
                        Where are the images coming from? <span class="text-red-500">*</span>
                    </label>

                    {{-- One answer, three real options. This used to be two yes/no
                         questions that could contradict each other. --}}
                    <div class="space-y-2">
                        @foreach(\App\Models\ProductRequest::selectableImageSources() as $value => $meta)
                            <label class="flex items-start gap-2.5 cursor-pointer rounded-lg border px-3 py-2.5 transition-colors"
                                   :class="imageSource === '{{ $value }}' ? 'border-brand-300 bg-brand-50/50' : 'border-gray-200 hover:bg-gray-50'">
                                <input type="radio" name="image_source" value="{{ $value }}" x-model="imageSource" required
                                       class="mt-0.5 text-brand-600 focus:ring-brand-500">
                                <span>
                                    <span class="text-sm text-gray-800">{{ $meta['label'] }}</span>
                                    <span class="block text-xs text-gray-500 mt-0.5">{{ $meta['hint'] }}</span>
                                </span>
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
                                   placeholder="https://… link to the folder the supplier sent"
                                   class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent">
                            <p class="text-xs text-gray-400 mt-1">
                                OneDrive, Drive, Dropbox — anywhere the team can open it. Shown on the request so nobody has to ask.
                            </p>
                        </div>

                        <p x-show="imagesAt === '{{ \App\Models\ProductRequest::IMAGES_AT_PIM }}'" x-cloak
                           class="text-xs text-gray-500 mt-2">
                            The team will take them from the PIM — no link needed.
                        </p>
                    </div>
                </section>

                {{-- 5. Content --}}
                <section>
                    <h3 class="text-sm font-semibold text-gray-800 mb-3">5. Product Content</h3>

                    <label class="block text-xs font-medium text-gray-600 mb-2">
                        Use AI Content Generator? <span class="text-red-500">*</span>
                    </label>
                    <div class="space-y-1.5">
                        <label class="flex items-start gap-2 cursor-pointer">
                            <input type="radio" name="use_ai_content" value="1" x-model="useAi" required
                                   class="mt-0.5 text-brand-600 focus:ring-brand-500">
                            <span>
                                <span class="text-sm text-gray-700">Yes — generate content with AI</span>
                                <span class="block text-xs text-gray-400">Descriptions, meta titles and meta descriptions are generated for you.</span>
                            </span>
                        </label>
                        <label class="flex items-start gap-2 cursor-pointer">
                            <input type="radio" name="use_ai_content" value="0" x-model="useAi"
                                   class="mt-0.5 text-brand-600 focus:ring-brand-500">
                            <span>
                                <span class="text-sm text-gray-700">No — brand team will provide the content</span>
                                <span class="block text-xs text-gray-400">You supply the copy as an Excel or CSV sheet.</span>
                            </span>
                        </label>
                    </div>

                    <div x-show="useAi === '0'" x-cloak class="mt-3 rounded-lg border border-amber-200 bg-amber-50/60 px-3 py-3">
                        <label class="block text-xs font-medium text-amber-900 mb-1.5">
                            Content Sheet <span class="text-gray-500 font-normal">(Excel or CSV)</span>
                        </label>
                        <input type="file" name="content_sheet" accept=".csv,.xlsx,.xls"
                               class="w-full text-sm text-gray-600 file:mr-3 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-medium file:bg-white file:text-amber-800 hover:file:bg-amber-100 cursor-pointer">
                        <p class="text-xs text-amber-700 mt-1.5">
                            Up to {{ \App\Models\ProductRequestAttachment::maxUploadLabel() }}. You can attach this later if it isn't ready — the request
                            will show as <span class="font-medium">awaiting content sheet</span> until you do.
                        </p>
                    </div>
                </section>

                {{-- 6. Team --}}
                <section x-data="{
                        allRoles: {{ Illuminate\Support\Js::from(collect(\App\Models\ProductRequest::assignableRoles())->map(fn ($label, $key) => ['key' => $key, 'label' => $label, 'task' => \App\Models\ProductRequest::taskForRole($key)])->values()) }},
                        // Only the roles this request will actually use — no shoot
                        // means no coordinator and nothing to edit either.
                        get activeRoles() {
                            return this.allRoles.filter(r =>
                                (r.key !== 'photographer_id' || needsPhotoshoot) &&
                                (r.key !== 'image_editor_id' || needsEditing) &&
                                (r.key !== 'supply_chain_id' || usesMapping)
                            );
                        },
                        // The category owner runs the request; only the shoot is
                        // somebody else's job.
                        personFor(key) {
                            return key === 'photographer_id' ? photoshootCoordinator : categoryOwner;
                        },
                     }">

                    <h3 class="text-sm font-semibold text-gray-800 mb-1">6. Team Assignments</h3>
                    <p class="text-xs text-gray-400 mb-3">
                        Set by the category — there is nothing to choose here. Everyone below is notified when you submit,
                        and any role can be handed to someone else on the request page afterwards.
                    </p>

                    <template x-if="!category">
                        <p class="text-xs text-gray-500 rounded-lg border border-dashed border-gray-300 px-3 py-3">
                            Choose a category above to see who will handle this request.
                        </p>
                    </template>

                    <template x-if="category">
                        <div>
                            <div class="rounded-lg border border-gray-200 divide-y divide-gray-100 overflow-hidden">
                                <template x-for="r in activeRoles" :key="r.key">
                                    <div class="flex items-start justify-between gap-4 px-3 py-2.5 bg-gray-50/60">
                                        <div class="min-w-0">
                                            <p class="text-sm font-medium text-gray-800" x-text="r.label"></p>
                                            <p class="text-xs text-gray-500 mt-0.5" x-text="r.task"></p>
                                        </div>
                                        <p class="text-sm shrink-0 text-right"
                                           :class="personFor(r.key) ? 'text-gray-800 font-medium' : 'text-amber-700'"
                                           x-text="personFor(r.key) || 'Unassigned'"></p>
                                    </div>
                                </template>
                            </div>

                            <p x-show="!categoryOwner" x-cloak class="text-xs text-amber-700 mt-2">
                                Nobody is set to handle <span class="font-medium" x-text="category"></span> yet, so this
                                request will arrive unassigned. Ask an admin to set the category owner, or assign it
                                yourself on the request page once it is raised.
                            </p>

                            <p x-show="needsPhotoshoot && !photoshootCoordinator" x-cloak class="text-xs text-amber-700 mt-2">
                                No single photoshoot coordinator is set, so the shoot is left unassigned.
                            </p>
                        </div>
                    </template>
                </section>

                {{-- 7. Additional --}}
                <section>
                    <h3 class="text-sm font-semibold text-gray-800 mb-3">7. Additional Information</h3>

                    <label class="block text-xs font-medium text-gray-600 mb-2">Priority <span class="text-red-500">*</span></label>
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

                    <label class="block text-xs font-medium text-gray-600 mb-1.5">Notes / Special Instructions</label>
                    <textarea name="notes" rows="3" placeholder="Add any special instructions, campaign details, product details, marketing notes, etc."
                              class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent resize-y">{{ old('notes') }}</textarea>
                </section>

                {{-- Sets expectations before they hit submit. --}}
                <div class="rounded-lg bg-gray-50 border border-gray-200 px-4 py-3">
                    <p class="text-xs font-semibold text-gray-700 mb-1.5">What happens after you submit</p>
                    <ol class="text-xs text-gray-500 space-y-1 list-decimal list-inside">
                        <li>Your SKUs are checked automatically and you get a request ID (e.g. PCR-2026-00045).</li>
                        <li>If any SKU is not mapped yet, the request waits with Supply Chain and continues on its own once they finish — you do not resubmit.</li>
                        <li>The E-Commerce team picks it up, and photoshoot, content and QA follow.</li>
                        <li>You are notified at every stage change, and can follow progress on the request page at any time.</li>
                    </ol>
                </div>

            </div>

            <div class="px-6 py-4 bg-gray-50 border-t border-gray-200 flex gap-3 shrink-0">
                <button type="button" @click="newRequestOpen = false"
                        class="flex-1 border border-gray-300 text-gray-700 text-sm font-medium px-4 py-2.5 rounded-lg hover:bg-gray-100 transition-colors">
                    Cancel
                </button>
                <button type="submit"
                        class="flex-1 text-white text-sm font-medium px-4 py-2.5 rounded-lg transition-colors"
                        style="background-color:#1d5a74" onmouseover="this.style.backgroundColor='#164659'" onmouseout="this.style.backgroundColor='#1d5a74'">
                    Submit Request
                </button>
            </div>
        </form>
    </div>
</div>
