@extends('layouts.app')

@section('title', 'All Requests')
@section('page-title', 'Product Creation Request')

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

    <div class="flex items-center justify-between">
        <div>
            <nav class="text-xs text-gray-400 mb-1">
                <a href="{{ route('product-requests.index') }}" class="hover:text-gray-600">Product Creation Request</a>
                <span class="mx-1.5">&gt;</span>
                <span class="text-gray-600">All Requests</span>
            </nav>
            <p class="text-sm text-gray-500">{{ number_format($requests->total()) }} request(s) found.</p>
        </div>
        <div class="flex items-center gap-2">
            <button type="button" @click="newRequestOpen = true"
                    class="inline-flex items-center gap-2 text-white text-sm font-medium px-4 py-2 rounded-lg transition-colors"
                    style="background-color:#1d5a74" onmouseover="this.style.backgroundColor='#164659'" onmouseout="this.style.backgroundColor='#1d5a74'">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                </svg>
                New Request
            </button>
            <a href="{{ route('product-requests.index') }}"
               class="inline-flex items-center gap-2 border border-gray-300 text-gray-700 text-sm font-medium px-4 py-2 rounded-lg hover:bg-gray-50 transition-colors">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
                </svg>
                Back to Dashboard
            </a>
        </div>
    </div>

    {{-- Filters --}}
    <form method="GET" class="bg-white rounded-xl border border-gray-200 shadow-sm px-5 py-4">
        <div class="grid grid-cols-1 md:grid-cols-5 gap-3 items-end">
            <div class="md:col-span-2">
                <label class="block text-xs font-medium text-gray-600 mb-1.5">Search</label>
                <input type="text" name="search" value="{{ request('search') }}" placeholder="Request name, brand or category"
                       class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent">
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1.5">Status</label>
                <select name="status" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
                    <option value="">All statuses</option>
                    @foreach(\App\Models\ProductRequest::STATUS_LABELS as $value => $label)
                        <option value="{{ $value }}" @selected(request('status') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1.5">Priority</label>
                <select name="priority" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
                    <option value="">All priorities</option>
                    @foreach(\App\Models\ProductRequest::PRIORITIES as $value => $label)
                        <option value="{{ $value }}" @selected(request('priority') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1.5">Brand</label>
                <select name="brand" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
                    <option value="">All brands</option>
                    @foreach($brands as $brand)
                        <option value="{{ $brand }}" @selected(request('brand') === $brand)>{{ $brand }}</option>
                    @endforeach
                </select>
            </div>
        </div>
        <div class="flex gap-2 mt-3">
            <button type="submit" class="bg-brand-600 hover:bg-brand-700 text-white text-sm font-medium px-4 py-2 rounded-lg transition-colors">Apply Filters</button>
            @if(request()->hasAny(['search', 'status', 'priority', 'brand']))
                <a href="{{ route('product-requests.list') }}" class="text-sm text-gray-500 hover:text-gray-700 px-3 py-2">Clear</a>
            @endif
        </div>
    </form>

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

    {{-- Results --}}
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
        @if($requests->isEmpty())
            <div class="px-5 py-16 text-center">
                <p class="text-sm text-gray-400">
                    {{ request()->hasAny(['search', 'status', 'priority', 'brand']) ? 'No requests match these filters.' : 'No product creation requests yet.' }}
                </p>
                @unless(request()->hasAny(['search', 'status', 'priority', 'brand']))
                    <button type="button" @click="newRequestOpen = true" class="mt-3 text-sm text-brand-600 hover:text-brand-700 font-medium">
                        Create the first request &rarr;
                    </button>
                @endunless
            </div>
        @else
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs text-gray-500 border-b border-gray-100 bg-gray-50/60">
                        <th class="px-3 py-2.5 w-8">
                            <input type="checkbox" x-model="allOnPage" @change="toggleAll()"
                                   title="Select the requests on this page"
                                   class="rounded border-gray-300 text-brand-600 focus:ring-brand-500">
                        </th>
                        <th class="px-5 py-2.5 font-medium">Request</th>
                        <th class="px-3 py-2.5 font-medium">Brand / Category</th>
                        <th class="px-3 py-2.5 font-medium text-right">SKUs</th>
                        <th class="px-3 py-2.5 font-medium">Mapping</th>
                        <th class="px-3 py-2.5 font-medium">Launch</th>
                        <th class="px-3 py-2.5 font-medium">Status</th>
                        <th class="px-3 py-2.5 font-medium">Priority</th>
                        <th class="px-5 py-2.5 font-medium">Waiting On</th>
                        @if(auth()->user()->is_super_admin)
                            <th class="px-3 py-2.5 w-8"><span class="sr-only">Delete</span></th>
                        @endif
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-50">
                    @foreach($requests as $item)
                    <tr class="hover:bg-gray-50/70 transition-colors" :class="picked.includes('{{ $item->id }}') && 'bg-brand-50/50'">
                        <td class="px-3 py-3">
                            <input type="checkbox" data-request-id="{{ $item->id }}" value="{{ $item->id }}" x-model="picked"
                                   class="rounded border-gray-300 text-brand-600 focus:ring-brand-500">
                        </td>
                        <td class="px-5 py-3">
                            <a href="{{ route('product-requests.show', $item) }}"
                               class="text-brand-600 hover:text-brand-700 font-medium">{{ $item->displayName() }}</a>
                            <p class="text-xs text-gray-400">
                                {{ $item->reference }}
                                @if($label = $item->sheetLabel())
                                    &middot; <span title="Request No and Request Date on the tracking sheet">{{ $label }}</span>
                                @endif
                                &middot; by {{ $item->requesterName() }}
                            </p>
                        </td>
                        <td class="px-3 py-3 text-gray-700">
                            {{ $item->brand }} / {{ $item->category }}
                            {{-- The website is what separates two otherwise identical rows: the
                                 sheet's "BS - PG-SN" is one brand raised against three sites. --}}
                            <p class="text-xs font-medium" style="color:#b4540a">{{ $item->store?->name ?? 'no website' }}</p>
                        </td>
                        <td class="px-3 py-3 text-right text-gray-700 tabular-nums">{{ number_format($item->total_skus) }}</td>
                        <td class="px-3 py-3">
                            <div class="flex items-center gap-1.5 text-xs">
                                <span class="inline-flex items-center gap-1" title="Mapped"><span class="w-2 h-2 rounded-full bg-green-500"></span>{{ $item->mapped_skus }}</span>
                                <span class="inline-flex items-center gap-1" title="Pending mapping"><span class="w-2 h-2 rounded-full bg-amber-500"></span>{{ $item->pending_skus }}</span>
                                <span class="inline-flex items-center gap-1" title="Not mapped"><span class="w-2 h-2 rounded-full bg-red-500"></span>{{ $item->not_mapped_skus }}</span>
                            </div>
                            @if($item->hasSkuBalance())
                                {{-- How much of it can actually go live. --}}
                                <p class="text-[11px] text-amber-700 font-medium mt-1">{{ $item->skuCompletionPercent() }}% ready</p>
                            @endif
                        </td>
                        <td class="px-3 py-3 whitespace-nowrap {{ $item->isOverdue() ? 'text-red-600 font-medium' : 'text-gray-600' }}">
                            {{ $item->online_launch_date?->format('d M Y') ?? '—' }}
                            @if($item->online_launch_date)
                                <p class="text-xs {{ $item->isOverdue() ? 'text-red-500' : 'text-gray-400' }}">{{ $item->online_launch_date->format('H:i') }}</p>
                            @endif
                        </td>
                        <td class="px-3 py-3">
                            <span class="inline-flex items-center px-2 py-0.5 rounded-md text-xs font-medium border {{ $item->statusColor() }} whitespace-nowrap">
                                {{ $item->statusLabel() }}
                            </span>
                        </td>
                        <td class="px-3 py-3">
                            <span class="inline-flex items-center px-2 py-0.5 rounded-md text-xs font-medium border {{ $item->priorityColor() }}">
                                {{ $item->priorityLabel() }}
                            </span>
                        </td>
                        {{-- Whose court the ball is in right now, not just who owns the request. --}}
                        @php
                            $g   = $item->currentGuide();
                            $own = $item->ownershipFor(auth()->user());
                        @endphp
                        <td class="px-5 py-3">
                            @if($item->isClosed())
                                <span class="text-gray-400">—</span>
                            @elseif($item->isOnHold())
                                <span class="inline-flex items-center px-2 py-0.5 rounded-md text-xs font-semibold bg-red-600 text-white">On hold</span>
                                <p class="text-xs text-red-600 truncate max-w-[10rem]" title="{{ $item->hold_reason }}">{{ $item->hold_reason }}</p>
                            @elseif($own === 'mine')
                                <span class="inline-flex items-center px-2 py-0.5 rounded-md text-xs font-semibold bg-brand-600 text-white">You</span>
                                <p class="text-xs text-gray-400">{{ $g['role'] }}</p>
                            @elseif($own === 'my_team')
                                <span class="inline-flex items-center px-2 py-0.5 rounded-md text-xs font-medium bg-amber-100 text-amber-800 border border-amber-200">Your team</span>
                                <p class="text-xs text-amber-600">unclaimed</p>
                            @elseif($g['owner'])
                                <p class="text-gray-700">{{ $g['owner']->name }}</p>
                                <p class="text-xs text-gray-400">{{ $g['role'] }}</p>
                            @else
                                <p class="text-gray-500">{{ $g['role'] ?? '—' }}</p>
                                <p class="text-xs text-amber-600">unassigned</p>
                            @endif
                        </td>
                        {{-- Deleting is a super admin's job: everyone else cancels,
                             which keeps the history. --}}
                        @if(auth()->user()->is_super_admin)
                            <td class="px-3 py-3 text-right">
                                <form method="POST" action="{{ route('product-requests.destroy', $item) }}"
                                      onsubmit="return confirm('Delete {{ addslashes($item->reference) }} — {{ addslashes($item->displayName()) }}?\n\nThis removes its {{ $item->total_skus }} SKU(s), activity trail, assignments and attachments for good. Cancel the request instead if you want to keep the record.')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" title="Delete this request"
                                            class="text-gray-300 hover:text-red-600 transition-colors">
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
    </div>

    @if($requests->hasPages())
        <div>{{ $requests->links() }}</div>
    @endif

    @include('product-requests.partials.new-request-form')

</div>
@endsection
