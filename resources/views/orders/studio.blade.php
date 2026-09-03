{{--
    The AI Studio tab of the management dashboard: headline numbers, a
    fortnight of throughput, what is running now, the module cards and one
    merged timeline across every module.

    This is a deliberate copy of the home dashboard rather than a shared
    partial — the home screen is left exactly as it is, by request. The cost
    is that the two can drift: a change made to resources/views/dashboard.blade.php
    will not appear here unless it is made here too, and the same goes for
    App\Support\WorkspaceSummary against DashboardController.
--}}
@php
    /**
     * Tailwind's JIT cannot see classes that are built at runtime, so every tone
     * used on this page is written out in full here and looked up by key.
     */
    $tones = [
        'brand'   => ['bg' => 'bg-brand-50',   'text' => 'text-brand-600',   'fill' => 'bg-brand-500',   'ring' => 'border-brand-200'],
        'emerald' => ['bg' => 'bg-emerald-50', 'text' => 'text-emerald-600', 'fill' => 'bg-emerald-500', 'ring' => 'border-emerald-200'],
        'sky'     => ['bg' => 'bg-sky-50',     'text' => 'text-sky-600',     'fill' => 'bg-sky-500',     'ring' => 'border-sky-200'],
        'violet'  => ['bg' => 'bg-violet-50',  'text' => 'text-violet-600',  'fill' => 'bg-violet-500',  'ring' => 'border-violet-200'],
        'indigo'  => ['bg' => 'bg-indigo-50',  'text' => 'text-indigo-600',  'fill' => 'bg-indigo-500',  'ring' => 'border-indigo-200'],
        'amber'   => ['bg' => 'bg-amber-50',   'text' => 'text-amber-600',   'fill' => 'bg-amber-500',   'ring' => 'border-amber-200'],
        'rose'    => ['bg' => 'bg-rose-50',    'text' => 'text-rose-600',    'fill' => 'bg-rose-500',    'ring' => 'border-rose-200'],
        'gray'    => ['bg' => 'bg-gray-100',   'text' => 'text-gray-600',    'fill' => 'bg-gray-400',    'ring' => 'border-gray-200'],
    ];

    // Modules disagree on wording — "running" here, "processing" there, "ready"
    // and "done" both meaning finished — so every value they can hold is mapped.
    $statusPill = [
        'completed'   => 'bg-emerald-50 text-emerald-700 border-emerald-200',
        'done'        => 'bg-emerald-50 text-emerald-700 border-emerald-200',
        'ready'       => 'bg-emerald-50 text-emerald-700 border-emerald-200',
        'processing'  => 'bg-blue-50 text-blue-700 border-blue-200',
        'running'     => 'bg-blue-50 text-blue-700 border-blue-200',
        'translating' => 'bg-violet-50 text-violet-700 border-violet-200',
        'pending'     => 'bg-gray-100 text-gray-600 border-gray-200',
        'failed'      => 'bg-red-50 text-red-700 border-red-200',
    ];
@endphp

