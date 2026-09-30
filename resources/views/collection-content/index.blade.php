@extends('layouts.app')
@section('title', 'Collection SEO')
@section('page-title', 'Collection SEO')

@section('content')
<div class="space-y-6">

    <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
        <div class="px-6 py-5 border-b border-gray-100">
            <h2 class="font-semibold text-gray-800 text-lg">Write the collection pages</h2>
            <p class="text-sm text-gray-500 mt-1">
                Collection pages rank for the category somebody actually types — "cabin luggage qatar" —
                while a product page competes for a model name. There are only a few dozen of them,
                and they carry a share of search demand out of all proportion to their number.
            </p>
            <p class="text-sm text-gray-500 mt-1">
                There is no photograph to write from, so the products inside each collection are the evidence.
                Nothing is written to Shopify until you review it.
            </p>
        </div>

        <form method="POST" action="{{ route('collection-content.store') }}">
            @csrf
            <div class="px-6 py-5">
                <label for="keywords" class="text-sm font-medium text-gray-700">
                    Target search terms <span class="font-normal text-gray-400">— optional</span>
                </label>
                <textarea id="keywords" name="keywords" rows="2"
                          placeholder="cabin luggage qatar, travel bags doha, hand luggage"
                          class="mt-1.5 w-full resize-y rounded-lg border px-3.5 py-2.5 text-sm transition focus:border-brand-500 focus:outline-none focus:ring-4 focus:ring-brand-500/15 {{ $errors->has('keywords') ? 'border-red-400' : 'border-gray-300' }}">{{ old('keywords') }}</textarea>
                @error('keywords')
                    <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                @enderror
                <p class="mt-1.5 text-xs text-gray-400">
                    Comma or line separated. Terms that don't fit a collection are ignored rather than forced in.
                    @if($activeStore?->gsc_site_url)
                        Search Console is connected, so each collection's own queries are added to these automatically.
                    @endif
                </p>
            </div>

            <div class="flex items-center justify-between gap-3 border-t border-gray-100 bg-gray-50 px-6 py-4">
                <p class="text-xs text-gray-400">
                    Covers every collection in {{ $activeStore?->name ?? 'this website' }},
                    up to {{ number_format(\App\Http\Controllers\CollectionContentController::MAX_BATCH) }} per run.
                </p>
                <button type="submit"
                        onclick="return confirm('Generate SEO content for this website\'s collections?\n\nNothing is written to Shopify yet — you will review everything first.')"
                        class="inline-flex items-center gap-2 rounded-lg bg-brand-600 px-6 py-2.5 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-brand-700">
                    Generate for all collections
                </button>
            </div>
        </form>
    </div>

    <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-100">
            <h3 class="font-semibold text-gray-800">Previous runs</h3>
        </div>

        @if($sessions->isEmpty())
        <div class="px-6 py-12 text-center text-gray-400 text-sm">
            Nothing yet. You can also start one from an SEO audit, which sends only the collections that need fixing.
        </div>
        @else
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-xs text-gray-500 uppercase tracking-wide">
                <tr>
                    <th class="px-6 py-3 text-left">Date</th>
                    <th class="px-6 py-3 text-left">Store</th>
                    <th class="px-6 py-3 text-left">Status</th>
                    <th class="px-6 py-3 text-center">Collections</th>
                    <th class="px-6 py-3 text-center">Pushed</th>
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
                            $colors = ['pending' => 'gray', 'processing' => 'brand', 'ready' => 'amber', 'done' => 'green', 'failed' => 'red'];
                            $c = $colors[$session->status] ?? 'gray';
                        @endphp
                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-{{ $c }}-100 text-{{ $c }}-700">
                            {{ ucfirst($session->status) }}
                        </span>
                    </td>
                    <td class="px-6 py-3 text-center font-semibold text-gray-800">{{ number_format($session->total_items) }}</td>
                    <td class="px-6 py-3 text-center text-green-600 font-medium">
                        {{ number_format($session->items()->where('status', 'pushed')->count()) }}
                    </td>
                    <td class="px-6 py-3">
                        <div class="flex items-center gap-3">
                            <a href="{{ route('collection-content.show', $session) }}" class="text-brand-600 hover:text-brand-800 text-xs font-medium">Review</a>
                            <form method="POST" action="{{ route('collection-content.destroy', $session) }}"
                                  onsubmit="return confirm('Delete this session?')">
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
