@extends('layouts.app')
@section('title', 'SKU Check History')
@section('page-title', 'SKU Check History')

@section('content')
<div class="space-y-6">

    <div class="flex items-center justify-between">
        <p class="text-sm text-gray-500">All your past SKU check sessions.</p>
        <a href="{{ route('sku-checker.index') }}"
           class="bg-brand-600 hover:bg-brand-700 text-white px-4 py-2 rounded-lg text-sm font-semibold transition-colors">
            + New Check
        </a>
    </div>

    <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
        @if($sessions->isEmpty())
            <div class="px-6 py-16 text-center text-gray-400">
                <svg class="w-10 h-10 mx-auto mb-3 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                <p class="font-medium text-gray-500">No check history yet</p>
                <p class="text-sm mt-1">Run your first SKU check to see it here.</p>
            </div>
        @else
            <table class="w-full text-sm">
                <thead>
                    <tr class="bg-gray-50 border-b border-gray-100">
                        <th class="text-left px-6 py-3 font-medium text-gray-600">Date</th>
                        <th class="text-left px-6 py-3 font-medium text-gray-600">Store</th>
                        <th class="text-left px-6 py-3 font-medium text-gray-600">Status</th>
                        <th class="text-left px-6 py-3 font-medium text-gray-600">Total SKUs</th>
                        <th class="text-left px-6 py-3 font-medium text-gray-600">Available</th>
                        <th class="text-left px-6 py-3 font-medium text-gray-600">Not Available</th>
                        <th class="text-left px-6 py-3 font-medium text-gray-600">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach($sessions as $session)
                    <tr class="hover:bg-gray-50 transition-colors">
                        <td class="px-6 py-3 text-gray-600">{{ $session->created_at->format('d M Y, h:i A') }}</td>
                        <td class="px-6 py-3 text-gray-600">{{ $session->store?->name ?? '—' }}</td>
                        <td class="px-6 py-3">
                            @php $colors = ['pending'=>'gray','running'=>'brand','completed'=>'green','failed'=>'red']; $c = $colors[$session->status ?? 'completed'] ?? 'gray'; @endphp
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-{{ $c }}-100 text-{{ $c }}-700">{{ ucfirst($session->status ?? 'completed') }}</span>
                        </td>
                        <td class="px-6 py-3 font-semibold text-gray-800">{{ $session->total_skus }}</td>
                        <td class="px-6 py-3">
                            <span class="inline-flex items-center gap-1 text-green-600 font-medium">
                                <span class="w-1.5 h-1.5 rounded-full bg-green-500"></span>
                                {{ $session->available_count }}
                            </span>
                        </td>
                        <td class="px-6 py-3">
                            <span class="inline-flex items-center gap-1 text-red-500 font-medium">
                                <span class="w-1.5 h-1.5 rounded-full bg-red-400"></span>
                                {{ $session->not_available_count }}
                            </span>
                        </td>
                        <td class="px-6 py-3">
                            <div class="flex items-center gap-3">
                                <a href="{{ route('sku-checker.show', $session) }}"
                                   class="text-brand-600 hover:text-brand-800 text-xs font-medium">View</a>
                                <a href="{{ route('sku-checker.download', $session) }}"
                                   class="text-gray-500 hover:text-gray-700 text-xs font-medium">Download CSV</a>
                                <form method="POST" action="{{ route('sku-checker.destroy', $session) }}"
                                      onsubmit="return confirm('Delete this check session?')">
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
            <div class="px-6 py-4 border-t border-gray-100">
                {{ $sessions->links() }}
            </div>
            @endif
        @endif
    </div>

</div>
@endsection
