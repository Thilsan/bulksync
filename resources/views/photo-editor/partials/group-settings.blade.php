{{--
    The edit settings, rendered twice: once for the run as a whole, and again
    inside any SKU card that needs to differ from it.

    $prefix is the form-name root — "edits" for the run, "groups[7][edits]" for
    a SKU. $uid keeps element ids unique between the copies, since the same
    field can be on screen more than once.

    Deliberately a subset of everything Photoroom accepts: the fields whose
    right answer actually differs between a dress, a watch and a cap. Anything
    not shown keeps whatever it already had rather than being reset to a
    default nobody chose — see PhotoEditorController::editsFromRequest().
--}}
@php
    $name = fn ($field) => $prefix . '[' . $field . ']';
    $val  = fn ($field, $default = null) => data_get($edits, $field, $default);
    $bg   = $val('background_mode', 'white');

    /*
     * Per-edge spacing is stored with its unit ("40px") so nothing downstream
     * has to guess; the boxes show just the number beside a px label.
     */
    $edgePx = function (string $side) use ($val) {
        $stored = (string) $val('padding_' . $side);

        return str_ends_with($stored, 'px') ? (int) rtrim($stored, 'px') : null;
    };

    // The tree lays the buttons out; the flat map, handed to the browser whole,
    // is what a click reads — so it sets the same values the server will write
    // when the form comes back.
    $framingTree  = \App\Services\PhotoroomService::FRAMING_PRESETS;
    $productNouns = \App\Services\PhotoroomService::PRODUCT_NOUNS;

    $framingPresets = collect(\App\Services\PhotoroomService::framingPresetsFlat())
        ->map(fn (array $preset) => [
            'label' => $preset['full'],
            'note'  => $preset['note'],
            'edits' => array_merge(\App\Services\PhotoroomService::FRAMING_FIELDS, $preset['edits']),
        ]);

    // Which main category opens on arrival: the one already chosen, or the
    // first, so the second row is never empty and waiting to be understood.
    $framingMain = str_contains((string) $val('framing_preset'), '/')
        ? explode('/', (string) $val('framing_preset'))[0]
        : array_key_first($framingTree);
@endphp

<div x-data="{ bg: @js($bg) }">
    <span class="mb-2 block text-xs font-semibold uppercase tracking-wide text-gray-500">Background</span>
    <div class="grid gap-3 sm:grid-cols-4">
        @foreach ([
            'white'       => 'Solid white',
            'transparent' => 'Transparent',
            'custom'      => 'Brand colour',
            'blur'        => 'Blur the original',
        ] as $mode => $label)
            <label class="flex cursor-pointer items-center gap-2 rounded-lg border border-gray-200 px-3 py-2 text-sm hover:border-gray-300 has-[:checked]:border-brand-500 has-[:checked]:bg-brand-50/60">
                <input type="radio" name="{{ $name('background_mode') }}" value="{{ $mode }}" @checked($bg === $mode)
                       x-model="bg"
                       class="h-3.5 w-3.5 border-gray-300 text-brand-600 focus:ring-brand-500">
                <span class="text-gray-800">{{ $label }}</span>
            </label>
        @endforeach
    </div>

    <div class="mt-3 flex items-center gap-3" x-show="bg === 'custom'" x-cloak>
        <label for="colour-{{ $uid }}" class="text-xs text-gray-600">Hex colour</label>
        <input id="colour-{{ $uid }}" type="text" name="{{ $name('background_color') }}"
               value="{{ $val('background_color') }}" placeholder="F5F5F5" maxlength="7"
               class="w-32 rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:border-brand-500 focus:outline-none">
    </div>

    {{-- remove_background is derived, not asked: blur is the one mode that
         keeps the original scene, so it is the one mode that does not remove it.
         Bound to the live choice rather than the stored one, or switching to
         blur would not take effect until the form had been saved once. --}}
    <input type="hidden" name="{{ $name('remove_background') }}" :value="bg === 'blur' ? '' : '1'">
