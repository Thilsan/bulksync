{{--
    A styled date + time picker — the browser's own calendar can't be styled.
    Expects: $name (form field), $model (parent Alpine property holding
    "YYYY-MM-DDTHH:MM", the datetime-local format), $placeholder.
    Optional: $dateOnly (hide the time, for a day rather than a slot),
    $required (default true).
--}}
@php
    $dateOnly = $dateOnly ?? false;
    $required = $required ?? true;
@endphp
<div class="relative"
     x-data="{
         open: false,
         view: null,
         init() { this.resetView(); },
         pad(n) { return String(n).padStart(2, '0'); },
         parse() {
             const v = {{ $model }};
             if (!v) return null;
             const [d, t] = v.split('T');
             const [y, m, day] = d.split('-').map(Number);
             const [h, mi] = (t || '10:00').split(':').map(Number);
             return new Date(y, m - 1, day, h, mi);
         },
         write(d) {
             {{ $model }} = `${d.getFullYear()}-${this.pad(d.getMonth() + 1)}-${this.pad(d.getDate())}T${this.pad(d.getHours())}:${this.pad(d.getMinutes())}`;
         },
         resetView() {
             const d = this.parse() || new Date();
             this.view = new Date(d.getFullYear(), d.getMonth(), 1);
         },
         shiftMonth(by) { this.view = new Date(this.view.getFullYear(), this.view.getMonth() + by, 1); },
         get monthLabel() { return this.view.toLocaleDateString('en-GB', { month: 'long', year: 'numeric' }); },
         get days() {
             const y = this.view.getFullYear(), m = this.view.getMonth();
             const lead = new Date(y, m, 1).getDay();
             const total = Math.ceil((lead + new Date(y, m + 1, 0).getDate()) / 7) * 7;
             return Array.from({ length: total }, (_, i) => new Date(y, m, 1 - lead + i));
         },
         same(a, b) { return !!a && !!b && a.toDateString() === b.toDateString(); },
         pickDay(d) {
             const cur = this.parse();
             const next = new Date(d);
             next.setHours(cur ? cur.getHours() : 10, cur ? cur.getMinutes() : 0);
             this.write(next);
         },
         step(unit, by) {
             const cur = this.parse();
             if (!cur) return;
             if (unit === 'h') cur.setHours((cur.getHours() + by + 24) % 24);
             else cur.setMinutes(((Math.floor(cur.getMinutes() / 15) + by) * 15 + 60) % 60);
             this.write(cur);
         },
         today() { const n = new Date(); this.pickDay(n); this.resetView(); },
         get label() {
             const d = this.parse();
             if (!d) return '';
             return d.toLocaleDateString('en-GB', { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' })
                 + ({{ $dateOnly ? 'true' : 'false' }} ? '' : ' · ' + this.pad(d.getHours()) + ':' + this.pad(d.getMinutes()));
         },
     }"
     @click.outside="open = false"
     @keydown.escape="if (open) { open = false; $event.stopPropagation(); }">

    {{-- Carries the value and the browser's "required" check. --}}
    <input type="text" name="{{ $name }}" :value="{{ $model }}" {{ $required ? 'required' : '' }} tabindex="-1" aria-hidden="true"
           class="absolute inset-x-0 bottom-0 h-px opacity-0 pointer-events-none">

    <button type="button" x-ref="btn" @click="open = !open; if (open) resetView()"
            class="w-full flex items-center justify-between gap-2 rounded-lg border bg-white px-3 py-2 text-sm text-left transition focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent"
            :class="open ? 'border-transparent ring-2 ring-brand-500' : 'border-gray-300 hover:border-gray-400'">
        <span class="truncate" :class="label ? 'text-gray-900' : 'text-gray-400'"
              x-text="label || {{ Illuminate\Support\Js::from($placeholder) }}"></span>
        <svg class="w-4 h-4 shrink-0 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
        </svg>
    </button>

    <div x-show="open" x-cloak
         x-transition:enter="transition ease-out duration-100" x-transition:enter-start="opacity-0 -translate-y-1" x-transition:enter-end="opacity-100 translate-y-0"
         class="absolute right-0 z-30 mt-1.5 w-72 rounded-xl border border-gray-200 bg-white p-3 shadow-lg">

        {{-- Month --}}
        <div class="flex items-center justify-between mb-2">
            <button type="button" @click="shiftMonth(-1)" aria-label="Previous month"
                    class="grid h-8 w-8 place-items-center rounded-lg text-gray-500 hover:bg-gray-100 hover:text-gray-900">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
            </button>
            <p class="text-sm font-semibold text-gray-900" x-text="monthLabel"></p>
            <button type="button" @click="shiftMonth(1)" aria-label="Next month"
                    class="grid h-8 w-8 place-items-center rounded-lg text-gray-500 hover:bg-gray-100 hover:text-gray-900">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
            </button>
        </div>

        {{-- Days --}}
        <div class="grid grid-cols-7 text-center text-[11px] font-medium text-gray-400 mb-1">
            <template x-for="w in ['Su','Mo','Tu','We','Th','Fr','Sa']" :key="w"><span class="py-1" x-text="w"></span></template>
        </div>
        <div class="grid grid-cols-7 gap-0.5">
            <template x-for="d in days" :key="d.toISOString()">
                <button type="button" @click="pickDay(d)"
                        class="h-9 rounded-lg text-sm tabular-nums transition-colors"
                        :class="same(d, parse())
                            ? 'bg-brand-600 text-white font-semibold'
                            : (d.getMonth() !== view.getMonth()
                                ? 'text-gray-300 hover:bg-gray-50'
                                : (same(d, new Date()) ? 'text-brand-700 font-semibold ring-1 ring-inset ring-brand-200 hover:bg-brand-50' : 'text-gray-700 hover:bg-gray-100'))"
                        x-text="d.getDate()"></button>
            </template>
        </div>

        {{-- Time --}}
        @unless($dateOnly)
        <div class="mt-3 pt-3 border-t border-gray-100 flex items-center justify-between" :class="!parse() && 'opacity-40 pointer-events-none'">
            <span class="text-sm text-gray-500">Time</span>
            <div class="flex items-center gap-1.5">
                @foreach(['h' => 'getHours', 'm' => 'getMinutes'] as $unit => $getter)
                    @if($unit === 'm')<span class="text-gray-400 font-medium">:</span>@endif
                    <div class="flex items-center rounded-lg bg-gray-100 p-0.5">
                        <button type="button" @click="step('{{ $unit }}', -1)" aria-label="Earlier"
                                class="grid h-7 w-6 place-items-center rounded-md text-gray-500 hover:bg-white hover:text-gray-900">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M20 12H4"/></svg>
                        </button>
                        <span class="w-7 text-center text-sm font-medium text-gray-900 tabular-nums"
                              x-text="parse() ? pad(parse().{{ $getter }}()) : '--'"></span>
                        <button type="button" @click="step('{{ $unit }}', 1)" aria-label="Later"
                                class="grid h-7 w-6 place-items-center rounded-md text-gray-500 hover:bg-white hover:text-gray-900">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
                        </button>
                    </div>
                @endforeach
            </div>
        </div>
        @endunless

        <div class="mt-3 flex items-center justify-between">
            <button type="button" @click="today()" class="text-sm font-medium text-brand-600 hover:text-brand-700">Today</button>
            <button type="button" @click="open = false; $refs.btn.focus()"
                    class="rounded-lg bg-brand-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-brand-700">Done</button>
        </div>
    </div>
</div>
