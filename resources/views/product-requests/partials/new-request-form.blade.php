{{--
    "New Product Creation Request" panel, filling the page area.
    Expects the parent Alpine scope to expose `newRequestOpen`.
--}}
<div x-show="newRequestOpen" x-cloak @keydown.escape.window="newRequestOpen = false"
     {{-- Covers the page area only: the sidebar and top bar stay in view. --}}
     x-data="{
         top: 0,
         nudge: 0,
         measure() {
             const bar = document.querySelector('header.topbar');
             const want = bar ? bar.getBoundingClientRect().bottom : 0;
             this.top = want + this.nudge;
             // Once it is on screen, check where it actually landed and remember
             // any difference, so the next open is right from the first frame.
             requestAnimationFrame(() => {
                 if (!this.$el.getClientRects().length) return; // still hidden
                 const off = want - this.$el.getBoundingClientRect().top;
                 if (Math.abs(off) > 0.5) { this.nudge += off; this.top += off; }
             });
         },
     }"
     x-init="measure()" x-effect="if (newRequestOpen) measure()" @resize.window="measure()"
     :style="{ top: top + 'px' }"
     {{-- No opacity here: a fading parent stops the browser drawing the blur
          until the fade ends. This only holds the panel open while its
          children animate out. --}}
     x-transition:leave="duration-200"
     {{-- !animate-none: the layout's page-load "rise" (main > * > *) would
          otherwise replay on every open — sliding the panel 10px down from
          the top bar and fading it, which also holds back the blur. --}}
     class="fixed inset-x-0 bottom-0 lg:left-64 z-30 flex !animate-none">

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
            <div class="max-w-4xl mx-auto flex items-center justify-between">
                <h2 class="text-base font-semibold text-gray-900">New Product Creation Request</h2>
                <button type="button" @click="newRequestOpen = false" aria-label="Close" title="Close"
                        class="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-red-50 text-red-600 ring-1 ring-red-100 transition-colors hover:bg-red-600 hover:text-white hover:ring-red-600 focus:outline-none focus:ring-2 focus:ring-red-400">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>
        </div>

        <form method="POST" action="{{ route('product-requests.store') }}" enctype="multipart/form-data"
              class="flex-1 flex flex-col min-h-0 overflow-clip"
              x-init="$watch('imageSource', () => clearLocationIfNotSupplier())"
              x-data="{
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
                  showroomDate: '{{ old('store_launch_date') }}',
                  todayIso: '{{ now()->format('Y-m-d\TH:i') }}',
                  {{-- Js::from, not a quoted string — "Men's Fashion" would break out of it. --}}
                  category: {{ Illuminate\Support\Js::from(old('category', '')) }},
                  brand: {{ Illuminate\Support\Js::from(old('brand', '')) }},
                  storeId: '{{ old('store_id', $stores->contains('id', $activeStoreId) ? $activeStoreId : $stores->first()?->id) }}',
                  mappingSites: {{ Illuminate\Support\Js::from($stores->where('requires_sku_mapping', true)->pluck('id')->map(fn ($id) => (string) $id)->values()) }},
                  get usesMapping() { return this.mappingSites.includes(String(this.storeId)) },

                  // On a Cegid-mapped website the product list is checked against
                  // Shopify before anything is created, so the person sees which
                  // SKUs are ready and which they still have to map.
                  check: { open: false, loading: false, error: null, result: null },
                  confirmed: false,
                  onlySkus: '',
                  submitting: false,
                  expanded: {}, cache: {},
                  async onSubmit(e) {
                      if (this.confirmed || !this.usesMapping) { this.submitting = true; return; }
                      if (!e.target.reportValidity()) return;
                      e.preventDefault();
                      this.check = { open: true, loading: true, error: null, result: null };
                      const data = new FormData(e.target);
                      try {
                          const res  = await fetch('{{ route('product-requests.precheck') }}', { method: 'POST', body: data, headers: { Accept: 'application/json' } });
                          const body = await res.json();
                          if (res.status === 422) {
                              this.check.error = Object.values(body.errors || {}).flat()[0] || body.message;
                          } else if (!res.ok) {
                              this.check.error = body.error || body.message || 'The check could not run.';
                          } else if (!body.mapping) {
                              this.check.open = false; this.go(null);
                              return;
                          } else {
                              this.check.result = body;
                              this.openAll(body.mapped.map(r => r.sku));
                          }
                      } catch (err) {
                          this.check.error = 'The check could not reach the server.';
                      }
                      this.check.loading = false;
                  },
                  // Submit for real, with only the SKUs that were confirmed.
                  go(skus) {
                      this.onlySkus = skus ? JSON.stringify(skus) : '';
                      this.confirmed = true;
                      this.check.open = false;
                      this.$nextTick(() => this.$refs.form.requestSubmit());
                  },
                  // Open by default: the first 40 load a few at a time, so a long
                  // list neither waits on forty Shopify calls nor floods it.
                  async openAll(skus) {
                      this.expanded = {};
                      const first = skus.slice(0, 40);
                      first.forEach(s => this.expanded[s] = true);
                      const queue = [...first];
                      const worker = async () => { while (queue.length) await this.fetchVariants(queue.shift()); };
                      await Promise.all([worker(), worker(), worker()]);
                  },
                  variants(sku) {
                      this.expanded[sku] = !this.expanded[sku];
                      if (this.expanded[sku]) this.fetchVariants(sku);
                  },
                  async fetchVariants(sku) {
                      if (this.cache[sku]) return;
                      this.cache[sku] = { data: null, error: null, loading: true };
                      try {
                          const res  = await fetch('{{ route('product-requests.precheck-variants') }}?store_id=' + this.storeId + '&sku=' + encodeURIComponent(sku), { headers: { Accept: 'application/json' } });
                          const body = await res.json();
                          this.cache[sku] = res.ok ? { data: body, error: null, loading: false }
                                                   : { data: null, error: body.error || body.message || 'Could not read the variants.', loading: false };
                      } catch (err) {
                          this.cache[sku] = { data: null, error: 'Could not reach Shopify for this SKU.', loading: false };
                      }
                  },
              }"
              x-ref="form"
              @submit="onSubmit($event)">
            @csrf
            <input type="hidden" name="only_skus" :value="onlySkus">

            <div class="flex-1 min-h-0 overflow-y-auto px-4 py-6 sm:px-6">
                <div class="max-w-4xl mx-auto">

                    <div class="space-y-4">
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

                        {{-- The basics, as small boxes side by side --}}
                        <section class="bg-white/90 rounded-xl border border-white shadow-[0_8px_30px_-12px_rgba(15,23,42,.18)] p-4">
                            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                                <div class="rounded-lg border border-gray-200 bg-white p-3">
                                    <label class="block text-xs font-medium text-gray-500 mb-1.5">Website</label>
                                    @if($stores->isEmpty())
                                        <p class="text-xs text-red-600">You don't have access to any website yet. Ask an admin for store access.</p>
                                    @else
                                        @include('product-requests.partials.pretty-select', [
                                            'name' => 'store_id', 'model' => 'storeId', 'placeholder' => 'Select a website',
                                            'options' => $stores->pluck('name', 'id'),
                                        ])
                                    @endif
                                </div>

                                <div class="rounded-lg border border-gray-200 bg-white p-3">
                                    <label class="block text-xs font-medium text-gray-500 mb-1.5">Brand</label>
                                    @php
                                        // Every brand already on a request, spelled once — picking one
                                        // keeps "Armani" and "ARMANI" from becoming two brands.
                                        $brandOptions = \App\Models\ProductRequest::query()
                                            ->whereNotNull('brand')->where('brand', '!=', '')
                                            ->distinct()->orderBy('brand')->pluck('brand')
                                            ->unique(fn ($b) => mb_strtolower(trim($b)))->values();
                                    @endphp
                                    {{-- Type to search the brands we have; a new one is added by typing it. --}}
                                    <div class="relative"
                                         x-data="{
                                            options: {{ Illuminate\Support\Js::from($brandOptions) }},
                                            open: false,
                                            active: 0,
                                            get typed() { return (brand || '').trim(); },
                                            get matches() {
                                                const q = this.typed.toLowerCase();
                                                return (q ? this.options.filter(b => b.toLowerCase().includes(q)) : this.options).slice(0, 100);
                                            },
                                            get isNew() {
                                                const q = this.typed.toLowerCase();
                                                return q !== '' && !this.options.some(b => b.toLowerCase() === q);
                                            },
                                            get count() { return this.matches.length + (this.isNew ? 1 : 0); },
                                            pick(b) { brand = b; this.open = false; },
                                            choose() {
                                                if (this.active < this.matches.length) this.pick(this.matches[this.active]);
                                                else if (this.isNew) this.pick(this.typed);
                                            },
                                            move(by) {
                                                if (!this.count) return;
                                                this.open = true;
                                                this.active = (this.active + by + this.count) % this.count;
                                                this.$nextTick(() => this.$refs.list?.querySelector('[data-active]')?.scrollIntoView({ block: 'nearest' }));
                                            },
                                         }"
                                         @click.outside="open = false">
                                        <input type="text" name="brand" x-model="brand" required autocomplete="off"
                                               placeholder="Search or add a brand" aria-label="Brand name"
                                               @focus="open = true" @input="open = true; active = 0"
                                               @keydown.arrow-down.prevent="move(1)" @keydown.arrow-up.prevent="move(-1)"
                                               @keydown.enter="if (open && count) { $event.preventDefault(); choose(); }"
                                               @keydown.escape="open = false" @keydown.tab="open = false"
                                               class="w-full rounded-lg border border-gray-300 bg-white pl-3 pr-9 py-2 text-sm text-gray-900 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent">
                                        <svg class="pointer-events-none absolute right-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M17 11a6 6 0 11-12 0 6 6 0 0112 0z"/></svg>

                                        <div x-show="open && count" x-cloak x-transition.origin.top
                                             class="absolute left-0 z-40 mt-1.5 min-w-full w-max max-w-sm rounded-xl border border-gray-200 bg-white shadow-xl overflow-hidden">
                                            <ul x-ref="list" class="max-h-64 overflow-y-auto py-1 text-sm">
                                                <template x-for="(b, i) in matches" :key="b">
                                                    <li>
                                                        <button type="button" @mousedown.prevent="pick(b)" @mouseenter="active = i"
                                                                :data-active="i === active ? '' : null"
                                                                class="w-full flex items-center justify-between gap-2 px-3 py-2 text-left"
                                                                :class="i === active ? 'bg-gray-100 text-gray-900' : 'text-gray-700'">
                                                            <span class="truncate" x-text="b"></span>
                                                            <svg x-show="b.toLowerCase() === typed.toLowerCase()" class="w-4 h-4 text-brand-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                                                        </button>
                                                    </li>
                                                </template>
                                                <li x-show="isNew" class="border-t border-gray-100">
                                                    <button type="button" @mousedown.prevent="pick(typed)" @mouseenter="active = matches.length"
                                                            :data-active="active === matches.length ? '' : null"
                                                            class="w-full flex items-center gap-2 px-3 py-2.5 text-left text-brand-700 font-medium"
                                                            :class="active === matches.length && 'bg-gray-100'">
                                                        <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
                                                        <span class="truncate">Add “<span x-text="typed"></span>” as a new brand</span>
                                                    </button>
                                                </li>
                                            </ul>
                                        </div>
                                        <p x-show="isNew && !open" x-cloak class="mt-1 text-xs text-brand-700">New brand — it will be added with this request.</p>
                                    </div>
                                </div>

                                <div class="rounded-lg border border-gray-200 bg-white p-3">
                                    <label class="block text-xs font-medium text-gray-500 mb-1.5">Category</label>
                                    @include('product-requests.partials.pretty-select', [
                                        'name' => 'category', 'model' => 'category', 'placeholder' => 'Select a category',
                                        'options' => array_combine(\App\Models\ProductRequest::CATEGORIES, \App\Models\ProductRequest::CATEGORIES),
                                    ])
                                </div>

                                <div class="rounded-lg border border-gray-200 bg-white p-3">
                                    <label class="block text-xs font-medium text-gray-500 mb-1.5">Website Go-Live Date</label>
                                    @include('product-requests.partials.date-picker', [
                                        'name' => 'online_launch_date', 'model' => 'onlineDate', 'placeholder' => 'Pick a date',
                                    ])
                                    <p x-show="onlineDate && onlineDate < todayIso" x-cloak class="text-xs text-amber-700 mt-1.5">This date is in the past.</p>
                                </div>

                                <div class="rounded-lg border border-gray-200 bg-white p-3">
                                    <label class="block text-xs font-medium text-gray-500 mb-1.5">Expected showroom launch <span class="font-normal text-gray-400">(optional)</span></label>
                                    @include('product-requests.partials.date-picker', [
                                        'name' => 'store_launch_date', 'model' => 'showroomDate', 'placeholder' => 'Pick a date',
                                        'dateOnly' => true, 'required' => false,
                                    ])
                                </div>

                                <div class="rounded-lg border border-gray-200 bg-white p-3">
                                    <label class="block text-xs font-medium text-gray-500 mb-1.5">Priority</label>
                                    <div class="flex rounded-lg bg-gray-100 p-1 gap-1">
                                        @foreach(\App\Models\ProductRequest::PRIORITIES as $value => $label)
                                            <label class="relative flex-1 text-center cursor-pointer rounded-md px-2 py-1 text-sm text-gray-600 transition-colors hover:text-gray-900 has-[:checked]:bg-white has-[:checked]:text-gray-900 has-[:checked]:font-medium has-[:checked]:shadow-sm">
                                                <input type="radio" name="priority" value="{{ $value }}" class="sr-only" required
                                                       {{ old('priority', 'medium') === $value ? 'checked' : '' }}>
                                                {{ $label }}
                                            </label>
                                        @endforeach
                                    </div>
                                </div>

                                <div class="rounded-lg border border-gray-200 bg-white p-3">
                                    <label class="block text-xs font-medium text-gray-500 mb-1.5">Is it new products?</label>
                                    <div class="flex rounded-lg bg-gray-100 p-1 gap-1">
                                        @foreach(['new_brand' => 'Yes', 'existing_brand' => 'No'] as $value => $label)
                                            <label class="relative flex-1 text-center cursor-pointer rounded-md px-2 py-1 text-sm text-gray-600 transition-colors hover:text-gray-900 has-[:checked]:bg-white has-[:checked]:text-gray-900 has-[:checked]:font-medium has-[:checked]:shadow-sm">
                                                <input type="radio" name="request_type" value="{{ $value }}" class="sr-only"
                                                       {{ old('request_type', 'new_brand') === $value ? 'checked' : '' }}>
                                                {{ $label }}
                                            </label>
                                        @endforeach
                                    </div>
                                </div>

                            {{-- Team: who the category hands this to, shown once a category is picked --}}
                            <div class="sm:col-span-2 rounded-lg border border-gray-200 bg-white p-3" x-data="{
                                    allRoles: {{ Illuminate\Support\Js::from(collect(\App\Models\ProductRequest::assignableRoles())->map(fn ($label, $key) => ['key' => $key, 'label' => $key === 'brand_manager_id' ? 'Brand Team' : $label])->values()) }},
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
                                    // Asked of the server, which runs the same lookup as the
                                    // real assignment: a brand or a website can be handed to
                                    // someone other than the category's usual people.
                                    team: null,
                                    load() {
                                        if (!category) { this.team = null; return; }
                                        const q = new URLSearchParams({ category, brand: brand || '', store_id: storeId || '' });
                                        fetch('{{ route('product-requests.team-preview') }}?' + q, { headers: { Accept: 'application/json' } })
                                            .then(r => r.json())
                                            .then(t => { this.team = t; })
                                            .catch(() => {});
                                    },
                                    personFor(key) {
                                        if (!this.team) return '';
                                        if (key === 'photographer_id')  return this.team.coordinator;
                                        if (key === 'brand_manager_id') return (this.team.brand_managers || []).join(', ');
                                        return this.team.owner;
                                    },
                                 }"
                                 x-init="load(); $watch('category', () => load()); $watch('brand', () => { clearTimeout(this._t); this._t = setTimeout(() => load(), 400); }); $watch('storeId', () => load())">
                                <label class="block text-xs font-medium text-gray-500 mb-1.5">Assigned to</label>
                                <template x-if="!category">
                                    <p class="text-sm text-gray-400">Pick a category to see who it is assigned to.</p>
                                </template>
                                <template x-if="category">
                                    <div class="flex flex-wrap gap-x-6 gap-y-1.5">
                                        <template x-for="r in activeRoles" :key="r.key">
                                            <p class="text-sm">
                                                <span class="text-gray-500" x-text="r.label + ':'"></span>
                                                <span :class="personFor(r.key) ? 'text-gray-900 font-medium' : 'text-amber-700'"
                                                      x-text="personFor(r.key) || 'Not set yet'"></span>
                                            </p>
                                        </template>
                                    </div>
                                </template>
                            </div>
                            </div>
                        </section>

                        {{-- Products --}}
                        <section class="bg-white/90 rounded-xl border border-white shadow-[0_8px_30px_-12px_rgba(15,23,42,.18)] p-4">
                            <h3 class="text-sm font-semibold text-gray-900 mb-3">Product list</h3>

                            @include('product-requests.partials.sku-csv-check')
                            {{-- Checked as soon as it is picked, so a wrong file is caught
                               now rather than after the whole form is filled in. --}}
                            <div x-data="{
                                    fileName: '',
                                    over: false,
                                    error: @js($errors->first('sku_csv') ?: null),
                                    async pick(files) {
                                        const file = files[0];
                                        this.error = await window.checkSkuCsv(file);
                                        if (this.error) {
                                            this.$refs.skuCsv.value = '';
                                            this.fileName = '';
                                        } else {
                                            this.fileName = file?.name || '';
                                        }
                                    }
                                 }">
                            <label @dragover.prevent="over = true" @dragleave.prevent="over = false"
                                   @drop.prevent="over = false; $refs.skuCsv.files = $event.dataTransfer.files; pick($refs.skuCsv.files)"
                                   class="relative flex flex-col items-center justify-center gap-1.5 cursor-pointer rounded-lg border-2 border-dashed px-4 py-8 text-center transition-colors"
                                   :class="over ? 'border-brand-500 bg-brand-50' : (error ? 'border-red-300 bg-red-50/50' : (fileName ? 'border-brand-300 bg-brand-50/50' : 'border-gray-300 hover:bg-gray-50'))">
                                <svg class="w-7 h-7" :class="error ? 'text-red-400' : (fileName ? 'text-brand-600' : 'text-gray-400')" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path x-show="!fileName" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/>
                                    <path x-show="fileName" x-cloak stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                                </svg>
                                <span class="text-sm font-medium text-gray-800" x-text="fileName || 'Upload SKU file'"></span>
                                <span class="text-xs text-gray-400" x-text="fileName ? 'Click to change' : 'CSV with a SKU or Item SKU column · drag and drop or click'"></span>
                                <input type="file" name="sku_csv" accept=".csv,.txt" x-ref="skuCsv" required class="sr-only"
                                       @change="pick($el.files)">
                            </label>
                            <p x-show="error" x-cloak x-text="error" class="mt-2 text-xs text-red-600"></p>
                            </div>
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

                        {{-- Not asked here: every request starts on AI copy, and the
                             request page's Details tab can switch it to the brand team. --}}
                        <input type="hidden" name="use_ai_content" value="1">

                    </div>

                </div>
            </div>

            <div class="px-4 sm:px-6 py-3.5 bg-white/60 border-t border-white/60 shrink-0">
                <div class="max-w-4xl mx-auto flex justify-end gap-3">
                    <button type="button" @click="newRequestOpen = false"
                            class="border border-gray-300 bg-white text-gray-700 text-sm font-medium px-4 py-2 rounded-lg hover:bg-gray-50 transition-colors">
                        Cancel
                    </button>
                    <button type="submit" :disabled="check.loading || submitting"
                            class="inline-flex items-center gap-2 text-white text-sm font-medium px-4 py-2 rounded-lg bg-brand-600 hover:bg-brand-700 shadow-sm transition-colors disabled:opacity-60">
                        <svg x-show="check.loading || submitting" x-cloak class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
                        <span x-text="usesMapping && !confirmed ? 'Check & submit' : 'Submit request'">Submit request</span>
                    </button>
                </div>
            </div>

            {{-- Check & confirm: which SKUs are mapped on the website and which still need mapping --}}
            {{-- Teleported to <main>: the New Request panel is its own layer, and
                 anything inside it sits under the chat button however high it goes. --}}
            <template x-teleport="main">
            <div x-show="check.open" x-cloak class="fixed inset-0 z-[200] flex items-center justify-center p-4" @keydown.escape.window="if (!check.loading) check.open = false">
                <div class="absolute inset-0 bg-gray-900/40 backdrop-blur-[2px]" @click="if (!check.loading) check.open = false"></div>
                <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-6xl max-h-[92vh] flex flex-col overflow-hidden"
                     x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100">

                    {{-- Checking --}}
                    <template x-if="check.loading">
                        <div class="px-6 py-14 text-center">
                            <svg class="mx-auto w-8 h-8 animate-spin text-brand-600" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
                            <p class="mt-4 text-sm font-medium text-gray-900">Checking your SKUs on the website…</p>
                            <p class="text-xs text-gray-500 mt-1">Looking up each one in Shopify. This takes a few seconds.</p>
                        </div>
                    </template>

                    {{-- Could not check --}}
                    <template x-if="!check.loading && check.error">
                        <div>
                            <div class="px-6 pt-6 pb-4 flex items-start gap-4">
                                <span class="w-11 h-11 rounded-2xl bg-red-100 text-red-600 flex items-center justify-center shrink-0">
                                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M5 19h14a2 2 0 001.84-2.75L13.74 4a2 2 0 00-3.48 0l-7.1 12.25A2 2 0 004.99 19z"/></svg>
                                </span>
                                <div>
                                    <h3 class="text-lg font-semibold text-gray-900">The check didn't work</h3>
                                    <p class="text-sm text-gray-600 mt-1" x-text="check.error"></p>
                                </div>
                            </div>
                            <div class="px-6 py-4 bg-gray-50 border-t border-gray-100 flex justify-end gap-2">
                                <button type="button" @click="check.open = false" class="border border-gray-300 bg-white text-gray-700 text-sm font-medium px-4 py-2 rounded-lg hover:bg-gray-50">Back</button>
                                <button type="button" @click="go(null)" class="text-white text-sm font-medium px-4 py-2 rounded-lg bg-brand-600 hover:bg-brand-700">Submit anyway</button>
                            </div>
                        </div>
                    </template>

                    {{-- The result --}}
                    <template x-if="!check.loading && check.result">
                        <div class="flex flex-col min-h-0">
                            <div class="px-6 pt-6 pb-4 flex items-start gap-4 border-b border-gray-100">
                                <span class="w-11 h-11 rounded-2xl flex items-center justify-center shrink-0"
                                      :class="!check.result.unmapped.length ? 'bg-green-100 text-green-600' : (check.result.mapped.length ? 'bg-amber-100 text-amber-600' : 'bg-red-100 text-red-600')">
                                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                         :d="!check.result.unmapped.length ? 'M5 13l4 4L19 7' : 'M12 9v2m0 4h.01M5 19h14a2 2 0 001.84-2.75L13.74 4a2 2 0 00-3.48 0l-7.1 12.25A2 2 0 004.99 19z'"/></svg>
                                </span>
                                <div class="min-w-0">
                                    <h3 class="text-lg font-semibold text-gray-900"
                                        x-text="!check.result.unmapped.length
                                            ? `All ${check.result.mapped.length} SKUs are mapped`
                                            : (check.result.mapped.length
                                                ? `${check.result.mapped.length} of ${check.result.mapped.length + check.result.unmapped.length} SKUs are mapped`
                                                : 'None of these SKUs are mapped yet')"></h3>
                                    <p class="text-sm text-gray-600 mt-1">
                                        <template x-if="!check.result.unmapped.length"><span>Everything is ready on <span x-text="check.result.store"></span>. Check the list, then create the request.</span></template>
                                        <template x-if="check.result.unmapped.length && check.result.mapped.length"><span>Only the mapped ones can go into this request. Map the other <b x-text="check.result.unmapped.length"></b> in Cegid on your side, then add them to the request later.</span></template>
                                        <template x-if="!check.result.mapped.length"><span>Map them in Cegid on your side first. Once they show on <span x-text="check.result.store"></span>, submit the request again.</span></template>
                                    </p>
                                </div>
                            </div>

                            <div class="px-6 py-4 overflow-y-auto space-y-5">
                                {{-- Mapped --}}
                                <section x-show="check.result.mapped.length">
                                    <div class="flex items-center justify-between mb-2">
                                    <h4 class="text-xs font-semibold uppercase tracking-wider text-green-700 flex items-center gap-1.5">
                                        <span class="w-2 h-2 rounded-full bg-green-500"></span>
                                        <span x-text="`Mapped — goes into the request (${check.result.mapped.length})`"></span>
                                    </h4>
                                    <button type="button" class="text-xs font-medium text-brand-700 hover:text-brand-800"
                                            @click="Object.values(expanded).some(v => v) ? (expanded = {}) : openAll(check.result.mapped.map(r => r.sku))"
                                            x-text="Object.values(expanded).some(v => v) ? 'Hide all colours & sizes' : 'Show all colours & sizes'"></button>
                                    </div>
                                    <div class="space-y-1.5">
                                        <template x-for="row in check.result.mapped" :key="row.sku">
                                            <div class="rounded-xl border border-gray-200">
                                                <button type="button" @click="variants(row.sku)" class="w-full flex items-center gap-3 px-3 py-2.5 text-left hover:bg-gray-50 rounded-xl">
                                                    <span class="w-8 h-8 rounded-lg bg-green-50 text-green-600 flex items-center justify-center shrink-0">
                                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                                                    </span>
                                                    <span class="min-w-0 flex-1">
                                                        <span class="block text-sm font-medium text-gray-900 truncate" x-text="row.title || 'Untitled product'"></span>
                                                        <span class="block text-xs font-mono text-gray-500" x-text="row.sku"></span>
                                                    </span>
                                                    <span class="text-[11px] font-medium rounded-full px-2 py-0.5"
                                                          :class="row.published ? 'bg-emerald-50 text-emerald-700' : 'bg-gray-100 text-gray-500'"
                                                          x-text="row.published ? 'Published' : 'Draft'"></span>
                                                    <span class="text-xs font-medium text-brand-700 whitespace-nowrap" x-text="expanded[row.sku] ? 'Hide' : 'Colours & sizes'"></span>
                                                </button>
                                                <div x-show="expanded[row.sku]" class="border-t border-gray-100 bg-gray-50/70 px-3 pb-3 rounded-b-xl">
                                                    <template x-if="expanded[row.sku]">
                                                        <div x-data="{ get breakdown() { return cache[row.sku]?.data }, get breakdownLoading() { return cache[row.sku]?.loading ?? true }, get breakdownError() { return cache[row.sku]?.error } }">
                                                            @include('partials.variant-breakdown')
                                                        </div>
                                                    </template>
                                                </div>
                                            </div>
                                        </template>
                                    </div>
                                </section>

                                {{-- Not mapped --}}
                                <section x-show="check.result.unmapped.length">
                                    <h4 class="text-xs font-semibold uppercase tracking-wider text-red-600 mb-2 flex items-center gap-1.5">
                                        <span class="w-2 h-2 rounded-full bg-red-500"></span>
                                        <span x-text="`Not mapped yet — map these in Cegid (${check.result.unmapped.length})`"></span>
                                    </h4>
                                    <div class="flex flex-wrap gap-1.5">
                                        <template x-for="sku in check.result.unmapped" :key="sku">
                                            <span class="rounded-lg bg-red-50 border border-red-100 px-2 py-1 font-mono text-xs text-red-700" x-text="sku"></span>
                                        </template>
                                    </div>
                                    <button type="button" @click="navigator.clipboard?.writeText(check.result.unmapped.join('\n')); $el.textContent = 'Copied ✓'"
                                            class="mt-2 text-xs font-medium text-brand-700 hover:text-brand-800">Copy the list</button>
                                </section>
                            </div>

                            <div class="px-6 py-4 bg-gray-50 border-t border-gray-100 flex flex-wrap justify-end gap-2">
                                <button type="button" @click="check.open = false" class="border border-gray-300 bg-white text-gray-700 text-sm font-medium px-4 py-2 rounded-lg hover:bg-gray-50">Back</button>
                                <button type="button" x-show="check.result.mapped.length" @click="go(check.result.mapped.map(r => r.sku))"
                                        class="inline-flex items-center gap-2 text-white text-sm font-medium px-4 py-2 rounded-lg bg-brand-600 hover:bg-brand-700">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                    <span x-text="check.result.unmapped.length ? `Create request with ${check.result.mapped.length} mapped SKUs` : 'Create request'"></span>
                                </button>
                            </div>
                        </div>
                    </template>
                </div>
            </div>
            </template>
        </form>
    </div>
</div>
