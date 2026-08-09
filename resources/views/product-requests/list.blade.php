@extends('layouts.app')

@section('title', 'All Requests')
@section('page-title', 'Product Creation Request')

@section('content')
<div class="space-y-5">

    <div class="flex items-center justify-between">
        <div>
            <nav class="text-xs text-gray-400 mb-1">
                <a href="{{ route('product-requests.index') }}" class="hover:text-gray-600">Product Creation Request</a>
                <span class="mx-1.5">&gt;</span>
                <span class="text-gray-600">All Requests</span>
            </nav>
            <p class="text-sm text-gray-500">{{ number_format($requests->total()) }} request(s) found.</p>
        </div>
        <a href="{{ route('product-requests.index') }}"
           class="inline-flex items-center gap-2 border border-gray-300 text-gray-700 text-sm font-medium px-4 py-2 rounded-lg hover:bg-gray-50 transition-colors">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
            </svg>
            Back to Dashboard
        </a>
    </div>

    {{-- Filters --}}
    <form method="GET" class="bg-white rounded-xl border border-gray-200 shadow-sm px-5 py-4">
        <div class="grid grid-cols-1 md:grid-cols-5 gap-3 items-end">
            <div class="md:col-span-2">
                <label class="block text-xs font-medium text-gray-600 mb-1.5">Search</label>
                <input type="text" name="search" value="{{ request('search') }}" placeholder="Request ID, brand or category"
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

    {{-- Results --}}
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
        @if($requests->isEmpty())
            <p class="px-5 py-16 text-sm text-gray-400 text-center">No requests match these filters.</p>
        @else
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs text-gray-500 border-b border-gray-100 bg-gray-50/60">
                        <th class="px-5 py-2.5 font-medium">Request ID</th>
                        <th class="px-3 py-2.5 font-medium">Brand / Category</th>
                        <th class="px-3 py-2.5 font-medium text-right">SKUs</th>
                        <th class="px-3 py-2.5 font-medium">Mapping</th>
                        <th class="px-3 py-2.5 font-medium">Store Launch</th>
                        <th class="px-3 py-2.5 font-medium">Online Launch</th>
                        <th class="px-3 py-2.5 font-medium">Status</th>
                        <th class="px-3 py-2.5 font-medium">Priority</th>
                        <th class="px-5 py-2.5 font-medium">Assigned To</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-50">
                    @foreach($requests as $item)
                    <tr class="hover:bg-gray-50/70 transition-colors">
                        <td class="px-5 py-3">
                            <a href="{{ route('product-requests.show', $item) }}" class="text-brand-600 hover:text-brand-700 font-medium">{{ $item->reference }}</a>
                            <p class="text-xs text-gray-400">by {{ $item->user?->name ?? '—' }}</p>
                        </td>
                        <td class="px-3 py-3 text-gray-700">{{ $item->brand }} / {{ $item->category }}</td>
                        <td class="px-3 py-3 text-right text-gray-700 tabular-nums">{{ number_format($item->total_skus) }}</td>
                        <td class="px-3 py-3">
                            <div class="flex items-center gap-1.5 text-xs">
                                <span class="inline-flex items-center gap-1" title="Mapped"><span class="w-2 h-2 rounded-full bg-green-500"></span>{{ $item->mapped_skus }}</span>
                                <span class="inline-flex items-center gap-1" title="Pending mapping"><span class="w-2 h-2 rounded-full bg-amber-500"></span>{{ $item->pending_skus }}</span>
                                <span class="inline-flex items-center gap-1" title="Not mapped"><span class="w-2 h-2 rounded-full bg-red-500"></span>{{ $item->not_mapped_skus }}</span>
                            </div>
                        </td>
                        <td class="px-3 py-3 text-gray-600 whitespace-nowrap">{{ $item->store_launch_date?->format('d M Y') ?? '—' }}</td>
                        <td class="px-3 py-3 whitespace-nowrap {{ $item->isOverdue() ? 'text-red-600 font-medium' : 'text-gray-600' }}">
                            {{ $item->online_launch_date?->format('d M Y') ?? '—' }}
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
                        <td class="px-5 py-3 text-gray-600">{{ $item->assignee?->name ?? '—' }}</td>
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

</div>
@endsection
