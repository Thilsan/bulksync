{{--
    A styled dropdown — the native <select> menu can't be styled.
    Expects: $name (form field), $model (parent Alpine property it writes to),
    $options (value => label), $placeholder (shown while nothing is picked).
--}}
<div class="relative"
     x-data="{
         open: false,
         opts: {{ Illuminate\Support\Js::from(collect($options)->map(fn ($label, $value) => ['value' => (string) $value, 'label' => $label])->values()) }},
         get current() { return this.opts.find(o => o.value === String({{ $model }} ?? '')) },
         pick(o) { {{ $model }} = o.value; this.open = false; this.$refs.btn.focus(); },
     }"
     @click.outside="open = false"
     @keydown.escape="if (open) { open = false; $event.stopPropagation(); }">

    {{-- Carries the value and the browser's "required" check. --}}
    <input type="text" name="{{ $name }}" :value="{{ $model }}" required tabindex="-1" aria-hidden="true"
           class="absolute inset-x-0 bottom-0 h-px opacity-0 pointer-events-none">

    <button type="button" x-ref="btn" @click="open = !open"
            class="w-full flex items-center justify-between gap-2 rounded-lg border bg-white px-3 py-2 text-sm text-left transition focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent"
            :class="open ? 'border-transparent ring-2 ring-brand-500' : 'border-gray-300 hover:border-gray-400'">
        <span class="truncate" :class="current ? 'text-gray-900' : 'text-gray-400'"
              x-text="current ? current.label : {{ Illuminate\Support\Js::from($placeholder) }}"></span>
        <svg class="w-4 h-4 shrink-0 text-gray-400 transition-transform" :class="open && 'rotate-180'"
             fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
        </svg>
    </button>

    <div x-show="open" x-cloak
         x-transition:enter="transition ease-out duration-100" x-transition:enter-start="opacity-0 -translate-y-1" x-transition:enter-end="opacity-100 translate-y-0"
         class="absolute z-30 mt-1.5 w-full max-h-64 overflow-y-auto rounded-xl border border-gray-200 bg-white p-1 shadow-lg">
        <template x-for="o in opts" :key="o.value">
            <button type="button" @click="pick(o)"
                    class="w-full flex items-center justify-between gap-2 rounded-lg px-3 py-2 text-sm text-left transition-colors"
                    :class="current && current.value === o.value ? 'bg-brand-50 text-brand-700 font-medium' : 'text-gray-700 hover:bg-gray-100'">
                <span class="truncate" x-text="o.label"></span>
                <svg x-show="current && current.value === o.value" class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                </svg>
            </button>
        </template>
    </div>
</div>
