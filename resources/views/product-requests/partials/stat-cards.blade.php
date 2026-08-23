@php
    $tiles = [
        ['key' => 'total',              'label' => 'Total Requests',      'hint' => 'All requests',        'value' => $stats['total'],              'tone' => 'text-brand-600 bg-brand-50',      'filter' => null,                                'icon' => 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z'],
        ['key' => 'pending',            'label' => 'Pending',             'hint' => 'Awaiting action',     'value' => $stats['pending'],            'tone' => 'text-amber-600 bg-amber-50',      'filter' => \App\Models\ProductRequest::SUBMITTED,       'icon' => 'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z'],
        ['key' => 'waiting_mapping',    'label' => 'Waiting for Mapping', 'hint' => 'With the brand manager',   'value' => $stats['waiting_mapping'],    'tone' => 'text-orange-600 bg-orange-50',    'filter' => \App\Models\ProductRequest::WAITING_MAPPING, 'icon' => 'M4 4h16M6 4v5a6 6 0 006 6 6 6 0 006-6V4M6 20v-5a6 6 0 016-6 6 6 0 016 6v5M4 20h16'],
        ['key' => 'in_progress',        'label' => 'In Progress',         'hint' => 'In workflow',         'value' => $stats['in_progress'],        'tone' => 'text-blue-600 bg-blue-50',        'filter' => null,                                'icon' => 'M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15'],
        ['key' => 'waiting_photoshoot', 'label' => 'Waiting for Photoshoot','hint' => 'Photoshoot required','value' => $stats['waiting_photoshoot'],'tone' => 'text-purple-600 bg-purple-50',    'filter' => \App\Models\ProductRequest::PHOTOSHOOT_SCHEDULED, 'icon' => 'M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z M15 13a3 3 0 11-6 0 3 3 0 016 0z'],
        ['key' => 'qa_review',          'label' => 'QA Review',           'hint' => 'Being checked',       'value' => $stats['qa_review'],          'tone' => 'text-sky-600 bg-sky-50',          'filter' => \App\Models\ProductRequest::QA_REVIEW,       'icon' => 'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z'],
        ['key' => 'on_hold',            'label' => 'On Hold',             'hint' => 'Blocked',             'value' => $stats['on_hold'],            'tone' => 'text-red-600 bg-red-50',          'filter' => null,                                'icon' => 'M10 9v6m4-6v6m7-3a9 9 0 11-18 0 9 9 0 0118 0z'],
        ['key' => 'published',          'label' => 'Published',           'hint' => 'Live and closed',     'value' => $stats['published'],          'tone' => 'text-emerald-600 bg-emerald-50',  'filter' => \App\Models\ProductRequest::PUBLISHED,       'icon' => 'M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9'],
    ];
@endphp

<div class="grid grid-cols-2 md:grid-cols-4 gap-3">
    @foreach($tiles as $tile)
        <a href="{{ $tile['filter'] ? route('product-requests.list', ['status' => $tile['filter']]) : route('product-requests.list') }}"
           class="bg-white rounded-xl border border-gray-200 shadow-sm px-4 py-3.5 hover:border-brand-300 hover:shadow transition-all group">
            <div class="flex items-start gap-3">
                <div class="w-9 h-9 rounded-lg flex items-center justify-center shrink-0 {{ $tile['tone'] }}">
                    <svg class="w-4.5 h-4.5" style="width:1.125rem;height:1.125rem" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $tile['icon'] }}"/>
                    </svg>
                </div>
                <div class="min-w-0">
                    <p class="text-xs font-medium text-gray-500 leading-tight">{{ $tile['label'] }}</p>
                    <p class="text-2xl font-semibold text-gray-900 leading-tight mt-0.5">{{ number_format($tile['value']) }}</p>
                    <p class="text-xs text-gray-400 truncate group-hover:text-brand-600 transition-colors">{{ $tile['hint'] }}</p>
                </div>
            </div>
        </a>
    @endforeach
</div>
