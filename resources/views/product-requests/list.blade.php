@extends('layouts.app')

@section('title', 'All Requests')
@section('page-title', 'Product Creation')

@section('content')
{{-- Same slide-over as the dashboard; re-opens itself if submission failed. --}}
<div class="space-y-5"
     x-data="{
        newRequestOpen: {{ $errors->any() && old('brand') ? 'true' : 'false' }},
        picked: [],
        allOnPage: false,
        toggleAll() {
            this.picked = this.allOnPage
                ? Array.from($root.querySelectorAll('[data-request-id]')).map(el => el.dataset.requestId)
                : [];
        },
     }">

    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h2 class="text-xl font-semibold text-gray-900">All requests</h2>
            <p class="text-sm text-gray-500 mt-0.5">{{ number_format($requests->total()) }} {{ \Illuminate\Support\Str::plural('request', $requests->total()) }}</p>
        </div>
        <button type="button" @click="newRequestOpen = true" data-action="new-request"
                class="inline-flex items-center gap-2 rounded-xl bg-brand-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-brand-700">
            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
            </svg>
            New Request
        </button>
    </div>

    {{-- Bulk action bar — appears only when something is selected. --}}
    <div x-show="picked.length" x-cloak
         class="sticky top-2 z-30 bg-white rounded-xl border-2 border-brand-300 shadow-lg px-5 py-3">
        <form method="POST" action="{{ route('product-requests.bulk') }}"
              x-data="{ action: 'assign' }"
              @submit="if (!confirm(`Apply this to ${picked.length} request(s)?`)) $event.preventDefault()">
            @csrf
            <template x-for="id in picked" :key="id">
                <input type="hidden" name="ids[]" :value="id">
            </template>

            <div class="flex flex-wrap items-end gap-3">
                <div>
                    <p class="text-xs text-gray-500">Selected</p>
                    <p class="text-lg font-semibold text-brand-700 leading-tight"><span x-text="picked.length"></span></p>
                </div>

                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Action</label>
                    <select name="action" x-model="action"
                            class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
                        <option value="assign">Assign someone</option>
                        <option value="priority">Set priority</option>
                        <option value="status">Move stage</option>
                    </select>
                </div>

                <div x-show="action === 'assign'" class="flex items-end gap-2">
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Role</label>
                        <select name="field" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
                            @foreach(\App\Models\ProductRequest::assignableRoles() as $field => $label)
                                <option value="{{ $field }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Person</label>
                        <select name="user_id" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
                            @foreach($teamPool as $member)
                                <option value="{{ $member->id }}">{{ $member->name }}@if($member->pcr_role) — {{ $member->pcrRoleLabel() }}@endif</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div x-show="action === 'priority'" x-cloak>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Priority</label>
                    <select name="priority" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
                        @foreach(\App\Models\ProductRequest::PRIORITIES as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                <div x-show="action === 'status'" x-cloak class="flex items-end gap-2">
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Move to</label>
                        <select name="to_status" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
                            @foreach(\App\Models\ProductRequest::STATUS_LABELS as $value => $label)
                                @continue($value === \App\Models\ProductRequest::CANCELLED)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Remark</label>
                        <input type="text" name="remarks" maxlength="255" placeholder="Optional"
                               class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
                    </div>
                </div>

                <div class="flex-1"></div>

                <button type="button" @click="picked = []; allOnPage = false"
                        class="text-xs text-gray-500 hover:text-gray-700 px-3 py-2">Clear</button>
                <button type="submit"
                        class="bg-brand-600 hover:bg-brand-700 text-white text-sm font-medium px-4 py-2 rounded-lg transition-colors">
                    Apply
                </button>
            </div>

            <p x-show="action === 'status'" x-cloak class="text-xs text-gray-400 mt-2">
                Requests where that move isn't allowed from their current stage are skipped, and counted in the result.
            </p>
        </form>
    </div>

    {{-- Results: status tabs, one toolbar, the table — all in one card --}}
    @php
        $filtered = request()->hasAny(['search', 'status', 'priority', 'brand']);
        $statusTabs = [
            ''                               => 'All',
            'pending'                        => 'Pending',
            'in_progress'                    => 'In progress',
            \App\Models\ProductRequest::WAITING_MAPPING      => 'Waiting for mapping',
            \App\Models\ProductRequest::PHOTOSHOOT_SCHEDULED => 'Photoshoot',
            'on_hold'                        => 'On hold',
            \App\Models\ProductRequest::PUBLISHED            => 'Published',
        ];
        $palette = ['bg-sky-100 text-sky-700', 'bg-violet-100 text-violet-700', 'bg-amber-100 text-amber-800',
                    'bg-emerald-100 text-emerald-700', 'bg-rose-100 text-rose-700', 'bg-indigo-100 text-indigo-700'];
        $tone = fn (?string $text) => $palette[abs(crc32((string) $text)) % count($palette)];
        $initials = fn (?string $text) => strtoupper(mb_substr(preg_replace('/[^A-Za-z0-9]/', '', (string) $text) ?: '?', 0, 2));
    @endphp
    <div class="bg-white rounded-2xl border border-gray-200/80 shadow-sm overflow-hidden">

        {{-- Status as tabs: one click, no dropdown to open --}}
        <div class="px-3 border-b border-gray-100 flex gap-1 overflow-x-auto">
            @foreach($statusTabs as $value => $label)
                @php $active = (string) request('status', '') === (string) $value; @endphp
                <a href="{{ route('product-requests.list', array_filter(['status' => $value ?: null] + request()->only(['search', 'priority', 'brand']))) }}"
                   class="px-3 py-3 text-sm font-medium whitespace-nowrap border-b-2 transition-colors
                          {{ $active ? 'border-brand-600 text-gray-900' : 'border-transparent text-gray-500 hover:text-gray-800' }}">{{ $label }}</a>
            @endforeach
        </div>

        {{-- Search and the two remaining filters; Enter searches, the selects apply themselves --}}
        <form method="GET" class="px-4 py-3 flex flex-wrap items-center gap-2 border-b border-gray-100 bg-gray-50/50">
            @if(request('status'))
                <input type="hidden" name="status" value="{{ request('status') }}">
            @endif
            <div class="relative min-w-[14rem] flex-1">
                <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400"
                     fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M17 11a6 6 0 11-12 0 6 6 0 0112 0z"/>
                </svg>
                <input type="text" name="search" value="{{ request('search') }}"
                       placeholder="Search by brand, name, reference or category"
                       class="w-full rounded-xl border border-gray-200 bg-white py-2 pl-9 pr-3 text-sm transition focus:border-transparent focus:outline-none focus:ring-2 focus:ring-brand-500">
            </div>

            <select name="priority" @change="$el.form.submit()"
                    class="rounded-xl border border-gray-200 bg-white px-3 py-2 text-sm transition focus:outline-none focus:ring-2 focus:ring-brand-500 {{ request('priority') ? 'text-gray-900 font-medium' : 'text-gray-500' }}">
                <option value="">Any priority</option>
                @foreach(\App\Models\ProductRequest::PRIORITIES as $value => $label)
                    <option value="{{ $value }}" @selected(request('priority') === $value)>{{ $label }}</option>
                @endforeach
            </select>

            {{-- Brand: a searchable picker. Hundreds of brands do not fit a plain dropdown. --}}
            <div class="relative w-60"
                 x-data="{
                    brands: {{ Illuminate\Support\Js::from($brands->values()) }},
                    value: {{ Illuminate\Support\Js::from((string) request('brand', '')) }},
                    open: false,
                    q: '',
                    active: 0,
                    get matches() {
                        const q = this.q.trim().toLowerCase();
                        const list = q ? this.brands.filter(b => b.toLowerCase().includes(q)) : this.brands;
                        return list.slice(0, 200);
                    },
                    show() { this.open = true; this.q = ''; this.active = 0; this.$nextTick(() => this.$refs.q.focus()); },
                    pick(b) {
                        this.value = b; this.open = false;
                        this.$nextTick(() => this.$refs.input.form.submit());
                    },
                    move(by) {
                        const n = this.matches.length;
                        if (!n) return;
                        this.active = (this.active + by + n) % n;
                        this.$nextTick(() => this.$refs.list.querySelector('[data-active]')?.scrollIntoView({ block: 'nearest' }));
                    },
                 }"
                 @click.outside="open = false" @keydown.escape="open = false">
                <input type="hidden" name="brand" x-ref="input" :value="value">

                <button type="button" @click="open ? open = false : show()"
                        class="w-full flex items-center justify-between gap-2 rounded-xl border border-gray-200 bg-white px-3 py-2 text-sm text-left transition focus:outline-none focus:ring-2 focus:ring-brand-500">
                    <span class="truncate" :class="value ? 'text-gray-900 font-medium' : 'text-gray-500'" x-text="value || 'Any brand'">{{ request('brand') ?: 'Any brand' }}</span>
                    <svg class="w-4 h-4 text-gray-400 shrink-0 transition-transform" :class="open && 'rotate-180'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                </button>

                <div x-show="open" x-cloak x-transition.origin.top
                     class="absolute right-0 z-40 mt-1.5 w-72 rounded-xl border border-gray-200 bg-white shadow-xl overflow-hidden">
                    <div class="p-2 border-b border-gray-100">
                        <div class="relative">
                            <svg class="pointer-events-none absolute left-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M17 11a6 6 0 11-12 0 6 6 0 0112 0z"/></svg>
                            <input type="text" x-ref="q" x-model="q" @input="active = 0" placeholder="Search brands…"
                                   @keydown.arrow-down.prevent="move(1)" @keydown.arrow-up.prevent="move(-1)"
                                   @keydown.enter.prevent="matches[active] !== undefined && pick(matches[active])"
                                   class="w-full rounded-lg border border-gray-200 bg-gray-50 py-1.5 pl-8 pr-2 text-sm focus:bg-white focus:outline-none focus:ring-2 focus:ring-brand-500">
                        </div>
                    </div>

                    <ul x-ref="list" class="max-h-72 overflow-y-auto py-1 text-sm">
                        <li x-show="!q">
                            <button type="button" @click="pick('')"
                                    class="w-full flex items-center justify-between px-3 py-2 text-left text-gray-500 hover:bg-gray-50">
                                Any brand
                                <svg x-show="!value" class="w-4 h-4 text-brand-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                            </button>
                        </li>
                        <template x-for="(b, i) in matches" :key="b">
                            <li>
                                <button type="button" @click="pick(b)" @mouseenter="active = i"
                                        :data-active="i === active ? '' : null"
                                        class="w-full flex items-center justify-between gap-2 px-3 py-2 text-left"
                                        :class="i === active ? 'bg-gray-100 text-gray-900' : 'text-gray-700'">
                                    <span class="truncate" x-text="b"></span>
                                    <svg x-show="b === value" class="w-4 h-4 text-brand-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                                </button>
                            </li>
                        </template>
                        <li x-show="!matches.length" class="px-3 py-6 text-center text-gray-400">No brand matches “<span x-text="q"></span>”.</li>
                    </ul>
                </div>
            </div>

            <button type="submit" class="sr-only">Search</button>

            @if($filtered)
                <a href="{{ route('product-requests.list') }}"
                   class="inline-flex items-center gap-1 rounded-xl px-3 py-2 text-sm text-gray-500 transition-colors hover:bg-gray-100 hover:text-gray-800">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                    Clear
                </a>
            @endif
        </form>

        @if($requests->isEmpty())
            <div class="px-5 py-16 text-center">
                <div class="mx-auto w-12 h-12 rounded-2xl bg-gray-50 text-gray-300 flex items-center justify-center">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
                </div>
                <p class="mt-3 text-sm text-gray-500">{{ $filtered ? 'No requests match these filters.' : 'No product creation requests yet.' }}</p>
                @unless($filtered)
                    <button type="button" @click="newRequestOpen = true" class="mt-2 text-sm text-brand-600 hover:text-brand-700 font-medium">
                        Create the first request &rarr;
                    </button>
                @endunless
            </div>
        @else
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-[11px] uppercase tracking-wider text-gray-400 border-b border-gray-100">
                        <th class="pl-4 pr-2 py-2.5 w-8">
                            <input type="checkbox" x-model="allOnPage" @change="toggleAll()"
                                   title="Select the requests on this page"
                                   class="rounded border-gray-300 text-brand-600 focus:ring-brand-500">
                        </th>
                        <th class="px-3 py-2.5 font-medium">Request</th>
                        <th class="px-3 py-2.5 font-medium">Products</th>
                        <th class="px-3 py-2.5 font-medium">Website go-live</th>
                        <th class="px-3 py-2.5 font-medium">Status</th>
                        <th class="px-3 py-2.5 font-medium">Priority</th>
                        <th class="px-3 py-2.5 font-medium">With</th>
                        @if(auth()->user()->is_super_admin)
                            <th class="px-3 py-2.5 w-8"><span class="sr-only">Delete</span></th>
                        @endif
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach($requests as $item)
                    @php
                        $url  = route('product-requests.show', $item);
                        $days = $item->daysToOnlineLaunch();
                        $g    = $item->currentGuide();
                        $own  = $item->ownershipFor(auth()->user());
                        $mapped = $item->store?->requires_sku_mapping && $item->total_skus > 0;
                        $pct  = $item->total_skus > 0 ? (int) round(100 * $item->mapped_skus / $item->total_skus) : 0;
                    @endphp
                    <tr class="group cursor-pointer hover:bg-gray-50/80 transition-colors"
                        :class="picked.includes('{{ $item->id }}') && 'bg-brand-50/60'"
                        @click="if (!$event.target.closest('input, button, a, form')) window.location = '{{ $url }}'">
                        <td class="pl-4 pr-2 py-3.5">
                            <input type="checkbox" data-request-id="{{ $item->id }}" value="{{ $item->id }}" x-model="picked"
                                   class="rounded border-gray-300 text-brand-600 focus:ring-brand-500">
                        </td>

                        {{-- Request: brand badge, name, reference --}}
                        <td class="px-3 py-3.5">
                            <div class="flex items-center gap-3 min-w-0">
                                <span class="w-9 h-9 rounded-xl text-xs font-bold flex items-center justify-center shrink-0 {{ $tone($item->brand) }}">{{ $initials($item->brand) }}</span>
                                <div class="min-w-0">
                                    <a href="{{ $url }}" class="font-medium text-gray-900 hover:text-brand-700 truncate block max-w-[22rem]">{{ $item->displayName() }}</a>
                                    <p class="text-xs text-gray-400 mt-0.5 truncate">{{ $item->reference }} &middot; {{ $item->store?->name ?? 'No website' }}</p>
                                </div>
                            </div>
                        </td>

                        {{-- Products: the count, and how many are ready where mapping applies --}}
                        <td class="px-3 py-3.5 whitespace-nowrap">
                            <p class="text-gray-900 tabular-nums font-medium">{{ number_format($item->total_skus) }} <span class="font-normal text-gray-400">SKUs</span></p>
                            @if($mapped)
                                <div class="mt-1 flex items-center gap-2">
                                    <div class="w-16 h-1.5 rounded-full bg-gray-100 overflow-hidden">
                                        <div class="h-full rounded-full {{ $item->hasSkuBalance() ? 'bg-amber-400' : 'bg-green-500' }}" style="width: {{ $pct }}%"></div>
                                    </div>
                                    <span class="text-[11px] {{ $item->hasSkuBalance() ? 'text-amber-700' : 'text-green-700' }}">{{ $item->hasSkuBalance() ? number_format($item->mapped_skus) . ' ready' : 'All ready' }}</span>
                                </div>
                            @endif
                        </td>

                        {{-- Go-live, with how far off it is --}}
                        <td class="px-3 py-3.5 whitespace-nowrap">
                            @if($item->online_launch_date)
                                <p class="text-gray-900">{{ $item->online_launch_date->format('d M Y') }}</p>
                                @unless($item->isClosed())
                                    <p class="text-[11px] mt-0.5 {{ $days < 0 ? 'text-red-600 font-medium' : ($days <= 7 ? 'text-amber-700' : 'text-gray-400') }}">
                                        {{ $days < 0 ? 'Overdue ' . abs($days) . 'd' : ($days === 0 ? 'Today' : 'In ' . $days . ' ' . \Illuminate\Support\Str::plural('day', $days)) }}
                                    </p>
                                @endunless
                            @else
                                <span class="text-gray-300">—</span>
                            @endif
                        </td>

                        <td class="px-3 py-3.5">
                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium border {{ $item->statusColor() }} whitespace-nowrap">
                                <span class="w-1.5 h-1.5 rounded-full bg-current opacity-70"></span>
                                {{ $item->statusLabel() }}
                            </span>
                        </td>

                        <td class="px-3 py-3.5">
                            <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium border {{ $item->priorityColor() }}">{{ $item->priorityLabel() }}</span>
                        </td>

                        {{-- Whose court the ball is in right now, not just who owns the request. --}}
                        <td class="px-3 py-3.5 whitespace-nowrap">
                            @if($item->isClosed())
                                <span class="text-gray-300">—</span>
                            @elseif($item->isOnHold())
                                <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium bg-red-50 text-red-700 border border-red-200"
                                      title="{{ $item->hold_reason }}">On hold</span>
                            @elseif($own === 'mine')
                                <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold bg-brand-600 text-white" title="{{ $g['role'] }}">You</span>
                            @elseif($own === 'my_team')
                                <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium bg-amber-50 text-amber-800 border border-amber-200" title="{{ $g['role'] }}">Your team</span>
                            @elseif($g['owner'])
                                <span class="inline-flex items-center gap-2" title="{{ $g['role'] }}">
                                    <span class="w-7 h-7 rounded-full text-[11px] font-semibold flex items-center justify-center {{ $tone($g['owner']->name) }}">{{ $initials($g['owner']->name) }}</span>
                                    <span class="text-gray-700">{{ $g['owner']->name }}</span>
                                </span>
                            @else
                                <span class="text-amber-700" title="{{ $g['role'] }}">Not set</span>
                            @endif
                        </td>

                        {{-- Deleting is a super admin's job: everyone else cancels,
                             which keeps the history. Quiet until you hover the row. --}}
                        @if(auth()->user()->is_super_admin)
                            <td class="px-3 py-3.5 text-right">
                                <form method="POST" action="{{ route('product-requests.destroy', $item) }}"
                                      onsubmit="return confirm('Delete {{ addslashes($item->reference) }} — {{ addslashes($item->displayName()) }}?\n\nThis removes its {{ $item->total_skus }} SKU(s), activity trail, assignments and attachments for good. Cancel the request instead if you want to keep the record.')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" title="Delete this request"
                                            class="opacity-0 group-hover:opacity-100 focus:opacity-100 text-gray-300 hover:text-red-600 transition">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                        </svg>
                                    </button>
                                </form>
                            </td>
                        @endif
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif

        @if($requests->hasPages())
            <div class="px-4 py-3 border-t border-gray-100">{{ $requests->links() }}</div>
        @endif
    </div>

    @include('product-requests.partials.new-request-form')

</div>
@endsection
