@extends('layouts.app')

@section('title', 'AI Content')
@section('page-title', 'AI Content Generator')

@section('content')
<div class="max-w-4xl mx-auto space-y-6">

    {{-- New session form --}}
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-100">
            <h2 class="text-base font-semibold text-gray-800">Generate AI Content</h2>
            <p class="text-sm text-gray-500 mt-0.5">AI will analyze product images and generate descriptions, meta titles and meta descriptions.</p>
        </div>

        <form method="POST" action="{{ route('ai-content.store') }}" enctype="multipart/form-data" x-data="{ inputType: 'sku_list' }">
            @csrf
            <div class="px-6 py-5 space-y-5">

                {{-- Input type selector --}}
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Input Type</label>
                    <div class="flex gap-3">
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="radio" name="input_type" value="sku_list" x-model="inputType"
                                class="text-brand-600 focus:ring-brand-500">
                            <span class="text-sm text-gray-700">SKU List</span>
                        </label>
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="radio" name="input_type" value="csv_upload" x-model="inputType"
                                class="text-brand-600 focus:ring-brand-500">
                            <span class="text-sm text-gray-700">CSV Upload</span>
                        </label>
                    </div>
                </div>

                {{-- SKU List input --}}
                <div x-show="inputType === 'sku_list'">
                    <label class="block text-sm font-medium text-gray-700 mb-1">
                        SKU List <span class="text-gray-400 font-normal">(one per line)</span>
                    </label>
                    <textarea name="sku_raw" rows="8"
                        placeholder="SKU001&#10;SKU002&#10;SKU003"
                        class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent resize-y">{{ old('sku_raw') }}</textarea>
                    <p class="text-xs text-gray-400 mt-1">System will fetch product images from Shopify for each SKU.</p>
                </div>

                {{-- CSV Upload input --}}
                <div x-show="inputType === 'csv_upload'" x-cloak>
                    <label class="block text-sm font-medium text-gray-700 mb-1">CSV File</label>
                    <input type="file" name="csv_file" accept=".csv,.txt"
                        class="w-full text-sm text-gray-600 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-medium file:bg-brand-50 file:text-brand-700 hover:file:bg-brand-100 cursor-pointer">
                    <p class="text-xs text-gray-400 mt-1">
                        First column should contain SKUs (header row optional, e.g. <code class="bg-gray-100 px-1 rounded">SKU</code> or <code class="bg-gray-100 px-1 rounded">Variant SKU</code>).
                    </p>
                </div>

            </div>

            <div class="px-6 py-4 bg-gray-50 border-t border-gray-100 flex justify-end">
                <button type="submit"
                    class="inline-flex items-center gap-2 bg-brand-600 hover:bg-brand-700 text-white text-sm font-medium px-5 py-2 rounded-lg transition-colors">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M13 10V3L4 14h7v7l9-11h-7z"/>
                    </svg>
                    Generate AI Content
                </button>
            </div>
        </form>
    </div>

    {{-- Session history --}}
    @if($sessions->isNotEmpty())
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-100">
            <h2 class="text-base font-semibold text-gray-800">Previous Sessions</h2>
        </div>
        <table class="w-full text-sm">
            <thead class="bg-gray-50 border-b border-gray-100">
                <tr>
                    <th class="text-left px-6 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wide">Date</th>
                    <th class="text-left px-6 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wide">Type</th>
                    <th class="text-left px-6 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wide">Store</th>
                    <th class="text-left px-6 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wide">Items</th>
                    <th class="text-left px-6 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wide">Status</th>
                    <th class="px-6 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @foreach($sessions as $session)
                <tr class="hover:bg-gray-50">
                    <td class="px-6 py-3 text-gray-600">{{ $session->created_at->format('d M Y H:i') }}</td>
                    <td class="px-6 py-3 text-gray-600 capitalize">{{ str_replace('_', ' ', $session->input_type) }}</td>
                    <td class="px-6 py-3 text-gray-600">{{ $session->store?->name ?? '—' }}</td>
                    <td class="px-6 py-3 text-gray-600">{{ $session->processed_items }} / {{ $session->total_items }}</td>
                    <td class="px-6 py-3">
                        @php
                            $colors = [
                                'pending'    => 'bg-gray-100 text-gray-600',
                                'processing' => 'bg-blue-100 text-blue-700',
                                'ready'      => 'bg-green-100 text-green-700',
                                'pushing'    => 'bg-yellow-100 text-yellow-700',
                                'done'       => 'bg-emerald-100 text-emerald-700',
                                'failed'     => 'bg-red-100 text-red-700',
                            ];
                        @endphp
                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium {{ $colors[$session->status] ?? 'bg-gray-100 text-gray-600' }}">
                            {{ ucfirst($session->status) }}
                        </span>
                    </td>
                    <td class="px-6 py-3 text-right">
                        <div class="flex items-center justify-end gap-3">
                            <a href="{{ route('ai-content.show', $session) }}"
                               class="text-brand-600 hover:text-brand-800 font-medium text-xs">View →</a>
                            <form method="POST" action="{{ route('ai-content.destroy', $session) }}"
                                  onsubmit="return confirm('Delete this session?')">
                                @csrf @method('DELETE')
                                <button type="submit" class="text-xs text-red-400 hover:text-red-600 font-medium">Delete</button>
                            </form>
                        </div>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
        <div class="px-6 py-3 border-t border-gray-100">
            {{ $sessions->links() }}
        </div>
    </div>
    @endif

</div>
@endsection
