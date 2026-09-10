@extends('layouts.app')
@section('title', 'Team')
@section('page-title', 'Team')

@php
    /*
     * The work-distribution sheet, transcribed. There is no backend behind
     * this screen on purpose — the department's ownership map changes a few
     * times a year, so it lives here as data and is edited in place rather
     * than modelled in the database.
     *
     * A cell is one of:
     *   'check'                       → the person covers that row
     *   'Lead' | 'Primary' | 'Support'→ the weight of their involvement
     *   ['a', 'b', …]                 → the categories they own
     */
    $people = ['Jestin', 'Ahamed Thilsan', 'Lal Mohammed', 'Ghassen', 'Rasul', 'Ahmad', 'Cleford', 'Wafa'];

    $dev  = ['Jestin', 'Ahamed Thilsan', 'Lal Mohammed'];
    $ops  = ['Ghassen', 'Rasul', 'Ahmad'];

    // Rows where the same three people all carry the same weight — the bulk
    // of "Key Responsibilities" — are spelled out by this helper instead of
    // repeating an identical map fifteen times.
    $all = fn (array $who, string $weight) => array_fill_keys($who, $weight);

    $sections = [
        [
            'title' => 'Development',
            'rows'  => [
                ['label' => 'Technical',                'cells' => ['Jestin' => 'Lead', 'Ahamed Thilsan' => 'Support', 'Lal Mohammed' => 'Support']],
                ['label' => 'Shopify Development',      'cells' => $all($dev, 'check')],
                ['label' => 'UI/UX & Frontend',         'cells' => $all($dev, 'check')],
                ['label' => 'API & Integrations',       'cells' => $all($dev, 'check')],
                ['label' => 'Bug Fixes & Enhancements', 'cells' => $all($dev, 'check')],
                ['label' => 'Website Refunds',          'cells' => ['Wafa' => 'check']],
                ['label' => 'Delivery, Invoices',       'cells' => ['Wafa' => 'check']],
            ],
        ],
        [
            'title' => 'Website Ownership',
            'rows'  => [
                ['label' => 'Blue Salon (Web/App)',    'cells' => $all($ops, 'check')],
                ['label' => 'Cole Haan (Web)',         'cells' => ['Ghassen' => 'check']],
                ['label' => 'Triumph (Web)',           'cells' => ['Ghassen' => 'check']],
                ['label' => 'Toys4Me (Web/App)',       'cells' => ['Ghassen' => 'check']],
                ['label' => 'Out of the Blue (Web)',   'cells' => ['Ghassen' => 'check']],
                ['label' => 'Qatar Outlet (Web)',      'cells' => $all($ops, 'check')],
                ['label' => 'Secret Notes (Web)',      'cells' => ['Rasul' => 'check']],
                ['label' => 'Pari Gallery (Web/App)',  'cells' => ['Ghassen' => 'check']],
                ['label' => 'Face Shop (Web)',         'cells' => ['Ghassen' => 'check']],
                ['label' => 'Karisma (Web)',           'cells' => ['Rasul' => 'check']],
                ['label' => 'Samsonite (Web)',         'cells' => ['Ahmad' => 'check']],
                ['label' => 'American Tourister (Web)','cells' => ['Ahmad' => 'check']],
                ['label' => 'Mosafer (Web)',           'cells' => ['Ahmad' => 'check']],
                ['label' => 'Replay (Web)',            'cells' => ['Ahmad' => 'check']],
                ['label' => 'Gold Gourmet (Web)',      'cells' => ['Ahmad' => 'check']],
            ],
        ],
        [
            'title' => 'Category Ownership',
            'rows'  => [
                ['label' => 'Fashion Categories',   'cells' => ['Ghassen' => ['Lingerie', 'Linen', 'Food & Beverages', "Men's Fashion", 'Fashion Accessories', 'Watches']]],
                ['label' => 'Beauty & PG Operations', 'cells' => ['Rasul' => ['Leather Goods', 'Beauty', 'PG Operations']]],
                ['label' => 'Lifestyle',            'cells' => ['Ahmad' => ['Luggage', "Women's Fashion", 'Kids', 'Home']]],
            ],
        ],
        [
            'title' => 'Key Responsibilities',
            'rows'  => [
                ['label' => 'Photoshoot Coordination',              'cells' => ['Ghassen' => 'Primary', 'Cleford' => 'Support']],
                ['label' => 'Product Upload',                       'cells' => $all($ops, 'Primary')],
                ['label' => 'Product Photography',                  'cells' => ['Cleford' => 'Primary']],
                ['label' => 'Minor Image Editing',                  'cells' => $all($ops, 'Support') + ['Cleford' => 'Primary']],
                ['label' => 'Website Department Merchandising',     'cells' => $all($ops, 'Primary')],
                ['label' => 'Landing Pages',                        'cells' => $all($ops, 'Primary')],
                ['label' => 'Brand Manager Coordination',           'cells' => $all($ops, 'Primary')],
                ['label' => 'Campaign Planning for the Website/App','cells' => $all($ops, 'Primary')],
                ['label' => 'Banner Requests',                      'cells' => $all($ops, 'Primary')],
                ['label' => 'New Arrivals',                         'cells' => $all($ops, 'Primary')],
                ['label' => 'Brand Launches',                       'cells' => $all($ops, 'Primary')],
                ['label' => 'Collection Management',                'cells' => $all($ops, 'Primary')],
                ['label' => 'Website QA',                           'cells' => $all($ops, 'Primary')],
            ],
        ],
    ];

    // Tailwind's JIT never sees a runtime-built class, so the weights are
    // spelled out here and looked up by key.
    $weight = [
        'Lead'    => 'bg-brand-600 text-white ring-brand-600/20',
        'Primary' => 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
        'Support' => 'bg-amber-50 text-amber-700 ring-amber-600/20',
    ];

    // Initials for the column avatars; two words give two letters.
    $initials = function (string $name) {
        $parts = preg_split('/\s+/', trim($name));
        return strtoupper(substr($parts[0], 0, 1) . (count($parts) > 1 ? substr($parts[1], 0, 1) : ''));
    };
