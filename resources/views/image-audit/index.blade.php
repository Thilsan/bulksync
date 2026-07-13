@extends('layouts.app')
@section('title', 'Image Audit')
@section('page-title', 'Image Audit')

@section('content')
<div class="space-y-6">

    {{-- Header --}}
    <div class="bg-white rounded-xl border border-gray-200 p-6 flex items-center justify-between">
        <div>
            <h2 class="font-semibold text-gray-800 text-lg">Store Image Audit</h2>
            <p class="text-sm text-gray-500 mt-1">Scan your entire Shopify store to find which SKUs have images and which don't.</p>
            <p class="text-sm text-gray-500 mt-1">Use SKU GAT207LUG00139 for test purpose.</p>
        </div>
        <form method="POST" action="{{ route('image-audit.start') }}">
            @csrf
            <button type="submit"
                onclick="return confirm('This will scan all products in your Shopify store. Continue?')"
                class="bg-brand-600 hover:bg-brand-700 text-white px-5 py-2.5 rounded-lg text-sm font-semibold transition-colors flex items-center gap-2">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                </svg>
                Start New Audit
            </button>
        </form>
    </div>

    {{-- History --}}
    <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-100">
            <h3 class="font-semibold text-gray-800">Audit History</h3>
        </div>

        @if($sessions->isEmpty())
        <div class="px-6 py-12 text-center text-gray-400 text-sm">
            No audits yet. Click <strong>Start New Audit</strong> to scan your store.
        </div>
        @else
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-xs text-gray-500 uppercase tracking-wide">
                <tr>
                    <th class="px-6 py-3 text-left">Date</th>
                    <th class="px-6 py-3 text-left">Store</th>
                    <th class="px-6 py-3 text-left">Status</th>
                    <th class="px-6 py-3 text-center">Total SKUs</th>
                    <th class="px-6 py-3 text-center">With Images</th>
                    <th class="px-6 py-3 text-center">No Images</th>
                    <th class="px-6 py-3 text-left">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @foreach($sessions as $session)
                <tr class="hover:bg-gray-50 transition-colors">
                    <td class="px-6 py-3 text-gray-600">{{ $session->created_at->format('d M Y, h:i A') }}</td>
                    <td class="px-6 py-3 text-gray-600">{{ $session->store?->name ?? '—' }}</td>
                    <td class="px-6 py-3">
                        @php
                            $colors = ['pending' => 'gray', 'running' => 'brand', 'completed' => 'green', 'failed' => 'red'];
                            $c = $colors[$session->status] ?? 'gray';
                        @endphp
                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-{{ $c }}-100 text-{{ $c }}-700">
                            {{ ucfirst($session->status) }}
                        </span>
                    </td>
                    <td class="px-6 py-3 text-center font-semibold text-gray-800">{{ number_format($session->total_skus) }}</td>
                    <td class="px-6 py-3 text-center text-green-600 font-medium">{{ number_format($session->with_images) }}</td>
                    <td class="px-6 py-3 text-center text-red-500 font-medium">{{ number_format($session->without_images) }}</td>
                    <td class="px-6 py-3">
                        <div class="flex items-center gap-3">
                            <a href="{{ route('image-audit.show', $session) }}" class="text-brand-600 hover:text-brand-800 text-xs font-medium">View</a>
                            @if($session->status === 'completed')
                            <a href="{{ route('image-audit.download', $session) }}" class="text-gray-500 hover:text-gray-700 text-xs font-medium">Download All</a>
                            @endif
                            <form method="POST" action="{{ route('image-audit.destroy', $session) }}"
                                  onsubmit="return confirm('Delete this audit?')">
                                @csrf @method('DELETE')
                                <button type="submit" class="text-red-400 hover:text-red-600 text-xs font-medium">Delete</button>
                            </form>
                        </div>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
        @if($sessions->hasPages())
        <div class="px-6 py-4 border-t border-gray-100">{{ $sessions->links() }}</div>
        @endif
        @endif
    </div>

</div>
@endsection
