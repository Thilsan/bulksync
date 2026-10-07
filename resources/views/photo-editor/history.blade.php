@extends('layouts.app')
@section('title', 'Photo Editor History')
@section('page-title', 'Photo Editor History')

@section('content')
<div class="space-y-5">

    @if (session('success'))
    <div class="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">
        {{ session('success') }}
    </div>
    @endif


    {{-- ── Everything run so far ───────────────────────────────────────────
         Summed across every session, not the page below, so paging does not
         move the totals. --}}
    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        {{-- No "On Shopify" tile. A photo can be pushed, replaced, re-pushed
             and deleted again, so the lifetime count of push requests is not
             the number of photos on Shopify and nobody reading it would guess
             the difference. The per-session column below still shows pushed
             against edited, where the two sit side by side and the comparison
             is the point. --}}
        @php
            $found = (int) $totals->found;
            // Rates are of everything found, so the two read against the same base.
            $rate  = fn ($n) => $found > 0 ? round($n / $found * 100, 1) : 0;
        @endphp
        @foreach ([
            // key, label, number tint, icon tile, bar, icon path, note
            ['sessions', 'Sessions', 'text-gray-800',    'bg-slate-100 text-slate-600 ring-slate-200',       'bg-slate-400',
                'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2',
                'Edit runs started', null],
            ['found',    'Found',    'text-[#46668d]',   'bg-brand-50 text-brand-600 ring-brand-100',        'bg-[#6185b2]',
                'M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z',
                'Photos picked up', null],
            ['edited',   'Edited',   'text-emerald-600', 'bg-emerald-50 text-emerald-600 ring-emerald-100',  'bg-emerald-500',
                'M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L21 12l-5.714 2.143L13 21l-2.286-6.857L5 12l5.714-2.143L13 3z',
                'of found edited', $rate((int) $totals->edited)],
            ['failed',   'Failed',   (int) $totals->failed === 0 ? 'text-gray-300' : 'text-red-600',
                                     'bg-red-50 text-red-500 ring-red-100',                               'bg-red-500',
                'M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z',
                'of found failed', $rate((int) $totals->failed)],
        ] as [$key, $label, $tint, $tile, $bar, $icon, $note, $pct])
        @php $n = (int) $totals->{$key}; @endphp
        <div class="group relative overflow-hidden rounded-xl border border-gray-200 bg-white p-4 transition-transform duration-300 hover:-translate-y-0.5">
            {{-- A coloured thread along the top names the tile's kind at a glance. --}}
            <span class="absolute inset-x-0 top-0 h-[3px] {{ $bar }} opacity-80"></span>

            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <p class="text-[11px] font-semibold uppercase tracking-[.12em] text-gray-400">{{ $label }}</p>
                    <p class="figure mt-2 text-[2rem] leading-none {{ $tint }}"
                       x-data="{ v: {{ $n }} }"
                       x-init="countUp(0, {{ $n }}, x => v = x, 900)"
                       x-text="v.toLocaleString('en-US')">{{ number_format($n) }}</p>
                </div>
                <span class="grid h-10 w-10 shrink-0 place-items-center rounded-xl ring-1 ring-inset {{ $tile }} transition-transform duration-300 group-hover:scale-110 group-hover:-rotate-6">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.7">
                        <path stroke-linecap="round" stroke-linejoin="round" d="{{ $icon }}"/>
                    </svg>
                </span>
            </div>

            @if ($pct !== null)
                <div class="mt-3">
                    <div class="h-1.5 overflow-hidden rounded-full bg-gray-100">
                        <div class="h-full rounded-full {{ $bar }}" style="width: {{ min(100, $pct) }}%"></div>
                    </div>
                    <p class="mt-1.5 text-xs text-gray-500"><span class="font-semibold text-gray-700">{{ $pct }}%</span> {{ $note }}</p>
                </div>
            @else
                <p class="mt-3 text-xs text-gray-500">{{ $note }}</p>
            @endif
        </div>
        @endforeach
    </div>

    <div class="flex items-center justify-between gap-3">
        <p class="flex items-center gap-2 text-sm text-gray-500">
            <span class="inline-flex items-center rounded-full bg-brand-50 px-2.5 py-0.5 text-xs font-semibold tabular-nums text-brand-700 ring-1 ring-inset ring-brand-100">
                {{ number_format($sessions->total()) }}
            </span>
            edit session{{ $sessions->total() !== 1 ? 's' : '' }}
        </p>
        <a href="{{ route('photo-editor.index') }}"
           class="inline-flex items-center gap-1.5 rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-brand-700">
            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.4"><path stroke-linecap="round" d="M12 5v14M5 12h14"/></svg>
            New Edit
        </a>
    </div>

    <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
        @if ($sessions->isEmpty())
        <div class="px-6 py-16 text-center">
            <div class="mx-auto grid h-12 w-12 place-items-center rounded-xl bg-brand-50 text-brand-600">
                <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.6">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L21 12l-5.714 2.143L13 21l-2.286-6.857L5 12l5.714-2.143L13 3z"/>
                </svg>
            </div>
            <p class="mt-4 font-semibold text-gray-800">No edits yet</p>
            <p class="mx-auto mt-1 max-w-sm text-sm text-gray-500">
                Point the editor at a OneDrive folder, choose what to change, and review the results before
                anything reaches Shopify.
            </p>
            <a href="{{ route('photo-editor.index') }}"
               class="mt-5 inline-flex items-center gap-2 rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-brand-700">
                Start your first edit
            </a>
        </div>
        @else
        <div class="overflow-x-auto">
            <table class="w-full min-w-[60rem] text-sm">
                <thead>
                    <tr class="border-b border-gray-100 bg-gray-50/70 text-[11px] font-semibold text-gray-400">
                        <th class="py-3 pl-6 pr-4 text-left">Session</th>
                        @if ($showOwner)
                            <th class="px-4 py-3 text-left">Run by</th>
                        @endif
                        <th class="px-3 py-3 text-center">Found</th>
                        <th class="px-3 py-3 text-center">Edited</th>
                        <th class="px-3 py-3 text-left">Pushed</th>
                        <th class="px-3 py-3 text-center">Failed</th>
                        <th class="px-4 py-3 text-left">Status</th>
                        <th class="px-4 py-3 text-left">Created</th>
                        <th class="py-3 pl-4 pr-6"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach ($sessions as $s)
                    @php
                        /*
                         * "Completed" on its own only ever meant the editing
                         * pass finished — it said nothing about whether any of
                         * those photos had actually reached Shopify yet, which
                         * is the thing a run of this list is really being
                         * checked for. A run edited an hour ago and never
                         * pushed looked identical to one that went out the
                         * same green pill as one that had.
                         *
                         * Only relevant once the editing pass is actually done
                         * and produced something to push — a run with nothing
                         * edited (everything failed, or none of it finished)
                         * has no push state worth reporting, so it keeps the
                         * plain status pill below.
                         */
                        $statusLabel = ucfirst($s->status);

                        // Written out rather than interpolated: Tailwind only ships
                        // the class names it can find as complete strings.
                        [$pill, $dot] = match ($s->status) {
                            'completed'  => ['bg-emerald-50 text-emerald-700 ring-emerald-200', 'bg-emerald-500'],
                            'processing' => ['bg-brand-50 text-brand-700 ring-brand-200',       'bg-brand-500 live-dot'],
                            'failed'     => ['bg-red-50 text-red-700 ring-red-200',             'bg-red-500'],
                            default      => ['bg-gray-50 text-gray-600 ring-gray-200',          'bg-gray-400'],
                        };

                        if ($s->status === 'completed' && $s->edited_files > 0) {
                            if ($s->pushed_files >= $s->edited_files) {
                                $statusLabel = 'Shopify Completed';
                            } else {
                                $statusLabel = 'Push to Shopify';
                                [$pill, $dot] = ['bg-amber-50 text-amber-700 ring-amber-200', 'bg-amber-500'];
                            }
                        }

                        // Share of the edited photos that have reached Shopify.
                        $pushPct = $s->edited_files > 0 ? min(100, (int) round($s->pushed_files / $s->edited_files * 100)) : 0;

                        $owner    = $s->user?->name ?? 'Deleted user';
                        $initials = collect(preg_split('/\s+/', trim($owner)))->filter()->take(2)
                            ->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)))->implode('');
                    @endphp
                    <tr class="group relative transition-colors hover:bg-brand-50/40">
                        <td class="relative py-3.5 pl-6 pr-4">
                            {{-- Accent rail on hover: which row the actions belong to. --}}
                            <span class="absolute inset-y-2 left-0 w-[3px] origin-center scale-y-0 rounded-r-full bg-[#6185b2] transition-transform duration-200 group-hover:scale-y-100"></span>
                            <div class="flex items-center gap-3">
                                <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-brand-50 text-brand-600 ring-1 ring-inset ring-brand-100">
                                    <svg class="h-4.5 w-4.5" style="width:1.1rem;height:1.1rem" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.7">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L21 12l-5.714 2.143L13 21l-2.286-6.857L5 12l5.714-2.143L13 3z"/>
                                    </svg>
                                </span>
                                <div class="min-w-0">
                                    <a href="{{ route('photo-editor.show', $s) }}"
                                       class="block max-w-[18rem] truncate font-semibold text-gray-800 transition-colors hover:text-brand-700">{{ $s->name }}</a>
                                    <p class="mt-0.5 flex max-w-[18rem] items-center gap-1.5 text-xs text-gray-400">
                                        <span class="shrink-0 rounded bg-gray-100 px-1.5 py-px text-[10px] font-medium text-gray-600">{{ $s->store?->name ?? 'No store' }}</span>
                                        <span class="truncate">{{ $s->editSummary() }}</span>
                                    </p>
                                </div>
                            </div>
                        </td>
                        {{-- Only for a super admin, whose history is everybody's
                             runs in one list. For anyone else every row is their
                             own and the column would be their name sixty times. --}}
                        @if ($showOwner)
                            <td class="px-4 py-3.5">
                                <div class="flex items-center gap-2.5">
                                    <span class="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-gradient-to-br from-[#7f9dc5] to-[#46668d] text-[11px] font-semibold text-white shadow-sm">
                                        {{ $initials ?: '?' }}
                                    </span>
                                    <div class="min-w-0">
                                        <p class="max-w-[11rem] truncate text-sm font-medium text-gray-700">{{ $owner }}</p>
                                        @if ($s->user?->email)
                                            <p class="max-w-[11rem] truncate text-xs text-gray-400">{{ $s->user->email }}</p>
                                        @endif
                                    </div>
                                </div>
                            </td>
                        @endif
                        <td class="px-3 py-3.5 text-center tabular-nums text-gray-600">{{ $s->total_files }}</td>
                        <td class="px-3 py-3.5 text-center">
                            <span class="tabular-nums font-semibold {{ $s->edited_files > 0 ? 'text-emerald-600' : 'text-gray-300' }}">{{ $s->edited_files }}</span>
                        </td>
                        <td class="px-3 py-3.5">
                            {{-- Pushed against edited: the number and how far along it is. --}}
                            <div class="w-24">
                                <p class="text-xs tabular-nums">
                                    <span class="font-semibold {{ $s->pushed_files > 0 ? 'text-brand-700' : 'text-gray-300' }}">{{ $s->pushed_files }}</span>
                                    @if ($s->edited_files > 0)
                                        <span class="text-gray-400">/ {{ $s->edited_files }}</span>
                                    @endif
                                </p>
                                @if ($s->edited_files > 0)
                                    <div class="mt-1 h-1.5 overflow-hidden rounded-full bg-gray-100">
                                        <div class="h-full rounded-full {{ $pushPct >= 100 ? 'bg-emerald-500' : 'bg-[#6185b2]' }}" style="width: {{ $pushPct }}%"></div>
                                    </div>
                                @endif
                            </div>
                        </td>
                        <td class="px-3 py-3.5 text-center">
                            @if ($s->failed_files > 0)
                                <span class="inline-flex min-w-[1.75rem] justify-center rounded-full bg-red-50 px-2 py-0.5 text-xs font-semibold tabular-nums text-red-600 ring-1 ring-inset ring-red-200">{{ $s->failed_files }}</span>
                            @else
                                <span class="tabular-nums text-gray-300">0</span>
                            @endif
                        </td>
                        <td class="px-4 py-3.5">
                            <span class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-full px-2.5 py-1 text-xs font-medium ring-1 ring-inset {{ $pill }}">
                                <span class="h-1.5 w-1.5 rounded-full {{ $dot }}"></span>
                                {{ $statusLabel }}
                            </span>
                        </td>
                        <td class="whitespace-nowrap px-4 py-3.5">
                            <p class="text-gray-700">{{ $s->created_at->format('d M Y') }}</p>
                            <p class="text-xs text-gray-400" title="{{ $s->created_at->diffForHumans() }}">{{ $s->created_at->format('H:i') }} · {{ $s->created_at->diffForHumans(null, true, true) }} ago</p>
                        </td>
                        <td class="py-3.5 pl-4 pr-6">
                            <div class="flex items-center justify-end gap-1.5">
                                <a href="{{ route('photo-editor.show', $s) }}"
                                   class="inline-flex items-center gap-1 rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-xs font-semibold text-gray-700 shadow-sm transition hover:border-[#6185b2] hover:bg-[#6185b2] hover:text-white">
                                    View
                                    <svg class="h-3 w-3 transition-transform group-hover:translate-x-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                                </a>
                                <form method="POST" action="{{ route('photo-editor.destroy', $s) }}"
                                      onsubmit="return confirm('Delete session \'{{ addslashes($s->name) }}\' and every edited file it produced?')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" title="Delete session" aria-label="Delete session {{ $s->name }}"
                                            class="grid h-8 w-8 place-items-center rounded-lg text-gray-400 transition hover:bg-red-50 hover:text-red-600">
                                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="border-t border-gray-100 px-6 py-4">
            {{ $sessions->links() }}
        </div>
        @endif
    </div>
</div>
@endsection