</div>

<div>
    <span class="mb-2 block text-xs font-semibold uppercase tracking-wide text-gray-500">Treatment</span>
    <div class="grid gap-3 sm:grid-cols-2">
        @foreach ([
            'none'            => ['Keep the photo', 'Real pixels, exactly as shot.'],
            'ghost_mannequin' => ['Keep the photo + AI cleanup', 'Redraws the garment — check every result. Costs an extra credit.'],
        ] as $mode => [$label, $help])
            @php $isGhost = $mode === 'ghost_mannequin'; @endphp
            <label class="flex cursor-pointer items-start gap-2 rounded-lg border border-gray-200 p-3 hover:border-gray-300 has-[:checked]:border-brand-500 has-[:checked]:bg-brand-50/60">
                <input type="radio" name="{{ $name('ghost_mannequin') }}" value="{{ $isGhost ? '1' : '' }}"
                       @checked((bool) $val('ghost_mannequin') === $isGhost)
                       class="mt-0.5 h-3.5 w-3.5 border-gray-300 text-brand-600 focus:ring-brand-500">
                <span>
                    <span class="block text-sm font-medium text-gray-800">{{ $label }}</span>
                    <span class="block text-xs text-gray-500">{{ $help }}</span>
                </span>
            </label>
        @endforeach
    </div>

    <div class="mt-3 grid gap-3 sm:grid-cols-2">
        <div>
            <label for="seg-keep-{{ $uid }}" class="mb-1 block text-xs text-gray-600">Keep (describe the product)</label>
            {{-- data-auto marks a value the category put here rather than a
                 person. Only those get overwritten when the category changes;
                 anything typed by hand is left alone. --}}
            <input id="seg-keep-{{ $uid }}" type="text" name="{{ $name('segmentation_prompt') }}"
                   value="{{ $val('segmentation_prompt') }}" placeholder="the dress" maxlength="500"
                   data-auto="{{ filled($val('segmentation_prompt')) ? '0' : '1' }}"
                   @input="$el.dataset.auto = '0'"
                   class="w-full rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:border-brand-500 focus:outline-none">
        </div>
        <div>
            <label for="seg-drop-{{ $uid }}" class="mb-1 block text-xs text-gray-600">Remove</label>
            <input id="seg-drop-{{ $uid }}" type="text" name="{{ $name('segmentation_negative_prompt') }}"
                   value="{{ $val('segmentation_negative_prompt') }}" placeholder="the mannequin and stand" maxlength="500"
                   class="w-full rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:border-brand-500 focus:outline-none">
        </div>
    </div>
    <p class="mt-1 text-xs text-gray-500">
        Filling in <em>Keep</em> is what removes a mannequin from the real photograph — nothing is redrawn, and it
        costs half what the AI cleanup does.
        <span class="cursor-help border-b border-dotted border-gray-400"
              title="Naming the product cuts the stand out of the photograph itself, inside the single cutout request, so the garment cannot shift or change shape. Leave Keep blank and the AI cleanup pass runs instead.">Why?</span>
    </p>
</div>

