{{--
    The storefronts on their way onto this tab.

    Shared by Ecom Order Analytics and Visitor Sessions, because the answer
    to "why isn't this site here" is the same on both. They have no card of
    their own because there is nothing to put in one — no token, no property,
    no figures — and a card of dashes would read as a site that sold nothing
    and had no visitors, which is a different and much worse claim than "not
    connected yet".

    Deliberately last on the page. It is the answer to a question somebody
    asks after reading the real figures, not before.
--}}
<div class="bg-white rounded-xl border border-gray-200 shadow-sm">
    <div class="px-5 py-3.5 border-b border-gray-100 flex items-baseline justify-between gap-3">
        <h3 class="text-sm font-semibold text-gray-800">Integration in progress</h3>
        <span class="text-xs text-gray-400">{{ count($integrating) }} websites</span>
    </div>
    <div class="px-5 py-4">
        <p class="text-xs text-gray-400">
            Being connected now. Each one gets a card of its own above once its figures start arriving.
        </p>
        <div class="mt-3 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5 gap-x-4 gap-y-2">
            @foreach($integrating as $domain)
                <span class="inline-flex items-center gap-1.5 text-xs text-gray-600 min-w-0" title="{{ $domain }}">
                    <span class="h-1.5 w-1.5 rounded-full bg-amber-400 shrink-0"></span>
                    <span class="truncate">{{ $domain }}</span>
                </span>
            @endforeach
        </div>
    </div>
</div>
