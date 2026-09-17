{{--
    What the screen looks like while a tab is being answered.

    Shaped like the thing it is standing in for — four tiles, a chart, a row
    of cards — so the layout does not jump when the real figures land. Every
    tab that asks Shopify or Google for a range includes this, because a range
    nobody has looked at yet is not cached and takes seconds to come back.
--}}
<div class="space-y-5" aria-live="polite" aria-busy="true">
    <span class="sr-only">Loading…</span>

    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        @for($i = 0; $i < 4; $i++)
            <div class="bg-white rounded-xl border border-gray-200 p-5 animate-pulse">
                <div class="h-3 w-20 bg-gray-200 rounded"></div>
                <div class="h-7 w-28 bg-gray-200 rounded mt-4"></div>
                <div class="h-2.5 w-24 bg-gray-100 rounded mt-3"></div>
            </div>
        @endfor
    </div>

    <div class="bg-white rounded-xl border border-gray-200 shadow-sm animate-pulse">
        <div class="px-5 py-3.5 border-b border-gray-100">
            <div class="h-3.5 w-40 bg-gray-200 rounded"></div>
        </div>
        <div class="px-5 py-4 space-y-2.5">
            @for($i = 0; $i < 4; $i++)
                <div class="grid grid-cols-[minmax(7rem,12rem)_1fr_auto] items-center gap-3">
                    <div class="h-3 w-28 bg-gray-200 rounded"></div>
                    <div class="h-2.5 rounded-full bg-gray-100"></div>
                    <div class="h-3 w-16 bg-gray-200 rounded"></div>
                </div>
            @endfor
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 xl:grid-cols-3 gap-4">
        @for($i = 0; $i < 3; $i++)
            <div class="bg-white rounded-xl border border-gray-200 shadow-sm animate-pulse">
                <div class="px-5 py-3.5 border-b border-gray-100">
                    <div class="h-3.5 w-32 bg-gray-200 rounded"></div>
                    <div class="h-7 w-36 bg-gray-200 rounded mt-3"></div>
                    <div class="h-2.5 w-28 bg-gray-100 rounded mt-3"></div>
                </div>
                <div class="px-5 py-3.5 space-y-2">
                    @for($line = 0; $line < 3; $line++)
                        <div class="h-2 bg-gray-100 rounded"></div>
                    @endfor
                </div>
            </div>
        @endfor
    </div>
</div>
