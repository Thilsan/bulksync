@extends('layouts.app')
@section('title', 'SEO Audit')
@section('page-title', 'SEO Audit')

@section('content')
<div class="space-y-6">

    {{-- Header --}}
    <div class="bg-white rounded-xl border border-gray-200 p-6 flex items-center justify-between">
        <div>
            <h2 class="font-semibold text-gray-800 text-lg">Store SEO Audit</h2>
            <p class="text-sm text-gray-500 mt-1">
                Scan every product for missing, duplicate or oversized meta titles and descriptions,
                images without alt text, thin copy and untagged products.
            </p>
            <p class="text-sm text-gray-500 mt-1">
                Read-only — nothing is written back to Shopify. Fix what it finds in the
                <a href="{{ route('ai-content.index') }}" class="text-brand-600 hover:text-brand-800 font-medium">AI Content Generator</a>.
            </p>
        </div>
        <form method="POST" action="{{ route('seo-audit.start') }}">
            @csrf
            <button type="submit"
                onclick="return confirm('This will scan all products in your Shopify store. Continue?')"
                class="bg-brand-600 hover:bg-brand-700 text-white px-5 py-2.5 rounded-lg text-sm font-semibold transition-colors flex items-center gap-2">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
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
                    <th class="px-6 py-3 text-center">Products</th>
                    <th class="px-6 py-3 text-center">Clean</th>
                    <th class="px-6 py-3 text-center">With Issues</th>
                    <th class="px-6 py-3 text-center">Avg Score</th>
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
                    <td class="px-6 py-3 text-center font-semibold text-gray-800">{{ number_format($session->scanned_products) }}</td>
                    <td class="px-6 py-3 text-center text-green-600 font-medium">{{ number_format($session->clean_products) }}</td>
                    <td class="px-6 py-3 text-center text-red-500 font-medium">{{ number_format($session->products_with_issues) }}</td>
                    <td class="px-6 py-3 text-center">
                        @if($session->status === 'completed')
                            @php
                                $score = $session->average_score;
                                $tone  = $score >= 80 ? 'green' : ($score >= 50 ? 'amber' : 'red');
                            @endphp
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold bg-{{ $tone }}-50 text-{{ $tone }}-700">
                                {{ $score }}
                            </span>
                        @else
                            <span class="text-gray-300">—</span>
                        @endif
                    </td>
                    <td class="px-6 py-3">
                        <div class="flex items-center gap-3">
                            <a href="{{ route('seo-audit.show', $session) }}" class="text-brand-600 hover:text-brand-800 text-xs font-medium">View</a>
                            @if($session->status === 'completed')
                            <a href="{{ route('seo-audit.download', $session) }}?filter=all" class="text-gray-500 hover:text-gray-700 text-xs font-medium">Download All</a>
                            @endif
                            <form method="POST" action="{{ route('seo-audit.destroy', $session) }}"
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