@endphp

@section('content')
<div class="space-y-5">

    {{-- ── Header ──────────────────────────────────────────────────────── --}}
    <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
        <div class="flex flex-wrap items-center justify-between gap-4 border-b border-gray-100 px-5 py-4">
            <div>
                <h2 class="text-base font-semibold text-gray-900">AIH E-Commerce Department</h2>
                <p class="mt-0.5 text-xs text-gray-500">Work distribution — who owns which site, category and task.</p>
            </div>
            <div class="flex flex-wrap items-center gap-x-4 gap-y-2 text-[11px] text-gray-500">
                <span class="inline-flex items-center gap-1.5">
                    <svg class="h-3.5 w-3.5 text-brand-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="3">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                    </svg>
                    Owns
                </span>
                @foreach ($weight as $label => $classes)
                    <span class="inline-flex items-center gap-1.5">
                        <span class="rounded px-1.5 py-0.5 text-[10px] font-semibold ring-1 ring-inset {{ $classes }}">{{ $label }}</span>
                    </span>
                @endforeach
            </div>
        </div>

        {{-- ── Matrix ──────────────────────────────────────────────────────
             Nine columns do not fit a laptop, so the grid scrolls sideways
             while the responsibility column and the name row stay pinned —
             a checkmark six columns out is meaningless without both. --}}
        <div class="overflow-x-auto">
            <table class="w-full min-w-[1100px] border-separate border-spacing-0 text-sm">
                <thead>
                    <tr>
                        <th scope="col"
                            class="sticky left-0 z-20 w-64 min-w-[16rem] border-b border-r border-gray-200 bg-gray-50 px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                            Responsibility
                        </th>
                        @foreach ($people as $person)
                            <th scope="col" class="border-b border-gray-200 bg-gray-50 px-3 py-3 text-center align-bottom
                                                   {{ $loop->last ? '' : 'border-r border-gray-100' }}">
                                <span class="mx-auto mb-1.5 grid h-7 w-7 place-items-center rounded-full bg-brand-100 text-[10px] font-bold text-brand-800">
                                    {{ $initials($person) }}
                                </span>
                                <span class="block whitespace-nowrap text-xs font-semibold text-gray-700">{{ $person }}</span>
                            </th>
                        @endforeach
                    </tr>
                </thead>

                <tbody>
                    @foreach ($sections as $section)
                        <tr>
                            <th scope="colgroup" colspan="{{ count($people) + 1 }}"
                                class="border-y border-brand-200 bg-brand-50 px-4 py-2 text-left text-[11px] font-bold uppercase tracking-[.12em] text-brand-800">
                                {{ $section['title'] }}
                            </th>
                        </tr>

                        @foreach ($section['rows'] as $row)
                            <tr class="group">
                                <th scope="row"
                                    class="sticky left-0 z-10 border-b border-r border-gray-200 bg-white px-4 py-2.5 text-left align-top text-[13px] font-medium text-gray-800 group-hover:bg-brand-50/60">
                                    {{ $row['label'] }}
                                </th>

                                @foreach ($people as $person)
                                    @php $cell = $row['cells'][$person] ?? null; @endphp
                                    <td class="border-b border-gray-100 px-3 py-2.5 text-center align-top group-hover:bg-brand-50/40
                                               {{ $loop->last ? '' : 'border-r' }}">
                                        @if ($cell === 'check')
                                            <svg class="mx-auto h-4 w-4 text-brand-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="3">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                                            </svg>
                                        @elseif (is_array($cell))
                                            <div class="flex flex-wrap justify-center gap-1">
                                                @foreach ($cell as $category)
                                                    <span class="rounded bg-gray-100 px-1.5 py-0.5 text-[11px] font-medium text-gray-700">{{ $category }}</span>
                                                @endforeach
                                            </div>
                                        @elseif ($cell)
                                            <span class="inline-block rounded px-1.5 py-0.5 text-[10px] font-semibold ring-1 ring-inset {{ $weight[$cell] ?? 'bg-gray-100 text-gray-600 ring-gray-300' }}">
                                                {{ $cell }}
                                            </span>
                                        @else
                                            <span class="text-gray-200">·</span>
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
