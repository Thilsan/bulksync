@extends('layouts.app')

@section('title', 'Configure — ' . $session->name)

@section('content')
{{--
    The step between finding the photos and paying for them.

    The settings themselves were already chosen on the previous screen and
    arrive here filled in — this screen is the last look at them against the
    actual photos, plus the two things that could not be asked before the
    folder was read: which SKUs want something different from the rest, and
    what the run is about to cost. Nothing is sent to Photoroom until this
    form is submitted.
--}}
<div class="space-y-5" x-data="configureRun()" @photo-credits-changed.window="recount()">

    <div>
        <p class="text-xs text-gray-500">Media &rsaquo; Photo Editor</p>
        <h1 class="text-2xl font-semibold text-gray-900">{{ $session->name }}</h1>
        <p class="mt-1 text-sm text-gray-500">
            Your settings are already in. Change anything that should differ for one SKU, then start —
            nothing is sent to Photoroom until you do.
        </p>
    </div>

    @if (session('error'))
        <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ session('error') }}</div>
    @endif

    {{-- A failed scan used to land here too, and this screen told the operator
         it was still working — forever, on a job that had already given up.
         The failure has to be louder than the wait, not quieter. --}}
    @if ($session->scan_status === 'failed')
        <div class="rounded-xl border border-red-200 bg-red-50 p-8 text-center">
            <p class="text-sm font-semibold text-red-800">The folder could not be read.</p>
            <p class="mx-auto mt-2 max-w-xl text-sm text-red-700">
                {{ $session->error_message ?: 'No reason was recorded. The queue worker log will have it.' }}
            </p>
            <a href="{{ route('photo-editor.index') }}"
               class="mt-4 inline-block rounded-lg bg-white px-4 py-2 text-sm font-medium text-gray-700 ring-1 ring-gray-300 hover:bg-gray-50">
                Start again
            </a>
        </div>
    @elseif ($session->scan_status !== 'scanned')
        <div class="rounded-xl border border-gray-200 bg-white p-8 text-center"
             x-init="setTimeout(() => window.location.reload(), 4000)">
            <p class="text-sm font-medium text-gray-800">Still reading the folder…</p>
            <p class="mt-1 text-xs text-gray-500">
                {{ number_format($session->scanned_files) }} found so far. This page refreshes itself.
            </p>

            {{-- 'pending' means no worker ever picked the job up, which looks
                 exactly like a slow scan until you know to tell them apart. --}}
            @if ($session->scan_status === 'pending' && $session->created_at->lt(now()->subMinute()))
                <p class="mx-auto mt-4 max-w-lg rounded-lg bg-amber-50 px-4 py-3 text-xs text-amber-800">
                    Nothing has picked this up in over a minute. The queue worker is probably not running —
                    check <code>supervisorctl status</code> and that it listens on the <code>bulkupload</code> queue.
                </p>
            @endif
        </div>
    @elseif ($groups->isEmpty())
        <div class="rounded-xl border border-gray-200 bg-white p-8 text-center">
            <p class="text-sm text-gray-700">{{ $session->error_message ?: 'No images were found in that folder.' }}</p>
            <a href="{{ route('photo-editor.index') }}" class="mt-3 inline-block text-sm text-brand-600 hover:underline">Start again</a>
        </div>
    @else
    <form method="POST" action="{{ route('photo-editor.start', $session) }}" @submit="starting = true">
        @csrf

        {{-- Running total, so the bill is visible before it is committed to
             rather than discovered afterwards. --}}
        <div class="sticky top-0 z-10 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-gray-200 bg-white/95 px-5 py-4 backdrop-blur">
            <div class="text-sm text-gray-700">
                <span class="font-semibold" x-text="totalCredits"></span> Photoroom credits
                <span class="text-gray-400">·</span>
                <span x-text="photoCount"></span> photos
                <span class="text-gray-400">+</span>
                <span x-text="lifestyleTotal"></span> on-model
                <template x-if="untouched">
                    <span class="text-gray-400">&minus; <span x-text="untouched"></span> untouched</span>
                </template>
                <span class="ml-2 text-xs text-gray-500">of {{ number_format($monthlyQuota) }} this month</span>
            </div>
            <button type="submit" :disabled="starting"
                    class="rounded-lg bg-brand-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-brand-700 disabled:opacity-60">
                <span x-text="starting ? 'Starting…' : 'Start editing'"></span>
            </button>
        </div>

        {{-- Carried over from the previous screen, where it was chosen once for
             the whole folder. Collapsed, because it has already been answered —
             the cards below only carry settings when a product genuinely
             differs from it. --}}
        <div class="mb-4 overflow-hidden rounded-xl border border-gray-200 bg-white">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 px-5 py-4">
                <div>
                    <h2 class="text-sm font-semibold text-gray-900">Settings for this run</h2>
                    <p class="text-xs text-gray-500">
                        As you picked them on the previous screen. Applied to every SKU below unless one is set to differ.
                    </p>
                </div>
                <button type="button" @click="runOpen = !runOpen" class="text-xs font-medium text-brand-600 hover:underline">
                    <span x-text="runOpen ? 'Hide' : 'Change'"></span>
                </button>
            </div>
            <div x-show="runOpen" x-cloak class="space-y-5 px-5 py-5">
                @include('photo-editor.partials.group-settings', [
                    'prefix'        => 'edits',
                    'uid'           => 'run',
                    'edits'         => $session->edits ?? [],
                    'beautifyModes' => $beautifyModes,
                ])
            </div>
        </div>

        <div class="space-y-4">
            @foreach ($groups as $group)
                @php
                    $groupPhotos = $photos[$group->sku] ?? collect();

                    /*
                     * Keyed by id because the grid is rendered by Alpine from an
                     * array of ids now, and the thumbnail URL has to be built
                     * here where the route helper lives. Assembled in PHP rather
                     * than inside @json(), which cannot be trusted with a
                     * nested array spread over several lines.
                     */
                    $photoMeta = $groupPhotos->mapWithKeys(fn ($p) => [
                        $p->id => [
                            'filename' => $p->filename,
                            'thumb'    => route('photo-editor.onedrive-thumb', [$session, $p]),
                            'asIs'     => (bool) $p->skip_edit,
                            'keepBg'   => (bool) $p->keep_background,
                        ],
                    ])->all();
                @endphp

                <script>
                    window.photoEditorPhotos = Object.assign(window.photoEditorPhotos || {}, @json($photoMeta));
                </script>

                <div class="overflow-hidden rounded-xl border border-gray-200 bg-white"
                     x-data="{
                        open: {{ $group->edits ? 'true' : 'false' }},
                        differs: {{ $group->edits ? 'true' : 'false' }},
                        lifestyle: {{ (int) $group->lifestyle_count }},
                     }"
                     x-init="$watch('lifestyle', () => recount())">

                    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 px-5 py-4">
                        <div class="flex items-baseline gap-3">
                            <h2 class="font-mono text-base font-semibold tracking-tight text-gray-900">{{ $group->sku }}</h2>
                            <p class="text-xs text-gray-400">{{ $groupPhotos->count() }} {{ Str::plural('photo', $groupPhotos->count()) }}</p>
                        </div>
                        <label class="flex cursor-pointer items-center gap-2 rounded-lg px-2.5 py-1.5 text-xs transition-colors hover:bg-gray-50"
                               :class="differs ? 'text-brand-700' : 'text-gray-500'">
                            <input type="checkbox" name="groups[{{ $group->id }}][differs]" value="1"
                                   x-model="differs" @change="open = differs"
                                   class="h-3.5 w-3.5 rounded border-gray-300 text-brand-600 focus:ring-brand-500">
                            <span x-text="differs ? 'Settings differ for this SKU' : 'Same as the run'"></span>
                        </label>
                    </div>

                    {{-- Thumbnails come from OneDrive's own preview renderer.
                         The originals are ~9 MB each and none of them has been
                         edited yet, so pulling the real files to show a grid
                         nobody has decided anything on would cost minutes.

                         Drag to reorder. The order here is the order the photos
                         are given to Shopify, so the first one becomes the
                         product's main image. --}}
                    <div class="px-5 py-4"
                         x-data="photoOrder(@js($groupPhotos->pluck('id')->all()))">
                        <div class="mb-3 flex flex-wrap items-baseline justify-between gap-2">
                            <p class="text-xs text-gray-500">
                                Drag to reorder — photo 1 becomes the product's main image on Shopify.
                            </p>
                            <p class="flex items-center gap-2 text-xs">
                                <span x-show="asIsCount" x-cloak class="rounded-full bg-amber-50 px-2 py-0.5 font-medium text-amber-700">
                                    <span x-text="asIsCount"></span> untouched — no credit
                                </span>
                                <span x-show="keepBgCount" x-cloak class="rounded-full bg-sky-50 px-2 py-0.5 font-medium text-sky-700">
                                    <span x-text="keepBgCount"></span> keeping its background
                                </span>
                                <span x-show="!asIsCount && !keepBgCount" class="text-gray-400">
                                    Every photo cut out and reframed
                                </span>
                            </p>
                        </div>

                        {{-- Four or five to a row rather than ten. These are
                             the pictures the whole decision rests on, and at a
                             tenth of the width nobody can see what they are
                             deciding about. --}}
                        <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5">
                            <template x-for="(id, index) in order" :key="id">
                                <div class="group"
                                     draggable="true"
                                     @dragstart="from = index"
                                     @dragover.prevent
                                     @drop.prevent="moveTo(index)"
                                     :class="from === index ? 'opacity-40' : ''">
                                    <input type="hidden" :name="`groups[{{ $group->id }}][order][]`" :value="id">

                                    <label class="relative block cursor-grab active:cursor-grabbing">
                                        <input type="radio" class="peer sr-only"
                                               name="groups[{{ $group->id }}][lifestyle_source_item_id]"
                                               :value="id" :checked="id === {{ (int) $group->lifestyle_source_item_id }}">

                                        {{-- object-contain, not cover: a crop can
                                             hide the very edge of a product that
                                             decides whether a shot is usable. --}}
                                        <div class="relative overflow-hidden rounded-xl border-2 bg-white transition-colors peer-checked:border-brand-500"
                                             :class="asIs[id] ? 'border-amber-400' : (keepBg[id] ? 'border-sky-400' : (index === 0 ? 'border-brand-300' : 'border-gray-200'))">
                                            <img :src="photos[id].thumb" :alt="photos[id].filename" loading="lazy"
                                                 class="aspect-square w-full bg-white object-contain">

                                            {{-- Position is the one number here
                                                 with a consequence on the shop:
                                                 the first photo becomes the
                                                 product's main image. --}}
                                            <span class="pointer-events-none absolute left-2 top-2 flex h-6 w-6 items-center justify-center rounded-full text-xs font-bold text-white shadow-sm"
                                                  :class="index === 0 ? 'bg-brand-600' : 'bg-gray-900/60'"
                                                  x-text="index + 1"></span>

                                            <span x-show="index === 0"
                                                  class="pointer-events-none absolute right-2 top-2 rounded-full bg-brand-600 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-white shadow-sm">
                                                Main
                                            </span>

                                            <span x-show="lifestyle > 0 && index !== 0" x-cloak
                                                  class="pointer-events-none absolute right-2 top-2 rounded-full bg-white/95 px-2 py-0.5 text-[10px] font-semibold text-gray-600 shadow-sm peer-checked:bg-brand-600 peer-checked:text-white">
                                                Model wears this
                                            </span>

                                            <button type="button" x-show="index !== 0" @click.prevent="makeFirst(index)"
                                                    class="absolute inset-x-2 bottom-2 rounded-lg bg-gray-900/85 py-1.5 text-xs font-medium text-white opacity-0 transition-opacity group-hover:opacity-100 focus:opacity-100">
                                                Make main image
                                            </button>
                                        </div>
                                    </label>

                                    <p class="mt-2 truncate text-xs text-gray-500" x-text="photos[id].filename"></p>

                                    {{-- The two treatments, spelled out under
                                         every photo rather than revealed on
                                         hover. Hidden, they were a feature
                                         nobody knew was there — and each one
                                         changes what the run costs. --}}
                                    <div class="mt-1.5 grid grid-cols-2 gap-1.5">
                                        <button type="button" @click.prevent="toggleAsIs(id)"
                                                :title="asIs[id] ? 'Going to Shopify untouched — click to edit it instead' : 'Will be edited — click to send it as it is'"
                                                :class="asIs[id]
                                                    ? 'bg-amber-500 text-white ring-amber-500'
                                                    : 'bg-white text-gray-500 ring-gray-200 hover:ring-gray-300'"
                                                class="rounded-lg px-2 py-1 text-[11px] font-semibold ring-1 transition-colors">
                                            As is
                                        </button>

                                        <button type="button" @click.prevent="toggleKeepBg(id)"
                                                :title="keepBg[id] ? 'Keeping its background — click to cut it out instead' : 'Will be cut out — click to keep its background and only reframe it'"
                                                :class="keepBg[id]
                                                    ? 'bg-sky-500 text-white ring-sky-500'
                                                    : 'bg-white text-gray-500 ring-gray-200 hover:ring-gray-300'"
                                                class="rounded-lg px-2 py-1 text-[11px] font-semibold ring-1 transition-colors">
                                            Keep BG
                                        </button>
                                    </div>

                                    <template x-if="asIs[id]">
                                        <input type="hidden" :name="`groups[{{ $group->id }}][as_is][]`" :value="id">
                                    </template>

                                    <template x-if="keepBg[id]">
                                        <input type="hidden" :name="`groups[{{ $group->id }}][keep_bg][]`" :value="id">
                                    </template>
                                </div>
                            </template>
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center gap-3 border-t border-gray-100 bg-gray-50 px-5 py-3">
                        <label for="lifestyle-{{ $group->id }}" class="text-xs font-medium text-gray-700">On-model images</label>
                        <select id="lifestyle-{{ $group->id }}" name="groups[{{ $group->id }}][lifestyle_count]"
                                x-model.number="lifestyle"
                                class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:border-brand-500 focus:outline-none">
                            @for ($n = 0; $n <= $maxLifestyle; $n++)
                                <option value="{{ $n }}">{{ $n === 0 ? 'None' : $n }}</option>
                            @endfor
                        </select>
                        <p x-show="lifestyle > 0" x-cloak class="text-xs text-amber-700">
                            Generated, not photographed — tick which photo the model wears above, and check every result.
                            Costs <span x-text="lifestyle"></span> extra {{ Str::plural('credit', 2) }}.
                        </p>
                    </div>

                    {{-- Only rendered when the SKU is set to differ. Seeded
                         from the run's settings, so "differs" starts as a copy
                         of what it was already going to get. --}}
                    <div x-show="differs" x-cloak class="space-y-5 border-t border-gray-100 px-5 py-5">
                        @include('photo-editor.partials.group-settings', [
                            'prefix'        => "groups[{$group->id}][edits]",
                            'uid'           => $group->id,
                            'edits'         => $group->edits ?: ($session->edits ?? []),
                            'beautifyModes' => $beautifyModes,
                        ])
                    </div>
                </div>
            @endforeach
        </div>
    </form>
    @endif
</div>

<script>
    /*
     * The order of one SKU's photos, held as a list of ids.
     *
     * Rendering the grid from the list rather than moving DOM nodes around is
     * what keeps the hidden inputs, the position badges and the pictures from
     * ever disagreeing — there is one array, and everything on screen is a
     * reading of it.
     */
    function photoOrder(ids) {
        const photos = window.photoEditorPhotos || {};

        return {
            order: ids,
            photos,
            from: null,

            // Seeded from what was stored, so reopening the screen shows the
            // same answer it was left with.
            asIs: Object.fromEntries(ids.map(id => [id, !! (photos[id] || {}).asIs])),
            keepBg: Object.fromEntries(ids.map(id => [id, !! (photos[id] || {}).keepBg])),

            get asIsCount() {
                return Object.values(this.asIs).filter(Boolean).length;
            },

            get keepBgCount() {
                return Object.values(this.keepBg).filter(Boolean).length;
            },

            // The two are alternatives, not layers: untouched never reaches
            // Photoroom, so it cannot also be asking Photoroom for a reframe.
            // Turning one on turns the other off rather than leaving a state
            // the form would have to arbitrate.
            toggleAsIs(id) {
                this.asIs[id] = ! this.asIs[id];

                if (this.asIs[id]) {
                    this.keepBg[id] = false;
                }

                window.dispatchEvent(new CustomEvent('photo-credits-changed'));
            },

            toggleKeepBg(id) {
                this.keepBg[id] = ! this.keepBg[id];

                if (this.keepBg[id]) {
                    this.asIs[id] = false;
                }

                // Still billable either way, so the credit estimate does not
                // move — but it does when this clears an untouched tick.
                window.dispatchEvent(new CustomEvent('photo-credits-changed'));
            },

            moveTo(to) {
                if (this.from === null || this.from === to) {
                    this.from = null;
                    return;
                }

                const moved = this.order.splice(this.from, 1)[0];
                this.order.splice(to, 0, moved);
                this.from = null;
            },

            makeFirst(index) {
                this.order.unshift(this.order.splice(index, 1)[0]);
            },
        };
    }

    function configureRun() {
        return {
            starting: false,
            runOpen: false,
            photoCount: {{ $photos->flatten()->count() }},
            lifestyleTotal: {{ $groups->sum('lifestyle_count') }},

            untouched: {{ $photos->flatten()->where('skip_edit', true)->count() }},

            get totalCredits() {
                return this.photoCount - this.untouched + this.lifestyleTotal;
            },

            recount() {
                /*
                 * Read the DOM rather than tracking each card's state
                 * separately — one source of truth for the number the operator
                 * is being asked to commit to. The "as is" photos are counted
                 * from their hidden inputs, which exist only while a photo is
                 * marked, so the count cannot drift from what will be posted.
                 */
                this.lifestyleTotal = Array.from(
                    document.querySelectorAll('select[name$="[lifestyle_count]"]')
                ).reduce((sum, el) => sum + Number(el.value || 0), 0);

                this.untouched = document.querySelectorAll('input[name$="[as_is][]"]').length;
            },
        };
    }
</script>
@endsection
