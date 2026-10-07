{{--
    App pagination. Overrides Laravel's Tailwind view, whose dark: variants
    follow the OS rather than the app's own light/dark switch. Separate soft
    pills instead of one joined strip; the current page takes the accent.
    Neutral classes (bg-white, border-gray-200, text-gray-*) are remapped
    under html.dark in the layout, so dark mode comes for free.
--}}
@if ($paginator->hasPages())
    @php
        $pill = 'inline-flex h-9 min-w-[2.25rem] items-center justify-center rounded-lg px-2.5 text-sm font-medium tabular-nums';
        $idle = 'border border-gray-200 bg-white text-gray-600 shadow-sm transition hover:-translate-y-px hover:border-[#6185b2]/50 hover:text-[#46668d] hover:shadow';
        $off  = 'border border-gray-100 bg-white text-gray-300 cursor-not-allowed';
    @endphp

    <nav role="navigation" aria-label="{{ __('Pagination Navigation') }}"
         class="flex flex-col items-center gap-3 sm:flex-row sm:justify-between">

        <p class="text-xs text-gray-500">
            @if ($paginator->firstItem())
                Showing
                <span class="font-semibold text-gray-800">{{ $paginator->firstItem() }}</span>–<span class="font-semibold text-gray-800">{{ $paginator->lastItem() }}</span>
                of
                <span class="font-semibold text-gray-800">{{ $paginator->total() }}</span>
            @else
                Showing {{ $paginator->count() }} of {{ $paginator->total() }}
            @endif
        </p>

        <div class="flex flex-wrap items-center justify-center gap-1.5">
            {{-- Previous --}}
            @if ($paginator->onFirstPage())
                <span class="{{ $pill }} {{ $off }}" aria-disabled="true" aria-label="{{ __('pagination.previous') }}">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
                </span>
            @else
                <a href="{{ $paginator->previousPageUrl() }}" rel="prev" class="{{ $pill }} {{ $idle }}" aria-label="{{ __('pagination.previous') }}">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
                </a>
            @endif

            {{-- Pages: hidden on phones, where prev/next and the count are enough --}}
            <span class="hidden items-center gap-1.5 sm:flex">
                @foreach ($elements as $element)
                    @if (is_string($element))
                        <span class="{{ $pill }} text-gray-400" aria-disabled="true">…</span>
                    @endif

                    @if (is_array($element))
                        @foreach ($element as $page => $url)
                            @if ($page == $paginator->currentPage())
                                <span aria-current="page"
                                      class="{{ $pill }} border border-[#46668d] bg-[#6185b2] font-semibold text-white shadow-[0_6px_16px_-6px_rgba(97,133,178,.9)]">
                                    {{ $page }}
                                </span>
                            @else
                                <a href="{{ $url }}" class="{{ $pill }} {{ $idle }}" aria-label="{{ __('Go to page :page', ['page' => $page]) }}">
                                    {{ $page }}
                                </a>
                            @endif
                        @endforeach
                    @endif
                @endforeach
            </span>

            <span class="px-1 text-xs font-medium text-gray-500 sm:hidden">
                Page {{ $paginator->currentPage() }} of {{ $paginator->lastPage() }}
            </span>

            {{-- Next --}}
            @if ($paginator->hasMorePages())
                <a href="{{ $paginator->nextPageUrl() }}" rel="next" class="{{ $pill }} {{ $idle }}" aria-label="{{ __('pagination.next') }}">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                </a>
            @else
                <span class="{{ $pill }} {{ $off }}" aria-disabled="true" aria-label="{{ __('pagination.next') }}">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                </span>
            @endif
        </div>
    </nav>
@endif
