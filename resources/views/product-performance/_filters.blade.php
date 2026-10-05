{{-- Filter bar shared by the Products and Divisions views. $mode picks which one. --}}
@php
    $isAll = $filters['store'] === 'all';
    $csv   = $mode === 'divisions' ? 'product-performance.divisions.download' : 'product-performance.download';
    $carry = request()->except(['page', 'tab', 'max', 'division', 'q']);
    $field = 'h-9 rounded-lg border border-gray-200 bg-gray-50 px-3 text-sm text-gray-800 focus:outline-none focus:ring-2 focus:ring-brand-500 focus:bg-white';
@endphp
<div class="bg-white rounded-xl border border-gray-200 px-4 py-3">
    <form method="GET" class="flex flex-wrap items-center gap-2">
        @if($mode === 'products')
            <input type="hidden" name="tab" value="{{ $filters['tab'] }}">
            @if($filters['division'] !== '')
                <input type="hidden" name="division" value="{{ $filters['division'] }}">
            @endif
        @endif

        <div class="inline-flex h-9 rounded-lg bg-gray-100 p-0.5 text-sm font-medium">
            @foreach(['products' => ['Products', 'product-performance.index'], 'divisions' => ['Divisions', 'product-performance.divisions']] as $key => [$label, $route])
                <a href="{{ route($route, $carry) }}"
                   class="px-3 inline-flex items-center rounded-md {{ $mode === $key ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500 hover:text-gray-800' }}">{{ $label }}</a>
            @endforeach
        </div>

        <select name="store" onchange="this.form.submit()" aria-label="Website" class="{{ $field }}">
            <option value="all" @selected($isAll)>All websites</option>
            @foreach($choices as $choice)
                <option value="{{ $choice->id }}" @selected((string) $choice->id === $filters['store'])>{{ $choice->name }}</option>
            @endforeach
        </select>
        <select name="days" onchange="this.form.submit()" aria-label="Period" class="{{ $field }}">
            @foreach($ranges as $range)
                <option value="{{ $range }}" @selected($range === $filters['days'])>Last {{ $range }} days</option>
            @endforeach
        </select>
        <select name="brand" onchange="this.form.submit()" aria-label="Brand" class="{{ $field }} max-w-[12rem]">
            <option value="">All brands</option>
            @foreach($brands as $brand)
                <option value="{{ $brand }}" @selected($brand === $filters['brand'])>{{ $brand }}</option>
            @endforeach
        </select>
        @if($mode === 'products' && $filters['tab'] === 'low')
            <label class="flex items-center gap-1.5 text-xs text-gray-500">
                ≤
                <input type="number" name="max" min="1" max="50" value="{{ $filters['max'] }}" aria-label="Sold at most"
                       onchange="this.form.submit()" class="{{ $field }} w-16">
                units
            </label>
        @endif
        <div class="relative flex-1 min-w-[10rem] max-w-xs">
            <svg class="absolute left-2.5 top-1/2 -translate-y-1/2 h-4 w-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-4.35-4.35M17 11a6 6 0 11-12 0 6 6 0 0112 0z"/></svg>
            <input type="search" name="q" value="{{ $filters['search'] }}" placeholder="{{ $mode === 'divisions' ? 'Search division' : 'Search product or SKU' }}" aria-label="Search"
                   class="{{ $field }} w-full pl-8">
        </div>

        <div class="ml-auto flex items-center gap-2">
            @if($summary['syncedAt'])
                <span class="hidden md:inline text-xs text-gray-400 mr-1">Updated {{ $summary['syncedAt']->diffForHumans() }}</span>
            @endif
            <a href="{{ route($csv, request()->except('page')) }}" title="Download CSV"
               class="h-9 inline-flex items-center gap-1.5 px-3 rounded-lg border border-gray-200 text-sm font-medium text-gray-700 hover:bg-gray-50">
                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                CSV
            </a>
            @if(auth()->user()->is_super_admin)
                <button form="pp-sync" class="h-9 inline-flex items-center px-3 rounded-lg bg-brand-600 text-white text-sm font-medium hover:bg-brand-700">Sync now</button>
            @endif
        </div>
    </form>
    @if(auth()->user()->is_super_admin)
        <form id="pp-sync" method="POST" action="{{ route('product-performance.refresh') }}" class="hidden">@csrf</form>
    @endif

    {{-- Why a number might be missing, kept to one line each; the detail sits in the tooltip. --}}
    @if($summary['never']->isNotEmpty())
        <p class="mt-3 text-xs text-amber-700 flex items-center gap-1.5" title="{{ $summary['never']->pluck('name')->join(', ') }}">
            <span class="h-1.5 w-1.5 rounded-full bg-amber-500"></span>
            {{ $summary['never']->count() }} {{ \Illuminate\Support\Str::plural('website', $summary['never']->count()) }} waiting for first sync
        </p>
    @endif
    @foreach($summary['failing'] as $failing)
        <p class="mt-2 text-xs text-red-700 flex items-center gap-1.5" title="{{ $failing->sales_sync_error }}">
            <span class="h-1.5 w-1.5 rounded-full bg-red-500"></span>
            {{ $failing->name }}: sync failed — {{ \Illuminate\Support\Str::limit($failing->sales_sync_error, 90) }}
        </p>
    @endforeach
    @foreach($summary['partial'] as $partial)
        <p class="mt-2 text-xs text-amber-700 flex items-center gap-1.5" title="Shopify gives 60 days of orders unless the app has read_all_orders.">
            <span class="h-1.5 w-1.5 rounded-full bg-amber-500"></span>
            {{ $partial->name }}: data from {{ $partial->sales_covered_from->format('j M Y') }} only
        </p>
    @endforeach
</div>
