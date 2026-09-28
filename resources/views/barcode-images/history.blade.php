@extends('layouts.app')
@section('title', 'Image grab history')
@section('page-title', 'Image grab history')

@section('content')
<div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
    <div class="flex items-center justify-between border-b border-gray-100 px-6 py-4">
        <h2 class="font-semibold text-gray-800">Every run</h2>
        <a href="{{ route('barcode-images.index') }}"
           class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-brand-700">
            New grab
        </a>
    </div>

    @if($sessions->isEmpty())
        <p class="px-6 py-12 text-center text-sm text-gray-400">Nothing grabbed yet.</p>
    @else
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-xs uppercase tracking-wide text-gray-500">
                <tr>
                    <th class="px-6 py-3 text-left">Date</th>
                    <th class="px-6 py-3 text-left">Run</th>
                    <th class="px-6 py-3 text-left">Website</th>
                    <th class="px-6 py-3 text-center">Barcodes</th>
                    <th class="px-6 py-3 text-center">With pictures</th>
                    <th class="px-6 py-3 text-center">Images</th>
                    <th class="px-6 py-3 text-left">Status</th>
                    <th class="px-6 py-3 text-left">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @foreach($sessions as $session)
                    @php
                        $colours = ['pending' => 'gray', 'running' => 'brand', 'completed' => 'green', 'failed' => 'red'];
                        $c = $colours[$session->status] ?? 'gray';
                    @endphp
                    <tr class="transition-colors hover:bg-gray-50">
                        <td class="px-6 py-3 text-gray-600">{{ $session->created_at->format('d M Y, h:i A') }}</td>
                        <td class="px-6 py-3 text-gray-800">{{ $session->name ?: '—' }}</td>
                        <td class="px-6 py-3 text-gray-600">{{ parse_url($session->site_url, PHP_URL_HOST) }}</td>
                        <td class="px-6 py-3 text-center font-semibold text-gray-800">{{ number_format($session->total_barcodes) }}</td>
                        <td class="px-6 py-3 text-center font-medium text-green-600">{{ number_format($session->found_count) }}</td>
                        <td class="px-6 py-3 text-center text-gray-700">{{ number_format($session->images_downloaded) }}</td>
                        <td class="px-6 py-3">
                            <span class="inline-flex items-center rounded-full bg-{{ $c }}-100 px-2 py-0.5 text-xs font-medium text-{{ $c }}-700">
                                {{ ucfirst($session->status) }}
                            </span>
                        </td>
                        <td class="px-6 py-3">
                            <div class="flex items-center gap-3">
                                <a href="{{ route('barcode-images.show', $session) }}" class="text-xs font-medium text-brand-600 hover:text-brand-800">View</a>
                                @if($session->images_downloaded > 0)
                                    <a href="{{ route('barcode-images.download', $session) }}" class="text-xs font-medium text-gray-500 hover:text-gray-700">ZIP</a>
                                @endif
                                <form method="POST" action="{{ route('barcode-images.destroy', $session) }}"
                                      onsubmit="return confirm('Delete this run and its downloaded images?')">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="text-xs font-medium text-red-400 hover:text-red-600">Delete</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        @if($sessions->hasPages())
            <div class="border-t border-gray-100 px-6 py-4">{{ $sessions->links() }}</div>
        @endif
    @endif
</div>
@endsection
