@php
    // How much of the work is live, as a ring; the rest as tiles by where it is.
    $total   = (int) $stats['total'];
    $live    = (int) $stats['published'];
    $livePct = $total > 0 ? (int) round(100 * $live / $total) : 0;
    $C       = 2 * M_PI * 42;

    $tiles = [
        ['Pending',               $stats['pending'],            'pending',                                         'bg-amber-50 border-amber-100',     'bg-amber-100 text-amber-600',     'text-amber-700',   'Awaiting action',           'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z'],
        ['Waiting for Mapping',   $stats['waiting_mapping'],    \App\Models\ProductRequest::WAITING_MAPPING,       'bg-orange-50 border-orange-100',   'bg-orange-100 text-orange-600',   'text-orange-700',  'With the brand manager',    'M8 9l4-4 4 4m0 6l-4 4-4-4'],
        ['In Progress',           $stats['in_progress'],        'in_progress',                                     'bg-sky-50 border-sky-100',         'bg-sky-100 text-sky-600',         'text-sky-700',     'Being worked on',           'M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15'],
        ['Photoshoot',            $stats['waiting_photoshoot'], \App\Models\ProductRequest::PHOTOSHOOT_SCHEDULED,  'bg-violet-50 border-violet-100',   'bg-violet-100 text-violet-600',   'text-violet-700',  'Waiting for images',        'M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9zM15 13a3 3 0 11-6 0 3 3 0 016 0z'],
        ['On Hold',               $stats['on_hold'],            'on_hold',                                         'bg-red-50 border-red-100',         'bg-red-100 text-red-500',         'text-red-600',     'Blocked',                   'M10 9v6m4-6v6m7-3a9 9 0 11-18 0 9 9 0 0118 0z'],
        ['Published',             $stats['published'],          \App\Models\ProductRequest::PUBLISHED,             'bg-green-50 border-green-100',     'bg-green-100 text-green-600',     'text-green-700',   'Live and closed',           'M5 13l4 4L19 7'],
    ];
@endphp

<div class="bg-white rounded-2xl border border-gray-200/80 shadow-sm p-5 flex flex-col lg:flex-row items-center gap-6">
    {{-- Total, and how much of it is live --}}
    <a href="{{ route('product-requests.list') }}" class="flex items-center gap-4 shrink-0 group">
        <div class="relative w-28 h-28">
            <svg class="w-28 h-28 -rotate-90" viewBox="0 0 100 100">
                <circle cx="50" cy="50" r="42" fill="none" stroke-width="9" class="text-gray-100" stroke="currentColor"/>
                <circle cx="50" cy="50" r="42" fill="none" stroke-width="9" stroke-linecap="round" stroke="currentColor"
                        class="text-green-500 transition-all duration-700"
                        stroke-dasharray="{{ $C }}" stroke-dashoffset="{{ $C * (1 - $livePct / 100) }}"/>
            </svg>
            <div class="absolute inset-0 flex flex-col items-center justify-center">
                <span class="text-2xl font-bold text-gray-900 tabular-nums">{{ $livePct }}%</span>
                <span class="text-[11px] text-gray-500">live</span>
            </div>
        </div>
        <div>
            <p class="text-xs font-medium text-gray-500">Total Requests</p>
            <p class="text-4xl font-bold text-gray-900 tabular-nums leading-tight">{{ number_format($total) }}</p>
            <p class="text-xs text-gray-400 group-hover:text-brand-600 transition-colors">{{ number_format($live) }} live on the website</p>
        </div>
    </a>

    {{-- Where the rest are --}}
    <div class="flex-1 w-full grid grid-cols-2 md:grid-cols-3 2xl:grid-cols-6 gap-3">
        @foreach($tiles as [$label, $value, $filter, $bg, $iconTone, $numTone, $hint, $icon])
            <a href="{{ route('product-requests.list', ['status' => $filter]) }}"
               class="rounded-2xl border px-4 py-3 transition hover:-translate-y-0.5 hover:shadow-sm {{ $bg }}">
                <div class="flex items-center justify-between gap-2">
                    <span class="text-xs font-medium text-gray-600 leading-tight">{{ $label }}</span>
                    <span class="w-7 h-7 rounded-lg flex items-center justify-center shrink-0 {{ $iconTone }}">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $icon }}"/></svg>
                    </span>
                </div>
                <p class="mt-1 text-2xl font-bold tabular-nums {{ $value > 0 ? $numTone : 'text-gray-300' }}">{{ number_format($value) }}</p>
                <p class="text-[11px] text-gray-500 truncate">{{ $hint }}</p>
            </a>
        @endforeach
    </div>
</div>
