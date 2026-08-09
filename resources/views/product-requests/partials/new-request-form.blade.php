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
              x-data="{
                  skuInput: 'type',
                  useAi: '{{ old('use_ai_content', '1') }}',
                  storeDate: '{{ old('store_launch_date') }}',
                  onlineDate: '{{ old('online_launch_date') }}',
                  storeId: '{{ old('store_id', $stores->firstWhere('is_active', true)?->id ?? $stores->first()?->id) }}',
                  mappingSites: {{ Illuminate\Support\Js::from($stores->where('requires_sku_mapping', true)->pluck('id')->map(fn ($id) => (string) $id)->values()) }},
                  get usesMapping() { return this.mappingSites.includes(String(this.storeId)) },
                  get dateWarning() { return this.storeDate && this.onlineDate && this.onlineDate < this.storeDate }
              }">
            @csrf

            <div class="flex-1 overflow-y-auto px-6 py-5 space-y-7">

                {{-- 1. Brand & Category --}}
                <section>
                    <h3 class="text-sm font-semibold text-gray-800 mb-3">1. Website, Brand &amp; Category Information</h3>

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
                            @foreach(['new_brand' => 'New Brand', 'existing_brand' => 'Existing Brand / New Category'] as $value => $label)
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
                            <input type="text" name="category" value="{{ old('category') }}" required placeholder="e.g. Footwear"
                                   class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1.5">Sub Category</label>
                            <input type="text" name="sub_category" value="{{ old('sub_category') }}" placeholder="e.g. Running Shoes"
                                   class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1.5">Department</label>
                            <input type="text" name="department" value="{{ old('department') }}" placeholder="e.g. Men"
                                   class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent">
                        </div>
                        <div class="col-span-2">
                            <label class="block text-xs font-medium text-gray-600 mb-1.5">Collection / Season</label>
                            <input type="text" name="collection" value="{{ old('collection') }}" placeholder="e.g. Fall / Winter 2026"
                                   class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent">
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

                {{-- 3. Availability --}}
                <section>
                    <h3 class="text-sm font-semibold text-gray-800 mb-3">3. Store &amp; Online Availability</h3>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1.5">Expected Store Launch Date <span class="text-red-500">*</span></label>
                            <input type="date" name="store_launch_date" x-model="storeDate" required
                                   class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1.5">Expected Online Launch Date <span class="text-red-500">*</span></label>
                            <input type="date" name="online_launch_date" x-model="onlineDate" required
                                   class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent">
                            <p x-show="!dateWarning" class="text-xs text-gray-400 mt-1">Online date should not be before store date.</p>
                            <p x-show="dateWarning" x-cloak class="text-xs text-amber-600 mt-1">
                                Online launch is before the store launch date — allowed, but please confirm this is intentional.
                            </p>
                        </div>
                    </div>
                </section>

                {{-- 4. Images --}}
                <section>
                    <h3 class="text-sm font-semibold text-gray-800 mb-3">4. Images &amp; Photoshoot</h3>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-2">Supplier Images Available? <span class="text-red-500">*</span></label>
                            <div class="space-y-1.5">
                                <label class="flex items-center gap-2 cursor-pointer">
                                    <input type="radio" name="supplier_images_available" value="1" {{ old('supplier_images_available') === '1' ? 'checked' : '' }} required class="text-brand-600 focus:ring-brand-500">
                                    <span class="text-sm text-gray-700">Yes, images provided by supplier</span>
                                </label>
                                <label class="flex items-center gap-2 cursor-pointer">
                                    <input type="radio" name="supplier_images_available" value="0" {{ old('supplier_images_available', '0') === '0' ? 'checked' : '' }} class="text-brand-600 focus:ring-brand-500">
                                    <span class="text-sm text-gray-700">No, require photoshoot</span>
                                </label>
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-2">Photoshoot Required? <span class="text-red-500">*</span></label>
                            <div class="flex gap-5">
                                <label class="flex items-center gap-2 cursor-pointer">
                                    <input type="radio" name="photoshoot_required" value="1" {{ old('photoshoot_required', '1') === '1' ? 'checked' : '' }} required class="text-brand-600 focus:ring-brand-500">
                                    <span class="text-sm text-gray-700">Yes</span>
                                </label>
                                <label class="flex items-center gap-2 cursor-pointer">
                                    <input type="radio" name="photoshoot_required" value="0" {{ old('photoshoot_required') === '0' ? 'checked' : '' }} class="text-brand-600 focus:ring-brand-500">
                                    <span class="text-sm text-gray-700">No</span>
                                </label>
                            </div>

                            <label class="block text-xs font-medium text-gray-600 mb-1.5 mt-4">Reference Images / Notes</label>
                            <input type="file" name="reference_images[]" multiple accept=".jpg,.jpeg,.png,.pdf"
                                   class="w-full text-sm text-gray-600 file:mr-3 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-medium file:bg-brand-50 file:text-brand-700 hover:file:bg-brand-100 cursor-pointer">
                            <p class="text-xs text-gray-400 mt-1">JPG, PNG, PDF up to 10MB each (max 10 files).</p>
                        </div>
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
                            You can attach this later if it isn't ready — the request will show as
                            <span class="font-medium">awaiting content sheet</span> until you do.
                        </p>
                    </div>
                </section>

                {{-- 6. Additional --}}
                <section>
                    <h3 class="text-sm font-semibold text-gray-800 mb-3">6. Additional Information</h3>

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
