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

        <form method="POST" action="{{ route('ai-content.store') }}" x-data="{ inputType: 'sku_list' }">
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
                            <input type="radio" name="input_type" value="onedrive" x-model="inputType"
                                class="text-brand-600 focus:ring-brand-500">
                            <span class="text-sm text-gray-700">OneDrive Folder</span>
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

                {{-- OneDrive input --}}
                <div x-show="inputType === 'onedrive'" x-cloak>
                    <label class="block text-sm font-medium text-gray-700 mb-1">OneDrive Folder Link</label>
                    <input type="url" name="onedrive_link" value="{{ old('onedrive_link') }}"
                        placeholder="https://1drv.ms/f/..."
                        class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent">
                    <p class="text-xs text-gray-400 mt-1">
                        Images should be named by SKU (e.g. <code class="bg-gray-100 px-1 rounded">SKU001.jpg</code>).
                        System will match each image to its Shopify product.
                    </p>

                    @if(!auth()->user()->has_onedrive)
                    <div class="mt-3 flex items-center gap-2 bg-yellow-50 border border-yellow-200 rounded-lg px-4 py-3 text-sm text-yellow-800">
                        <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                        </svg>
                        OneDrive is not connected. <a href="{{ route('settings.index') }}" class="underline font-medium">Connect in Settings</a>
                    </div>
                    @endif
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
                        <a href="{{ route('ai-content.show', $session) }}"
                           class="text-brand-600 hover:text-brand-800 font-medium text-xs">View →</a>
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