<div class="space-y-5">

    {{-- ── Greeting ─────────────────────────────────────────────────────── --}}
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h2 class="text-lg font-semibold text-gray-900">{{ $greeting }}, {{ Str::before($user->name, ' ') }}</h2>
            <p class="text-sm text-gray-500">
                {{ now()->format('l, d F Y') }}
                @if($running->isNotEmpty())
                    · <span class="text-brand-600 font-medium">{{ $running->count() }} {{ Str::plural('job', $running->count()) }} running right now</span>
                @else
                    · Nothing is running right now
                @endif
            </p>
        </div>
        {{-- No New Upload / Product Creation Requests buttons here. This is a
             management view — somewhere to read the numbers, not a place to
             start work. The home dashboard keeps both. --}}
    </div>

    {{-- ── Headline numbers ─────────────────────────────────────────────── --}}
    @if(count($headline))
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        @foreach($headline as $tile)
            @php $t = $tones[$tile['tone']] ?? $tones['gray']; @endphp
            <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-5">
                <div class="flex items-start justify-between">
                    <div class="w-10 h-10 rounded-lg {{ $t['bg'] }} {{ $t['text'] }} flex items-center justify-center">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $tile['icon'] }}"/>
                        </svg>
                    </div>
                </div>
                <p class="mt-4 text-3xl font-semibold text-gray-900 tabular-nums leading-none">{{ number_format($tile['value']) }}</p>
                <p class="mt-1.5 text-sm font-medium text-gray-700">{{ $tile['label'] }}</p>
                <p class="text-xs text-gray-400 mt-0.5">{{ $tile['note'] }}</p>
            </div>
        @endforeach
    </div>
    @endif

    {{-- No workload chart and no "running now" panel here. Both answer
         "what is the studio doing this minute", which belongs on the home
         dashboard where the work is started — this screen is for reading
         the numbers. The home dashboard keeps both. --}}

    {{-- ── Module cards ─────────────────────────────────────────────────── --}}
    @if(count($modules))
    <div>
        <div class="flex items-center justify-between mb-3">
            <h3 class="text-sm font-semibold text-gray-800">Your modules</h3>
            <p class="text-xs text-gray-400">{{ count($modules) }} {{ Str::plural('tool', count($modules)) }} enabled on this account</p>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
            @foreach($modules as $module)
                @php $t = $tones[$module['tone']] ?? $tones['gray']; @endphp
                <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-5 flex flex-col">
                    <div class="flex items-start gap-3">
                        <div class="w-9 h-9 rounded-lg {{ $t['bg'] }} {{ $t['text'] }} flex items-center justify-center shrink-0">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $module['icon'] }}"/>
                            </svg>
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2">
                                <h4 class="text-sm font-semibold text-gray-800 truncate">{{ $module['name'] }}</h4>
                                @if($module['running'] > 0)
                                    <span class="shrink-0 text-[10px] font-medium px-1.5 py-0.5 rounded-full {{ $t['bg'] }} {{ $t['text'] }}">
                                        {{ $module['running'] }} active
                                    </span>
                                @endif
                            </div>
                            <p class="text-xs text-gray-400 leading-snug mt-0.5">{{ $module['blurb'] }}</p>
                        </div>
                    </div>

                    <div class="grid grid-cols-3 gap-2 mt-4">
                        @foreach($module['metrics'] as [$label, $value])
                            <div class="rounded-lg bg-gray-50 px-2.5 py-2">
                                <p class="text-base font-semibold text-gray-900 tabular-nums leading-none">{{ $value }}</p>
                                <p class="text-[11px] text-gray-500 mt-1 truncate">{{ $label }}</p>
                            </div>
                        @endforeach
                    </div>

                    <div class="mt-4">
                        <div class="h-1.5 rounded-full bg-gray-100 overflow-hidden">
                            <div class="h-full {{ $t['fill'] }} rounded-full" style="width: {{ $module['bar'] }}%"></div>
                        </div>
                        <p class="text-[11px] text-gray-400 mt-1.5">{{ $module['barNote'] }}</p>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
    @endif

    {{-- No product creation pipeline here. The Orders tab already carries it,
         scoped to the chosen date range, and the home dashboard carries the
         live version — a third copy on this tab would be the same work counted
         a third way on the same screen. --}}

    {{-- ── Timeline + right rail ────────────────────────────────────────── --}}
    <div class="grid grid-cols-1 xl:grid-cols-3 gap-5 items-start">

        {{-- Merged activity across every module --}}
        <div class="xl:col-span-2 bg-white rounded-xl border border-gray-200 shadow-sm">
            <div class="px-5 py-3.5 border-b border-gray-100">
                <h3 class="text-sm font-semibold text-gray-800">Recent activity</h3>
                <p class="text-xs text-gray-400">Every module, newest first</p>
            </div>

            @if($feed->isEmpty())
                <div class="px-5 py-12 text-center">
                    <p class="text-sm text-gray-500">Nothing has run yet.</p>
                    <p class="text-xs text-gray-400 mt-1">Start a job from any module and it will show up here.</p>
                </div>
            @else
                <ul class="divide-y divide-gray-50">
                    @foreach($feed as $event)
                        @php $t = $tones[$event['tone']] ?? $tones['gray']; @endphp
                        <li>
                            <div class="flex items-start gap-3 px-5 py-3">
                                <span class="w-7 h-7 rounded-lg {{ $t['bg'] }} {{ $t['text'] }} flex items-center justify-center shrink-0 mt-0.5">
                                    <span class="w-1.5 h-1.5 rounded-full {{ $t['fill'] }}"></span>
                                </span>
                                <div class="min-w-0 flex-1">
                                    <div class="flex items-center gap-2 flex-wrap">
                                        <p class="text-sm font-medium text-gray-800 truncate">{{ $event['title'] }}</p>
                                        <span class="text-[10px] uppercase tracking-wide {{ $t['text'] }}">{{ $event['module'] }}</span>
                                    </div>
                                    <p class="text-xs text-gray-400 mt-0.5">{{ $event['detail'] }}</p>
                                </div>
                                <div class="text-right shrink-0">
                                    <span class="inline-block text-[11px] font-medium px-2 py-0.5 rounded-full border {{ $statusPill[$event['status']] ?? $statusPill['pending'] }}">
                                        {{ ucfirst($event['status']) }}
                                    </span>
                                    <p class="text-[11px] text-gray-400 mt-1">
                                        {{ $event['at']->diffForHumans(null, true) }} ago
                                        @if($user->is_super_admin && $event['who'])
                                            · {{ Str::before($event['who'], ' ') }}
                                        @endif
                                    </p>
                                </div>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>

        {{-- Right rail: connections, stores, team --}}
        <div class="space-y-5">

            {{-- Integration health --}}
            <div class="bg-white rounded-xl border border-gray-200 shadow-sm">
                <div class="px-5 py-3.5 border-b border-gray-100">
                    <h3 class="text-sm font-semibold text-gray-800">Connections</h3>
                </div>
                <ul class="divide-y divide-gray-50">
                    @foreach($health as $item)
                        <li class="px-5 py-2.5 flex items-center gap-3">
                            <span class="w-2 h-2 rounded-full shrink-0 {{ $item['ok'] ? 'bg-emerald-500' : 'bg-amber-400' }}"></span>
                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-medium text-gray-800">{{ $item['name'] }}</p>
                                <p class="text-xs text-gray-400 truncate">{{ $item['detail'] }}</p>
                            </div>
                            @if(! $item['ok'])
                            @endif
                        </li>
                    @endforeach
                </ul>
            </div>

            {{-- Stores --}}
            <div class="bg-white rounded-xl border border-gray-200 shadow-sm">
                <div class="px-5 py-3.5 border-b border-gray-100 flex items-center justify-between">
                    <h3 class="text-sm font-semibold text-gray-800">Stores</h3>
                </div>
                @if($stores->isEmpty())
                    <p class="px-5 py-6 text-sm text-gray-400 text-center">No store connected yet.</p>
                @else
                    <ul class="divide-y divide-gray-50">
                        @foreach($stores as $row)
                            <li class="px-5 py-3">
                                <div class="flex items-center gap-2">
                                    <p class="text-sm font-medium text-gray-800 truncate">{{ $row['model']->name }}</p>
                                    @if($row['active'])
                                        <span class="text-[10px] font-medium px-1.5 py-0.5 rounded-full bg-brand-50 text-brand-600 shrink-0">Active</span>
                                    @endif
                                    <span class="ml-auto w-2 h-2 rounded-full shrink-0 {{ $row['connected'] ? 'bg-emerald-500' : 'bg-red-400' }}"
                                          title="{{ $row['connected'] ? 'Access token present' : 'Not authenticated' }}"></span>
                                </div>
                                <p class="text-xs text-gray-400 truncate">{{ $row['model']->shopify_domain }}</p>
                                <div class="flex items-center gap-3 mt-1.5 text-[11px] text-gray-500">
                                    <span>{{ number_format($row['uploads']) }} uploads</span>
                                    <span>{{ number_format($row['checks']) }} checks</span>
                                    <span>{{ number_format($row['requests']) }} requests</span>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            {{-- Team, super admin only --}}
            @if($team->isNotEmpty())
            <div class="bg-white rounded-xl border border-gray-200 shadow-sm">
                <div class="px-5 py-3.5 border-b border-gray-100 flex items-center justify-between">
                    <h3 class="text-sm font-semibold text-gray-800">Team</h3>
                </div>
                <ul class="divide-y divide-gray-50">
                    @foreach($team as $member)
                        <li class="px-5 py-2.5 flex items-center gap-3">
                            <span class="w-7 h-7 rounded-full bg-gray-100 text-gray-600 text-[11px] font-semibold flex items-center justify-center shrink-0">
                                {{ Str::of($member['name'])->explode(' ')->take(2)->map(fn ($p) => Str::substr($p, 0, 1))->implode('') }}
                            </span>
                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-medium text-gray-800 truncate">{{ $member['name'] }}</p>
                                <p class="text-xs text-gray-400 truncate">{{ $member['role'] }}</p>
                            </div>
                            <p class="text-[11px] text-gray-400 shrink-0 text-right">
                                @if(! $member['active'])
                                    <span class="text-red-500">Disabled</span>
                                @elseif($member['seen_at'])
                                    {{ $member['seen_at']->diffForHumans(null, true) }} ago
                                @else
                                    Never signed in
                                @endif
                            </p>
                        </li>
                    @endforeach
                </ul>
            </div>
            @endif
        </div>
    </div>

</div>
