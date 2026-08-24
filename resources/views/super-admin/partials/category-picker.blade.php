{{--
    A multi-select for categories.

    Thirteen checkboxes in a grid is a wall: it takes the same space whether one
    category is ticked or all of them, and reading back which are on means
    scanning every row. This shows the answer as chips and hides the rest behind
    a search, so a user handling two categories takes two lines.

    @param string $name     form field, submitted as name[]
    @param string $label
    @param array  $options  value => ['label' => ?string, 'note' => ?string, 'strong' => bool, 'extra' => ?string]
    @param array  $selected currently chosen values
    @param string $help
    @param string $noun     what the options are, for the search box and the count
--}}
@php
    $noun = $noun ?? 'categories';

    // A value is not always something to show a person: a category on one website
    // is stored as "3|Watches" and has to read "Watches · PG Website".
    $labels = [];

    foreach ($options as $value => $meta) {
        $labels[$value] = $meta['label'] ?? $value;
    }
@endphp
<div class="pt-3"
     x-data="{
        open: false,
        search: '',
        selected: @js(array_values($selected)),
        all: @js(array_keys($options)),
        labels: @js($labels),
        toggle(value) {
            this.selected = this.selected.includes(value)
                ? this.selected.filter(v => v !== value)
                : [...this.selected, value];
        },
        shows(value) {
            const text = (this.labels[value] ?? value).toLowerCase();

            return !this.search || text.includes(this.search.toLowerCase());
        },
     }"
     @keydown.escape="open = false">

    <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1.5">{{ $label }}</label>

    {{-- What the form actually submits. Rebuilt from the Alpine state so the
         dropdown never has to keep hidden checkboxes in step with the chips. --}}
    <template x-for="value in selected" :key="value">
        <input type="hidden" name="{{ $name }}[]" :value="value">
    </template>

    <div class="relative">
        <button type="button" @click="open = !open"
                class="w-full flex items-center justify-between gap-2 rounded-lg border border-gray-300 bg-white px-3 py-2 text-left focus:outline-none focus:ring-2 focus:ring-brand-500">
            <span class="flex flex-wrap gap-1 min-w-0">
                <span x-show="selected.length === 0" class="text-sm text-gray-400">None selected</span>

                {{-- Every choice named while there are few enough to read; a count
                     once the chips would wrap into a wall of their own. --}}
                <template x-if="selected.length > 0 && selected.length <= 4">
                    <template x-for="value in selected" :key="value">
                        <span class="inline-flex items-center gap-1 bg-brand-50 text-brand-700 text-xs font-medium px-2 py-0.5 rounded-md">
                            <span x-text="labels[value] ?? value"></span>
                            <span @click.stop="toggle(value)" class="text-brand-400 hover:text-brand-700 cursor-pointer" title="Remove">&times;</span>
                        </span>
                    </template>
                </template>

                <template x-if="selected.length > 4">
                    <span class="text-sm text-gray-700"
                          x-text="selected.length === all.length
                                    ? `All ${all.length} {{ $noun }}`
                                    : `${selected.length} {{ $noun }}`"></span>
                </template>
            </span>

            <svg class="w-4 h-4 text-gray-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
            </svg>
        </button>

        <div x-show="open" x-cloak @click.outside="open = false"
             class="absolute z-30 mt-1 w-full rounded-lg border border-gray-200 bg-white shadow-lg">

            <div class="p-2 border-b border-gray-100 flex items-center gap-2">
                <input type="text" x-model="search" placeholder="Search {{ $noun }}"
                       class="flex-1 rounded-lg border border-gray-300 px-2.5 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
                <button type="button" @click="selected = [...all]"
                        class="text-xs font-medium text-brand-600 hover:text-brand-700 shrink-0">All</button>
                <button type="button" @click="selected = []"
                        class="text-xs font-medium text-gray-500 hover:text-gray-700 shrink-0">None</button>
            </div>

            <div class="max-h-64 overflow-y-auto py-1">
                @foreach($options as $category => $meta)
                    <label x-show="shows(@js($category))"
                           class="flex items-start gap-2 px-3 py-1.5 hover:bg-gray-50 cursor-pointer select-none">
                        <input type="checkbox" value="{{ $category }}"
                               :checked="selected.includes(@js($category))"
                               @change="toggle(@js($category))"
                               class="mt-0.5 w-4 h-4 rounded border-gray-300 text-brand-600 focus:ring-brand-500">
                        <span class="text-sm text-gray-700 leading-tight">
                            {{ $meta['label'] ?? $category }}
                            @if($meta['note'] ?? null)
                                <span class="block text-xs {{ ($meta['strong'] ?? false) ? 'text-brand-600 font-medium' : 'text-gray-500' }}">
                                    {{ $meta['note'] }}
                                </span>
                            @endif
                            @if($meta['extra'] ?? null)
                                <span class="block text-xs text-gray-400">{{ $meta['extra'] }}</span>
                            @endif
                        </span>
                    </label>
                @endforeach
            </div>
        </div>
    </div>

    <p class="text-xs text-gray-400 mt-1">{{ $help }}</p>
</div>