<div x-data="{
        preset:  @js($val('framing_preset')),
        presets: @js($framingPresets),
        nouns:   @js($productNouns),
        main:    @js($framingMain),

        width:   {{ $val('width') ?: 'null' }},
        height:  {{ $val('height') ?: 'null' }},
        padding: {{ $val('padding') ?: 0 }},
        padTop:    @js($edgePx('top')),
        padBottom: @js($edgePx('bottom')),
        padLeft:   @js($edgePx('left')),
        padRight:  @js($edgePx('right')),
        hAlign:  @js($val('h_align', 'center')),
        vAlign:  @js($val('v_align', 'center')),
        scaling: @js($val('scaling', 'fit')),
        refBox:  @js($val('reference_box', 'subjectBox')),
        custom:  false,

        pick(w, h) { this.width = w; this.height = h; this.custom = false },
        clear()    { this.width = null; this.height = null; this.custom = false },

        openMain(key) {
            this.main = key;
        },

        usePreset(key) {
            this.preset = key || null;

            if (! key) return;

            this.main = key.split('/')[0];

            const e = this.presets[key].edits;

            this.width  = e.width;
            this.height = e.height;
            this.padding = e.padding ?? 0;
            this.padTop = e.padding_top; this.padBottom = e.padding_bottom;
            this.padLeft = e.padding_left; this.padRight = e.padding_right;
            this.hAlign = e.h_align; this.vAlign = e.v_align;
            this.scaling = e.scaling; this.refBox = e.reference_box;
            this.custom = false;

            this.nameTheProduct(key);
        },

        /*
         * Fill in what the product is called, so the cheap cutout is what runs
         * when a hanger or a dress form is in shot. Left blank, a garment on a
         * hanger goes down the generative route instead — an extra credit, and
         * the garment comes back redrawn.
         *
         * A hand-typed value is never overwritten: the category is a sensible
         * default, not a better answer than the person looking at the photo.
         */
        nameTheProduct(key) {
            const box = document.getElementById('seg-keep-{{ $uid }}');

            if (! box || box.dataset.auto !== '1') return;

            box.value = this.nouns[key] || '';
            box.dispatchEvent(new Event('change'));
        },
     }">
    <span class="mb-2 block text-xs font-semibold uppercase tracking-wide text-gray-500">Size &amp; framing</span>

    {{-- The whole point of the screen for a catalogue: pick the category once
         and every photo under it is framed to the same numbers, so the product
         lands on the same spot on the canvas whatever the shoot did. Nothing
         below is a per-photo decision after that, which is why picking a
         category locks the boxes rather than merely filling them in. --}}
    <div class="rounded-lg border border-gray-200 bg-gray-50 p-3">
        <span class="mb-2 block text-xs font-medium text-gray-700">Category</span>

        {{-- Main category, then what sits under it. Two rows rather than one
             long list of "Women's dresses, Men's shirts, Kids' tops": the
             catalogue is navigated this way, so the picker is too. --}}
        <div class="flex flex-wrap gap-2">
            @foreach ($framingTree as $mainKey => $main)
                <button type="button" @click="openMain('{{ $mainKey }}')"
                        :class="main === '{{ $mainKey }}'
                            ? 'border-gray-800 bg-white text-gray-900 shadow-sm'
                            : 'border-transparent bg-transparent text-gray-500 hover:text-gray-800'"
                        class="rounded-lg border px-3 py-1.5 text-xs font-semibold transition-colors">
                    {{ $main['label'] }}
                    {{-- So it is obvious which main category holds the choice
                         when the open one is not the chosen one. --}}
                    <span x-show="preset && preset.split('/')[0] === '{{ $mainKey }}'" x-cloak
                          class="ml-1 text-brand-600">&bull;</span>
                </button>
            @endforeach
        </div>

        <div class="mt-2 flex flex-wrap gap-2 border-t border-gray-200 pt-2">
            @foreach ($framingTree as $mainKey => $main)
                @foreach ($main['subcategories'] as $subKey => $sub)
                    @php $presetKey = $mainKey . '/' . $subKey; @endphp
                    <button type="button" x-show="main === '{{ $mainKey }}'" x-cloak
                            @click="usePreset('{{ $presetKey }}')"
                            :class="preset === '{{ $presetKey }}' ? 'border-brand-600 bg-brand-600 text-white' : 'border-gray-300 bg-white text-gray-700 hover:border-brand-400'"
                            class="rounded-lg border px-3 py-1.5 text-xs font-medium transition-colors">{{ $sub['label'] }}</button>
                @endforeach
            @endforeach

            <button type="button" @click="usePreset('')"
                    :class="! preset ? 'border-gray-800 bg-gray-800 text-white' : 'border-gray-300 bg-white text-gray-600 hover:border-gray-400'"
                    class="rounded-lg border px-3 py-1.5 text-xs font-medium transition-colors">Frame by hand</button>
        </div>

        <p x-show="preset" x-cloak class="mt-2 text-xs text-gray-600">
            <span x-text="presets[preset]?.note"></span>
            Every photo under this category is framed to these numbers, so they line up with each other and with
            what is already on the website.
        </p>
        <p x-show="! preset" x-cloak class="mt-2 text-xs text-gray-500">
            Framing set by hand below. Fine for a one-off, but two SKUs framed by hand on different days will not
            match each other.
        </p>

        {{-- The name of the standard, kept beside the values it produced. The
             server expands it again on the way in: the boxes below are disabled
             while a category is picked, so a browser posts back only some of
             them, and the category is the thing that decides them all. --}}
        <input type="hidden" name="{{ $name('framing_preset') }}"
               value="{{ $val('framing_preset') }}" :value="preset ?? ''">
    </div>

    {{-- What the category settled, said plainly. Ten disabled inputs describe
         the same thing and are far harder to read — and reading is all anyone
         does with them once a category is picked. --}}
    <p x-show="preset" x-cloak class="mt-3 text-xs text-gray-600">
        <span class="font-medium text-gray-800">Framing:</span>
        <span x-text="width && height ? `${width} × ${height}` : 'original size'"></span>
        &middot; <span x-text="Math.round(padding * 100) + '% around'"></span>
        <template x-if="padBottom">
            <span>&middot; base <span x-text="Math.round(padBottom * 100) + '%'"></span> up</span>
        </template>
        &middot; <span x-text="{ top: 'top-aligned', bottom: 'standing on the base line', center: 'centred' }[vAlign]"></span>
        &middot; whole product shown
    </p>

    {{-- The manual controls, and only when they are live. Disabling them and
         leaving them visible was the single biggest thing making this screen
         look busy: a wall of grey boxes nobody can use and nobody needs. --}}
    <div class="mt-4" x-show="!preset" x-cloak>
        {{-- Presets before the raw boxes. A number field with "original" in it
             gives no hint that 2048 is the answer Shopify wants, and this screen is
             now the only place the size is set. --}}
        <div class="flex flex-wrap gap-2">
            <button type="button" @click="clear()"
                    :class="!width && !height && !custom ? 'border-gray-800 bg-gray-800 text-white' : 'border-gray-300 bg-white text-gray-600 hover:border-gray-400'"
                    class="rounded-lg border px-3 py-1.5 text-xs font-medium transition-colors">Keep original</button>
            @foreach ([[2048, 'Shopify'], [2000, null], [1200, null], [1000, null], [800, null], [600, null]] as [$px, $note])
                <button type="button" @click="pick({{ $px }}, {{ $px }})"
                        :class="width === {{ $px }} && height === {{ $px }} ? 'border-brand-600 bg-brand-600 text-white' : 'border-gray-300 bg-white text-gray-700 hover:border-brand-400'"
                        class="rounded-lg border px-3 py-1.5 text-xs font-medium transition-colors">
                    {{ $px }} &times; {{ $px }}@if ($note)<span class="ml-1 opacity-70">({{ $note }})</span>@endif
                </button>
            @endforeach
            <button type="button" @click="custom = true"
                    :class="custom ? 'border-gray-800 bg-gray-800 text-white' : 'border-gray-300 bg-white text-gray-600 hover:border-gray-400'"
                    class="rounded-lg border px-3 py-1.5 text-xs font-medium transition-colors">Custom&hellip;</button>
        </div>

        <div x-show="custom || (width && height)" x-cloak class="mt-3 grid gap-3 sm:grid-cols-2">
            <div>
                <label for="w-{{ $uid }}" class="mb-1 block text-xs text-gray-600">Width (px)</label>
                <input id="w-{{ $uid }}" type="number" name="{{ $name('width') }}" min="100" max="5000"
                       x-model.number="width" @input="custom = true" placeholder="original"
                       class="w-full rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:border-brand-500 focus:outline-none">
            </div>
            <div>
                <label for="h-{{ $uid }}" class="mb-1 block text-xs text-gray-600">Height (px)</label>
                <input id="h-{{ $uid }}" type="number" name="{{ $name('height') }}" min="100" max="5000"
                       x-model.number="height" @input="custom = true" placeholder="original"
                       class="w-full rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:border-brand-500 focus:outline-none">
            </div>
        </div>

        {{-- A percentage, not a decimal. "0.08" reads as a setting; "8%" reads as
             an amount of space, which is what the operator is actually choosing. --}}
        <div class="mt-4">
            <div class="mb-1 flex items-baseline justify-between">
                <label for="pad-{{ $uid }}" class="text-xs text-gray-600">Breathing room around the product</label>
                <span class="text-xs font-semibold tabular-nums text-brand-700" x-text="Math.round(padding * 100) + '%'"></span>
            </div>
            <input id="pad-{{ $uid }}" type="range" name="{{ $name('padding') }}"
                   x-model.number="padding" min="0" max="0.4" step="0.01" class="w-full accent-brand-600">
        </div>

        {{-- Per-edge spacing. The slider above is even on all four sides, which is
             right for a garment and wrong for a shoe: a shoe photographed on its
             sole wants headroom above and almost none below, or it floats. --}}
        <details class="mt-3 rounded-lg border border-gray-200 px-3 py-2">
            <summary class="cursor-pointer text-xs font-medium text-gray-600">Different space on each side</summary>
            <p class="mt-2 text-xs text-gray-500">
                Pixels on the finished canvas. Overrides the slider on that side only —
                leave blank to keep the even spacing.
            </p>
            <div class="mt-2 grid gap-3 sm:grid-cols-4">
                @foreach (['top' => 'Top', 'bottom' => 'Bottom', 'left' => 'Left', 'right' => 'Right'] as $edge => $label)
                    <div>
                        <label for="pad-{{ $edge }}-{{ $uid }}" class="mb-1 block text-xs text-gray-600">{{ $label }}</label>
                        <div class="relative">
                            <input id="pad-{{ $edge }}-{{ $uid }}" type="number" min="0" max="2000" step="1"
                                   name="{{ $name('padding_' . $edge) }}" placeholder="&mdash;"
                                   x-model.number="pad{{ ucfirst($edge) }}"
                                   class="w-full rounded-lg border border-gray-300 py-1.5 pl-3 pr-9 text-sm focus:border-brand-500 focus:outline-none">
                            <span class="pointer-events-none absolute inset-y-0 right-2.5 flex items-center text-xs text-gray-400">px</span>
                        </div>
                    </div>
                @endforeach
            </div>
        </details>

        <div class="mt-4 grid gap-3 sm:grid-cols-4">
            <div>
                <label for="halign-{{ $uid }}" class="mb-1 block text-xs text-gray-600">Horizontal</label>
                <select id="halign-{{ $uid }}" name="{{ $name('h_align') }}" x-model="hAlign"
                        class="w-full rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:border-brand-500 focus:outline-none">
                    @foreach (['left' => 'Left', 'center' => 'Centred', 'right' => 'Right'] as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="valign-{{ $uid }}" class="mb-1 block text-xs text-gray-600">Vertical</label>
                <select id="valign-{{ $uid }}" name="{{ $name('v_align') }}" x-model="vAlign"
                        class="w-full rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:border-brand-500 focus:outline-none">
                    @foreach (['top' => 'Top', 'center' => 'Centred', 'bottom' => 'Bottom'] as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="scale-{{ $uid }}" class="mb-1 block text-xs text-gray-600">Scaling</label>
                <select id="scale-{{ $uid }}" name="{{ $name('scaling') }}" x-model="scaling"
                        class="w-full rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:border-brand-500 focus:outline-none">
                    <option value="fit">Fit — show it all</option>
                    <option value="fill">Fill the canvas</option>
                </select>
            </div>
            <div>
                <label for="refbox-{{ $uid }}" class="mb-1 block text-xs text-gray-600">Measured from</label>
                <select id="refbox-{{ $uid }}" name="{{ $name('reference_box') }}" x-model="refBox"
                        class="w-full rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:border-brand-500 focus:outline-none">
                    <option value="subjectBox">The product itself</option>
                    <option value="originalImage">The original frame</option>
                </select>
            </div>
        </div>
    </div>
</div>

<details class="rounded-lg border border-gray-200 px-3 py-2">
    <summary class="cursor-pointer text-xs font-semibold uppercase tracking-wide text-gray-500">
        Finishing
        <span class="ml-1 font-normal normal-case tracking-normal text-gray-400">lighting, shadow, upscaling</span>
    </summary>
    <div class="mt-3 grid gap-3 sm:grid-cols-3">
        <div>
            <label for="light-{{ $uid }}" class="mb-1 block text-xs text-gray-600">Lighting</label>
            <select id="light-{{ $uid }}" name="{{ $name('lighting') }}"
                    class="w-full rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:border-brand-500 focus:outline-none">
                @foreach (\App\Services\PhotoroomService::LIGHTING_MODES as $value => $label)
                    <option value="{{ $value }}" @selected((string) $val('lighting') === (string) $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="beauty-{{ $uid }}" class="mb-1 block text-xs text-gray-600">Beautifier</label>
            <select id="beauty-{{ $uid }}" name="{{ $name('beautify') }}"
                    class="w-full rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:border-brand-500 focus:outline-none">
                @foreach ($beautifyModes as $value => $label)
                    <option value="{{ $value }}" @selected((string) $val('beautify') === (string) $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="shadow-{{ $uid }}" class="mb-1 block text-xs text-gray-600">Shadow</label>
            <select id="shadow-{{ $uid }}" name="{{ $name('shadow') }}"
                    class="w-full rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:border-brand-500 focus:outline-none">
                @foreach (\App\Services\PhotoroomService::SHADOW_MODES as $value => $label)
                    <option value="{{ $value }}" @selected((string) $val('shadow') === (string) $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
    </div>

    <div class="mt-3 flex flex-wrap gap-4">
        @foreach (['upscale' => 'Upscale small photos', 'expand' => 'Extend the background', 'ironing' => 'Smooth creases'] as $field => $label)
            <label class="flex cursor-pointer items-center gap-2 text-xs text-gray-700">
                <input type="checkbox" name="{{ $name($field) }}" value="1" @checked($val($field))
                       class="h-3.5 w-3.5 rounded border-gray-300 text-brand-600 focus:ring-brand-500">
                {{ $label }}
            </label>
        @endforeach
    </div>
</details>

<details class="rounded-lg border border-gray-200 px-3 py-2">
    <summary class="cursor-pointer text-xs font-semibold uppercase tracking-wide text-gray-500">
        Trim before editing
        <span class="ml-1 font-normal normal-case tracking-normal text-gray-400">crop a stand out of shot</span>
    </summary>
    <div class="mt-3 grid gap-3 sm:grid-cols-2">
        @foreach (['trim_top' => 'Off the top', 'trim_bottom' => 'Off the bottom'] as $field => $label)
            <div>
                <label for="{{ $field }}-{{ $uid }}" class="mb-1 block text-xs text-gray-600">{{ $label }}</label>
                <input id="{{ $field }}-{{ $uid }}" type="number" step="0.01" min="0" max="0.45"
                       name="{{ $name($field) }}" value="{{ $val($field) }}" placeholder="0"
                       class="w-full rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:border-brand-500 focus:outline-none">
            </div>
        @endforeach
    </div>
    <p class="mt-1 text-xs text-gray-500">A fraction of the photo, e.g. 0.1 for 10%. Crops a stand out of shot before the cutout runs.</p>
</details>
