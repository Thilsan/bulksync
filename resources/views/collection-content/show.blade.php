@extends('layouts.app')
@section('title', 'Review Collection SEO')
@section('page-title', 'Review Collection SEO')

@section('content')
<div class="space-y-6" x-data="collectionReview('{{ $session->status }}')" x-init="init()">

    <div class="flex items-center justify-between">
        <a href="{{ route('collection-content.index') }}" class="text-sm text-gray-500 hover:text-gray-700">← Back to Collection SEO</a>
        <p class="text-xs text-gray-400" x-show="status === 'processing' || status === 'pending'">
            <span x-text="processed"></span> / <span x-text="total"></span> written
        </p>
    </div>

    {{-- Progress --}}
    <div x-show="status === 'processing' || status === 'pending'" class="bg-white rounded-xl border border-gray-200 p-6">
        <p class="text-sm font-medium text-gray-700 mb-3">Writing collection pages…</p>
        <div class="w-full bg-gray-100 rounded-full h-3 overflow-hidden">
            <div class="bg-brand-600 h-3 rounded-full transition-all duration-500" :style="'width: ' + progress + '%'"></div>
        </div>
        <p class="text-xs text-gray-400 mt-2 text-center" x-text="progress + '% complete'"></p>
    </div>

    @if($session->status === 'failed')
    <div class="bg-red-50 border border-red-200 rounded-xl p-4 text-sm text-red-700">
        <strong>Generation failed:</strong> {{ $session->error_message }}
    </div>
    @endif

    @if($session->items->where('status', '!=', 'pending')->isNotEmpty())
    <form method="POST" action="{{ route('collection-content.push', $session) }}">
        @csrf

        <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between gap-4">
                <div>
                    <h3 class="font-semibold text-gray-800">Suggestions</h3>
                    <p class="text-xs text-gray-500 mt-0.5">
                        Edit anything. Only ticked collections are written to Shopify — the rest are saved for later.
                    </p>
                </div>
                <button type="submit"
                        onclick="return confirm('Write the ticked collections to Shopify?')"
                        class="bg-brand-600 hover:bg-brand-700 text-white px-5 py-2 rounded-lg text-sm font-semibold transition-colors">
                    Push ticked to Shopify
                </button>
            </div>

            <div class="divide-y divide-gray-100">
                @foreach($session->items as $item)
                <div class="px-6 py-5">
                    <div class="flex items-start gap-3">
                        <input type="checkbox" name="confirmed[]" value="{{ $item->id }}"
                               @checked($item->status === 'done')
                               @disabled($item->status === 'failed')
                               class="mt-1 w-4 h-4 rounded border-gray-300 text-brand-600 focus:ring-brand-500">
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2 flex-wrap">
                                <p class="font-semibold text-gray-800">{{ $item->title ?: 'Collection ' . $item->collection_id }}</p>
                                <span class="text-xs font-mono text-gray-400">{{ $item->path() }}</span>
                                @if($item->status === 'pushed')
                                    <span class="inline-flex px-2 py-0.5 rounded-full text-xs bg-green-50 text-green-700">Pushed</span>
                                @elseif($item->status === 'failed')
                                    <span class="inline-flex px-2 py-0.5 rounded-full text-xs bg-red-50 text-red-600">Failed</span>
                                @endif
                            </div>

                            @if($item->error_message)
                                <p class="mt-1 text-xs text-red-600">{{ $item->error_message }}</p>
                            @endif

                            @if($item->status !== 'failed')
                            <div class="mt-3 grid gap-3 lg:grid-cols-2">
                                <div>
                                    <label class="text-xs font-medium text-gray-600">Meta title</label>
                                    <input type="text" name="meta_title[{{ $item->id }}]" value="{{ $item->ai_meta_title }}"
                                           maxlength="60"
                                           class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
                                    @if($item->existing_meta_title)
                                        <p class="mt-1 text-xs text-gray-400">Was: {{ $item->existing_meta_title }}</p>
                                    @else
                                        <p class="mt-1 text-xs text-amber-600">Was empty</p>
                                    @endif
                                </div>
                                <div>
                                    <label class="text-xs font-medium text-gray-600">Meta description</label>
                                    <input type="text" name="meta_description[{{ $item->id }}]" value="{{ $item->ai_meta_description }}"
                                           maxlength="160"
                                           class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
                                    @if($item->existing_meta_description)
                                        <p class="mt-1 text-xs text-gray-400">Was: {{ Str::limit($item->existing_meta_description, 90) }}</p>
                                    @else
                                        <p class="mt-1 text-xs text-amber-600">Was empty</p>
                                    @endif
                                </div>
                            </div>

                            <div class="mt-3">
                                <label class="flex items-center gap-2 text-xs font-medium text-gray-600 cursor-pointer select-none">
                                    <input type="checkbox" name="push_description[{{ $item->id }}]" value="1"
                                           @checked(!$item->existing_description)
                                           class="w-3.5 h-3.5 rounded border-gray-300 text-brand-600 focus:ring-brand-500">
                                    {{-- Off by default where copy already exists: replacing a description
                                         somebody wrote by hand should be a deliberate choice. --}}
                                    Also replace the collection description
                                    @if($item->existing_description)
                                        <span class="font-normal text-gray-400">(one already exists)</span>
                                    @endif
                                </label>
                                <textarea name="description[{{ $item->id }}]" rows="3"
                                          class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-brand-500">{{ $item->ai_description }}</textarea>
                            </div>
                            @endif
                        </div>
                    </div>
                </div>
                @endforeach
            </div>
        </div>
    </form>
    @endif

</div>

<script>
function collectionReview(initialStatus) {
    return {
        status:    initialStatus,
        total:     {{ $session->total_items }},
        processed: {{ $session->processed_items }},
        progress:  {{ $session->progressPercent() }},
        timer:     null,

        init() {
            if (this.status === 'pending' || this.status === 'processing') {
                this.timer = setInterval(() => this.poll(), 3000);
            }
        },

        async poll() {
            const res  = await fetch('{{ route('collection-content.status', $session) }}');
            const data = await res.json();

            this.status    = data.status;
            this.processed = data.processed_items;
            this.progress  = data.progress;

            if (data.status !== 'pending' && data.status !== 'processing') {
                clearInterval(this.timer);
                // The suggestions are rendered server-side, so the page has to
                // come back for them once writing has finished.
                window.location.reload();
            }
        },
    };
}
</script>
@endsection
