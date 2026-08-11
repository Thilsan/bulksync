@extends('layouts.app')

@section('title', 'Photoshoot Room')
@section('page-title', 'Product Creation Request')

@section('content')
@php
    use App\Models\ProductRequest;

    $statuses = ProductRequest::SHOOT_STATUSES;

    // The room is one Alpine component: the calendar, the list and the drawer all
    // read the same selected shoot, so clicking a day and clicking a table row
    // open exactly the same panel.
    $payload = $shoots->mapWithKeys(fn ($r) => [$r->id => [
        'id'        => $r->id,
        'reference' => $r->reference,
        'name'      => $r->displayName(),
        'brand'     => $r->brand,
        'category'  => $r->category,
        'store'     => $r->store?->name,
        'status'    => $r->photoshoot_status,
        'statusLabel' => $r->shootStatusLabel(),
        'at'        => $r->photoshoot_scheduled_at?->format('Y-m-d\TH:i'),
        'atLabel'   => $r->photoshoot_scheduled_at?->format('D d M Y, H:i'),
        'studio'    => $r->photoshoot_studio,
        'notes'     => $r->photoshoot_notes,
        'stage'     => $r->statusLabel(),
        'launch'    => $r->online_launch_date?->format('d M Y, H:i'),
        'skus'      => $r->total_skus,
        'overdue'   => $r->isShootOverdue(),
        'url'       => route('product-requests.show', $r),
        'action'    => route('product-requests.photoshoot-room.update', $r),
    ]]);
@endphp

