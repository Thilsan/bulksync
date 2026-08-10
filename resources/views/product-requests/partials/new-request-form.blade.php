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
                  imageSource: '{{ old('image_source', '') }}',
                  get needsPhotoshoot() { return this.imageSource === '{{ \App\Models\ProductRequest::IMG_PHOTOSHOOT }}'; },
                  get needsEditing() { return this.imageSource !== '' && this.imageSource !== '{{ \App\Models\ProductRequest::IMG_SUPPLIER }}'; },
                  onlineDate: '{{ old('online_launch_date') }}',
                  todayIso: '{{ now()->format('Y-m-d\TH:i') }}',
                  // Per-person deadlines are dates, the launch is a moment — so
                  // cap them on the launch day, not the timestamp.
                  get launchDay() { return this.onlineDate ? this.onlineDate.slice(0, 10) : ''; },
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
                        @foreach(\App\Models\ProductRequest::IMAGE_SOURCES as $value => $meta)
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
                        rows: [{ role: '', user: '', due: '' }],
                        allRoles: {{ Illuminate\Support\Js::from(collect(\App\Models\ProductRequest::ASSIGNMENT_ROLES)->map(fn ($label, $key) => ['key' => $key, 'label' => $label, 'task' => \App\Models\ProductRequest::taskForRole($key)])->values()) }},
                        taskFor(role) {
                            const found = this.allRoles.find(r => r.key === role);
                            return found ? found.task : '';
                        },
                        get complete() { return this.rows.filter(r => r.role && r.user).length; },
                        // A finished row opens the next one, so the form leads you
                        // through the team instead of showing six empty slots.
                        openNext() {
                            const last = this.rows[this.rows.length - 1];
                            if (last && last.role && last.user && this.canAdd) {
                                this.rows.push({ role: '', user: '', due: '' });
                            }
                        },
                        // Only roles this request will actually use.
                        // Same rule as the request page: no shoot means no
                        // photographer and nothing to edit either.
                        // Same rule as the request page, from the one image answer.
                        get activeRoles() {
                            return this.allRoles.filter(r =>
                                (r.key !== 'photographer_id' || needsPhotoshoot) &&
                                (r.key !== 'image_editor_id' || needsEditing) &&
                                (r.key !== 'supply_chain_id' || usesMapping)
                            );
                        },
                        // A role already given out isn't offered again.
                        optionsFor(i) {
                            const taken = this.rows.filter((_, j) => j !== i).map(r => r.role).filter(Boolean);
                            return this.activeRoles.filter(r => !taken.includes(r.key));
                        },
                        get canAdd() { return this.rows.length < this.activeRoles.length; },
                        add() { if (this.canAdd) this.rows.push({ role: '', user: '', due: '' }); },
                        remove(i) {
                            this.rows.splice(i, 1);
                            if (!this.rows.length) this.add();
                        },
                        // Turning off the photoshoot must not leave a photographer
                        // assigned to work that no longer exists.
                        prune() {
                            const ok = this.activeRoles.map(r => r.key);
                            this.rows.forEach(r => { if (r.role && !ok.includes(r.role)) { r.role = ''; r.user = ''; r.due = ''; } });
                        },
                     }"
                     x-effect="imageSource; usesMapping; prune()"
                     x-init="$watch('rows', () => openNext(), { deep: true })">

                    <h3 class="text-sm font-semibold text-gray-800 mb-1">6. Team Assignments</h3>
                    <p class="text-xs text-gray-400 mb-3">
                        Optional. Choose a role and who does it — the task is set by the workflow.
                        Add a deadline if that person needs to finish before the launch date.
                        The next row opens as you complete each one.
                        Anyone you pick is notified that
                        <span class="font-medium text-gray-600">{{ auth()->user()->name }}</span> has given them this work.
                        Leave it empty and each team can claim their own stage later.
                    </p>

                    <div class="space-y-2">
                        <template x-for="(row, i) in rows" :key="i">
                            <div class="rounded-lg border border-gray-200 bg-gray-50/60 px-3 py-3">
                                <div class="flex items-center justify-between mb-2">
                                    <span class="text-xs font-semibold text-gray-500">
                                        Assignment <span x-text="i + 1"></span>
                                        <span x-show="row.role && row.user" x-cloak
                                              class="ml-1 text-green-600 font-normal">&check; set</span>
                                    </span>
                                    <button type="button" @click="remove(i)"
                                            class="text-gray-400 hover:text-red-600 transition-colors" title="Remove">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                                        </svg>
                                    </button>
                                </div>

                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                                    <div>
                                        <label class="block text-xs text-gray-500 mb-1">Role</label>
                                        <select x-model="row.role" :name="`assignments[${i}][role]`"
                                                class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-brand-500">
                                            <option value="">Select a role…</option>
                                            <template x-for="r in optionsFor(i)" :key="r.key">
                                                <option :value="r.key" x-text="r.label"></option>
                                            </template>
                                        </select>
                                    </div>

                                    <div>
                                        <label class="block text-xs text-gray-500 mb-1">Person</label>
                                        <select x-model="row.user" :name="`assignments[${i}][user_id]`" :disabled="!row.role"
                                                class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-brand-500 disabled:bg-gray-100 disabled:text-gray-400">
                                            <option value="">Select a person…</option>
                                            @foreach($teamPool as $member)
                                                <option value="{{ $member->id }}">{{ $member->name }}@if($member->pcr_role) — {{ $member->pcrRoleLabel() }}@endif</option>
                                            @endforeach
                                        </select>
                                    </div>

                                    <div>
                                        <label class="block text-xs text-gray-500 mb-1">
                                            Finish by
                                            <span class="text-gray-400" x-show="launchDay">(launch <span x-text="launchDay"></span>)</span>
                                        </label>
                                        <input type="date" x-model="row.due" :name="`assignments[${i}][due_date]`" :disabled="!row.role"
                                               :max="launchDay || null"
                                               class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-brand-500 disabled:bg-gray-100">
                                        <p x-show="row.due && launchDay && row.due > launchDay" x-cloak
                                           class="text-xs text-amber-600 mt-1">
                                            That is after the launch date.
                                        </p>
                                    </div>
                                </div>

                                {{-- The task is dictated by the workflow, so it is shown, not typed. --}}
                                <div x-show="row.role" x-cloak class="mt-2 rounded-lg bg-white border border-gray-200 px-3 py-2">
                                    <p class="text-[11px] uppercase tracking-wide font-semibold text-gray-400">Their task</p>
                                    <p class="text-xs text-gray-700 mt-0.5" x-text="taskFor(row.role)"></p>
                                </div>
                            </div>
                        </template>
                    </div>

                    <p x-show="complete" x-cloak class="text-xs text-gray-400 mt-2">
                        <span x-text="complete"></span> of <span x-text="activeRoles.length"></span> roles assigned.
                    </p>

                    <button type="button" @click="add()" x-show="canAdd"
                            class="mt-2.5 inline-flex items-center gap-1.5 text-xs font-medium text-brand-600 hover:text-brand-700">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                        </svg>
                        Add another role
                    </button>
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