<div class="space-y-5"
     x-data="{
        selected: null,
        shoots: {{ Illuminate\Support\Js::from($payload) }},
        canEdit: {{ $canEdit ? 'true' : 'false' }},
        open(id) { this.selected = this.shoots[id] ?? null; },
        close() { this.selected = null; },
     }"
     @keydown.escape="close()">

    {{-- Header --}}
    <div class="flex items-start justify-between gap-4">
        <div>
            <nav class="text-xs text-gray-400 mb-1">
                <a href="{{ route('product-requests.index') }}" class="hover:text-gray-600">Product Creation Request</a>
                <span class="mx-1.5">&gt;</span>
                <span class="text-gray-600">Photoshoot Room</span>
            </nav>
            <h2 class="text-lg font-semibold text-gray-800">Photoshoot Room</h2>
            <p class="text-sm text-gray-500">
                Every request that needs a shoot, on one calendar.
                @if($canEdit)
                    You can book and update shoots here.
                @else
                    Read-only — {{ $coordinator?->name ?? 'the photoshoot coordinator' }} keeps the calendar.
                @endif
            </p>
        </div>
        <a href="{{ route('product-requests.index') }}"
           class="inline-flex items-center gap-2 border border-gray-300 text-gray-700 text-sm font-medium px-4 py-2 rounded-lg hover:bg-gray-50 transition-colors shrink-0">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
            </svg>
            Back to Dashboard
        </a>
    </div>

    {{-- Dashboard --}}
    <div class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-7 gap-3">
        @php
            $tiles = [
                ['label' => 'Awaiting a date', 'value' => $stats['pending'],     'key' => ProductRequest::SHOOT_PENDING,     'tone' => $stats['pending'] > 0 ? 'text-amber-600' : 'text-gray-900'],
                ['label' => 'Scheduled',       'value' => $stats['scheduled'],   'key' => ProductRequest::SHOOT_SCHEDULED,   'tone' => 'text-blue-700'],
                ['label' => 'In progress',     'value' => $stats['in_progress'], 'key' => ProductRequest::SHOOT_IN_PROGRESS, 'tone' => 'text-violet-700'],
                ['label' => 'Completed',       'value' => $stats['completed'],   'key' => ProductRequest::SHOOT_COMPLETED,   'tone' => 'text-green-700'],
                ['label' => 'Cancelled',       'value' => $stats['cancelled'],   'key' => ProductRequest::SHOOT_CANCELLED,   'tone' => 'text-gray-500'],
                ['label' => 'This week',       'value' => $stats['this_week'],   'key' => null,                              'tone' => 'text-gray-900'],
                ['label' => 'Past its date',   'value' => $stats['overdue'],     'key' => null,                              'tone' => $stats['overdue'] > 0 ? 'text-red-600' : 'text-gray-900'],
            ];
        @endphp
        @foreach($tiles as $tile)
            <a href="{{ route('product-requests.photoshoot-room', array_filter(['month' => $month->format('Y-m'), 'status' => $tile['key']])) }}"
               class="bg-white rounded-xl border shadow-sm px-4 py-3 transition-colors
                      {{ $tile['key'] && $filter === $tile['key'] ? 'border-brand-400 ring-1 ring-brand-200' : 'border-gray-200 hover:border-gray-300' }}">
                <p class="text-xs text-gray-500 leading-tight">{{ $tile['label'] }}</p>
                <p class="text-2xl font-semibold leading-tight mt-0.5 {{ $tile['tone'] }}">{{ $tile['value'] }}</p>
            </a>
        @endforeach
    </div>

    @if($stats['at_risk'] > 0)
        <div class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3">
            <p class="text-sm text-amber-900">
                <span class="font-semibold">{{ $stats['at_risk'] }}</span>
                {{ $stats['at_risk'] === 1 ? 'shoot has' : 'shoots have' }} a launch date within a week and the images are
                not done yet.
            </p>
        </div>
    @endif

    {{-- Calendar --}}
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
        <div class="px-5 py-3.5 border-b border-gray-100 flex items-center justify-between gap-3">
            <div class="flex items-center gap-2">
                <a href="{{ route('product-requests.photoshoot-room', array_filter(['month' => $month->copy()->subMonth()->format('Y-m'), 'status' => $filter ?: null])) }}"
                   class="w-8 h-8 rounded-lg border border-gray-300 flex items-center justify-center text-gray-500 hover:bg-gray-50" aria-label="Previous month">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
                    </svg>
                </a>
                <h3 class="text-sm font-semibold text-gray-800 w-40 text-center">{{ $month->format('F Y') }}</h3>
                <a href="{{ route('product-requests.photoshoot-room', array_filter(['month' => $month->copy()->addMonth()->format('Y-m'), 'status' => $filter ?: null])) }}"
                   class="w-8 h-8 rounded-lg border border-gray-300 flex items-center justify-center text-gray-500 hover:bg-gray-50" aria-label="Next month">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                    </svg>
                </a>
                @if(!$month->isSameMonth(now()))
                    <a href="{{ route('product-requests.photoshoot-room', array_filter(['status' => $filter ?: null])) }}"
                       class="ml-1 text-xs font-medium text-brand-600 hover:text-brand-700">Today</a>
                @endif
            </div>

            {{-- Legend: the colours are the whole point of a shared calendar. --}}
            <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                @foreach($statuses as $key => $label)
                    <span class="inline-flex items-center gap-1.5 text-xs text-gray-500">
                        <span class="w-2.5 h-2.5 rounded-full {{ ProductRequest::SHOOT_DOT_COLORS[$key] }}"></span>
                        {{ $label }}
                    </span>
                @endforeach
            </div>
        </div>

        <div class="grid grid-cols-7 border-b border-gray-100 bg-gray-50/60">
            @foreach(['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $dayName)
                <div class="px-2 py-2 text-[11px] font-semibold uppercase tracking-wide text-gray-400 text-center">{{ $dayName }}</div>
            @endforeach
        </div>

        <div class="grid grid-cols-7">
            @foreach($weeks as $week)
                @foreach($week as $day)
                    <div class="min-h-[104px] border-b border-r border-gray-100 px-1.5 py-1.5
                                {{ $day['inMonth'] ? '' : 'bg-gray-50/50' }}
                                {{ $day['date']->isToday() ? 'bg-brand-50/40' : '' }}">
                        <p class="text-xs mb-1 px-0.5 flex items-center justify-between">
                            <span class="{{ $day['inMonth'] ? 'text-gray-600' : 'text-gray-300' }}
                                         {{ $day['date']->isToday() ? 'font-bold text-brand-700' : '' }}">
                                {{ $day['date']->format('j') }}
                            </span>
                            @if($day['shoots']->count() > 2)
                                <span class="text-[10px] text-gray-400">{{ $day['shoots']->count() }}</span>
                            @endif
                        </p>

                        <div class="space-y-1">
                            @foreach($day['shoots'] as $shoot)
                                <button type="button" @click="open({{ $shoot->id }})"
                                        class="w-full text-left rounded-md border px-1.5 py-1 hover:brightness-95 transition
                                               {{ $shoot->shootStatusColor() }}">
                                    <span class="block text-[11px] font-semibold leading-tight truncate">
                                        {{ $shoot->photoshoot_scheduled_at->format('H:i') }} {{ $shoot->brand }}
                                    </span>
                                    <span class="block text-[10px] leading-tight truncate opacity-80">
                                        {{ $shoot->reference }}@if($shoot->isShootOverdue()) &middot; late @endif
                                    </span>
                                </button>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            @endforeach
        </div>
    </div>

    {{-- The list --}}
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
        <div class="px-5 py-3.5 border-b border-gray-100 flex items-center justify-between gap-3">
            <h3 class="text-sm font-semibold text-gray-800">
                Photoshoot Requests
                <span class="text-gray-400 font-normal">({{ $shoots->count() }})</span>
                @if($filter)
                    <span class="ml-1 inline-flex items-center gap-1 text-xs font-medium border rounded-md px-1.5 py-0.5 {{ ProductRequest::SHOOT_COLORS[$filter] }}">
                        {{ $statuses[$filter] }}
                    </span>
                @endif
            </h3>
            @if($filter)
                <a href="{{ route('product-requests.photoshoot-room', ['month' => $month->format('Y-m')]) }}"
                   class="text-xs font-medium text-gray-500 hover:text-gray-800">Clear filter</a>
            @endif
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-xs text-gray-500 uppercase tracking-wide">
                    <tr>
                        <th class="px-3 py-2.5 text-left font-semibold">Request</th>
                        <th class="px-3 py-2.5 text-left font-semibold">Brand / Category</th>
                        <th class="px-3 py-2.5 text-left font-semibold">Shoot</th>
                        <th class="px-3 py-2.5 text-left font-semibold">Studio</th>
                        <th class="px-3 py-2.5 text-left font-semibold">Stage</th>
                        <th class="px-3 py-2.5 text-left font-semibold">Launch</th>
                        <th class="px-3 py-2.5 text-right font-semibold"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse($shoots as $shoot)
                        <tr class="hover:bg-gray-50/60">
                            <td class="px-3 py-3">
                                <a href="{{ route('product-requests.show', $shoot) }}" class="font-mono text-xs text-brand-700 hover:underline">{{ $shoot->reference }}</a>
                                <p class="text-xs text-gray-500 truncate max-w-[220px]">{{ $shoot->displayName() }}</p>
                            </td>
                            <td class="px-3 py-3 text-gray-700">{{ $shoot->brand }} <span class="text-gray-400">/</span> {{ $shoot->category }}</td>
                            <td class="px-3 py-3">
                                <span class="inline-flex items-center px-2 py-0.5 rounded-md text-xs font-medium border {{ $shoot->shootStatusColor() }}">
                                    {{ $shoot->shootStatusLabel() }}
                                </span>
                                <p class="text-xs mt-1 {{ $shoot->isShootOverdue() ? 'text-red-600 font-medium' : 'text-gray-500' }}">
                                    {{ $shoot->photoshoot_scheduled_at?->format('d M Y, H:i') ?? 'No date yet' }}
                                </p>
                            </td>
                            <td class="px-3 py-3 text-gray-600">{{ $shoot->photoshoot_studio ?: '—' }}</td>
                            <td class="px-3 py-3 text-gray-600">{{ $shoot->statusLabel() }}</td>
                            <td class="px-3 py-3 text-gray-600">{{ $shoot->online_launch_date?->format('d M Y') ?? '—' }}</td>
                            <td class="px-3 py-3 text-right">
                                <button type="button" @click="open({{ $shoot->id }})"
                                        class="text-xs font-medium text-brand-600 hover:text-brand-700">
                                    {{ $canEdit ? 'Manage' : 'View' }}
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-3 py-10 text-center text-sm text-gray-400">
                                Nothing to shoot{{ $filter ? ' with that status' : ' yet' }}. Requests appear here as soon as
                                someone chooses “We are photographing the products”.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- Drawer: read-only for everyone but the coordinator --}}
    <div x-show="selected" x-cloak class="fixed inset-0 z-50 flex justify-end">
        <div class="absolute inset-0 bg-gray-900/40" @click="close()"></div>

        <div class="relative w-full max-w-lg bg-white h-full shadow-2xl flex flex-col"
             x-transition:enter="transition ease-out duration-200"
             x-transition:enter-start="translate-x-full"
             x-transition:enter-end="translate-x-0">

            <template x-if="selected">
                <div class="flex-1 flex flex-col overflow-hidden">
                    <div class="px-6 py-4 border-b border-gray-200 flex items-start justify-between gap-3 shrink-0">
                        <div class="min-w-0">
                            <p class="text-xs font-mono text-gray-500" x-text="selected.reference"></p>
                            <h3 class="text-base font-semibold text-gray-900 truncate" x-text="selected.name"></h3>
                            <p class="text-xs text-gray-500 mt-0.5">
                                <span x-text="selected.brand"></span> ·
                                <span x-text="selected.category"></span> ·
                                <span x-text="selected.skus"></span> SKUs
                            </p>
                        </div>
                        <button type="button" @click="close()" class="text-gray-400 hover:text-gray-600 shrink-0">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                            </svg>
                        </button>
                    </div>

                    <div class="flex-1 overflow-y-auto px-6 py-5 space-y-5">
                        <div class="grid grid-cols-2 gap-4">
                            @foreach([
                                'Website'      => 'store',
                                'Request stage' => 'stage',
                                'Shoot'        => 'atLabel',
                                'Online launch' => 'launch',
                            ] as $label => $key)
                                <div>
                                    <p class="text-xs text-gray-400">{{ $label }}</p>
                                    <p class="text-sm text-gray-800" x-text="selected.{{ $key }} || '—'"></p>
                                </div>
                            @endforeach
                        </div>

                        <p x-show="selected.overdue" x-cloak class="text-xs text-red-600 font-medium">
                            The shoot date has passed and it is not marked completed.
                        </p>

                        @if($canEdit)
                            <form method="POST" :action="selected.action" class="space-y-4 border-t border-gray-100 pt-5">
                                @csrf
                                @method('PUT')

                                <div>
                                    <label class="block text-xs font-medium text-gray-600 mb-1.5">Status <span class="text-red-500">*</span></label>
                                    <div class="grid grid-cols-2 gap-2">
                                        @foreach($statuses as $key => $label)
                                            <label class="flex items-center gap-2 rounded-lg border px-3 py-2 cursor-pointer text-sm
                                                          {{ ProductRequest::SHOOT_COLORS[$key] }}"
                                                   :class="selected.status === '{{ $key }}' ? 'ring-2 ring-offset-1 ring-brand-400' : ''">
                                                <input type="radio" name="photoshoot_status" value="{{ $key }}"
                                                       :checked="selected.status === '{{ $key }}'" required
                                                       class="text-brand-600 focus:ring-brand-500">
                                                <span class="font-medium">{{ $label }}</span>
                                            </label>
                                        @endforeach
                                    </div>
                                </div>

                                <div>
                                    <label class="block text-xs font-medium text-gray-600 mb-1.5">Date &amp; time</label>
                                    <input type="datetime-local" name="photoshoot_scheduled_at" :value="selected.at"
                                           class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
                                    <p class="text-xs text-gray-400 mt-1">Needed before a shoot can be scheduled or in progress.</p>
                                </div>

                                <div>
                                    <label class="block text-xs font-medium text-gray-600 mb-1.5">Studio / location</label>
                                    <input type="text" name="photoshoot_studio" :value="selected.studio" maxlength="255"
                                           placeholder="e.g. Studio 2, Doha"
                                           class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
                                </div>

                                <div>
                                    <label class="block text-xs font-medium text-gray-600 mb-1.5">Shoot notes</label>
                                    <textarea name="photoshoot_notes" rows="3" maxlength="2000"
                                              placeholder="Samples, models, props, anything the day depends on."
                                              class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 resize-y"
                                              x-text="selected.notes"></textarea>
                                </div>

                                <div class="flex gap-3 pt-1">
                                    <button type="button" @click="close()"
                                            class="flex-1 border border-gray-300 text-gray-700 text-sm font-medium px-4 py-2.5 rounded-lg hover:bg-gray-100">
                                        Cancel
                                    </button>
                                    <button type="submit"
                                            class="flex-1 text-white text-sm font-medium px-4 py-2.5 rounded-lg"
                                            style="background-color:#1d5a74">
                                        Save Shoot
                                    </button>
                                </div>
                            </form>
                        @else
                            <div class="border-t border-gray-100 pt-5 space-y-3">
                                <div>
                                    <p class="text-xs text-gray-400">Status</p>
                                    <p class="text-sm font-medium text-gray-800" x-text="selected.statusLabel"></p>
                                </div>
                                <div>
                                    <p class="text-xs text-gray-400">Studio / location</p>
                                    <p class="text-sm text-gray-800" x-text="selected.studio || '—'"></p>
                                </div>
                                <div>
                                    <p class="text-xs text-gray-400">Shoot notes</p>
                                    <p class="text-sm text-gray-700 whitespace-pre-line" x-text="selected.notes || '—'"></p>
                                </div>
                                <p class="text-xs text-gray-400 pt-1">
                                    Only {{ $coordinator?->name ?? 'the photoshoot coordinator' }} can change a shoot.
                                </p>
                            </div>
                        @endif
                    </div>

                    <div class="px-6 py-3 bg-gray-50 border-t border-gray-200 shrink-0">
                        <a :href="selected.url" class="text-sm font-medium text-brand-600 hover:text-brand-700">
                            Open the full request &rarr;
                        </a>
                    </div>
                </div>
            </template>
        </div>
    </div>
</div>
@endsection
