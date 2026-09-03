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

    {{-- ── What the allowance has gone on ──────────────────────────────
         The number that decides whether the next run can happen at all, so it
         leads. Everything below it is history; this is the only figure on the
         page that constrains what you do next. --}}
    <div class="rounded-xl border border-gray-200 bg-white px-5 py-4">
        <div class="flex flex-wrap items-baseline justify-between gap-2">
            <h2 class="text-sm font-semibold text-gray-800">
                Photoroom {{ $allowance['is_sandbox'] ? 'sandbox allowance' : 'monthly allowance' }}
            </h2>
            <p class="text-xs text-gray-500">
                @if ($allowance['is_sandbox'])
                    Rolling 24 hours — capacity returns as each edit ages out
                @else
                    Resets {{ $allowance['resets_on']->format('D d M Y') }}
                @endif
            </p>
        </div>

        <div class="mt-3 grid grid-cols-3 gap-4">
            <div>
                <p class="text-2xl font-semibold tabular-nums text-gray-900">{{ number_format($allowance['spent']) }}</p>
                <p class="text-xs text-gray-500">Credits used</p>
            </div>
            <div>
                <p class="text-2xl font-semibold tabular-nums {{ $allowance['left'] ? 'text-emerald-600' : 'text-red-600' }}">
                    {{ number_format($allowance['left']) }}
                </p>
                <p class="text-xs text-gray-500">Left</p>
            </div>
            <div>
                <p class="text-2xl font-semibold tabular-nums text-gray-400">{{ number_format($allowance['quota']) }}</p>
                <p class="text-xs text-gray-500">Total</p>
            </div>
        </div>

        <div class="mt-3 h-1.5 overflow-hidden rounded-full bg-gray-100">
            <div class="h-full rounded-full {{ $allowance['percent_used'] >= 90 ? 'bg-red-500' : ($allowance['percent_used'] >= 70 ? 'bg-amber-500' : 'bg-brand-500') }}"
                 style="width: {{ max(2, $allowance['percent_used']) }}%"></div>
        </div>

        <p class="mt-2 text-xs text-gray-400">
            Counted from this app's own edits, so treat it as a minimum.
            @if ($allowance['charged_failures'])
                Includes {{ $allowance['charged_failures'] }} failed {{ Str::plural('edit', $allowance['charged_failures']) }} that still cost a request.
            @endif
        </p>
    </div>

    {{-- ── Everything run so far ───────────────────────────────────────────
         Summed across every session, not the page below, so paging does not
         move the totals. --}}
    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
        @foreach ([
            ['sessions', 'Sessions',   'gray'],
            ['found',    'Found',      'gray'],
            ['edited',   'Edited',     'emerald'],
            ['pushed',   'On Shopify', 'brand'],
            ['failed',   'Failed',     'red'],
        ] as [$key, $label, $color])
        <div class="rounded-xl border border-gray-200 bg-white p-4">
            <p class="text-xs font-medium uppercase tracking-wider text-gray-400">{{ $label }}</p>
            <p class="mt-1.5 text-2xl font-semibold tabular-nums {{ (int) $totals->{$key} === 0 && $color === 'red' ? 'text-gray-300' : 'text-' . $color . '-600' }}">
                {{ number_format((int) $totals->{$key}) }}
            </p>
        </div>
        @endforeach
    </div>

    <div class="flex items-center justify-between">
        <p class="text-sm text-gray-500">
            {{ $sessions->total() }} edit session{{ $sessions->total() !== 1 ? 's' : '' }}
        </p>
        <a href="{{ route('photo-editor.index') }}"
           class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-brand-700">
            + New Edit
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
            <table class="w-full min-w-[52rem] text-sm">
                <thead class="bg-gray-50 text-xs uppercase tracking-wide text-gray-500">
                    <tr>
                        <th class="px-6 py-3 text-left">Session</th>
                        <th class="px-6 py-3 text-center">Found</th>
                        <th class="px-6 py-3 text-center">Edited</th>
                        <th class="px-6 py-3 text-center">Pushed</th>
                        <th class="px-6 py-3 text-center">Failed</th>
                        <th class="px-6 py-3 text-left">Status</th>
                        <th class="px-6 py-3 text-left">Created</th>
                        <th class="px-6 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach ($sessions as $s)
                    @php
                        // Written out rather than interpolated: Tailwind only ships
                        // the class names it can find as complete strings.
                        $pill = match ($s->status) {
                            'completed'  => 'bg-emerald-100 text-emerald-700',
                            'processing' => 'bg-brand-100 text-brand-700',
                            'failed'     => 'bg-red-100 text-red-700',
                            default      => 'bg-gray-100 text-gray-600',
                        };
                    @endphp
                    <tr class="transition-colors hover:bg-gray-50">
                        <td class="px-6 py-3">
                            <p class="max-w-[18rem] truncate font-medium text-gray-800">{{ $s->name }}</p>
                            <p class="mt-0.5 max-w-[18rem] truncate text-xs text-gray-400">
                                {{ $s->store?->name ?? 'No store' }} &middot; {{ $s->editSummary() }}
                            </p>
                        </td>
                        <td class="px-6 py-3 text-center tabular-nums text-gray-700">{{ $s->total_files }}</td>
                        <td class="px-6 py-3 text-center font-semibold tabular-nums text-emerald-700">{{ $s->edited_files }}</td>
                        <td class="px-6 py-3 text-center font-semibold tabular-nums {{ $s->pushed_files > 0 ? 'text-brand-700' : 'text-gray-300' }}">{{ $s->pushed_files }}</td>
                        <td class="px-6 py-3 text-center tabular-nums {{ $s->failed_files > 0 ? 'text-red-600' : 'text-gray-300' }}">{{ $s->failed_files }}</td>
                        <td class="px-6 py-3">
                            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium {{ $pill }}">
                                {{ ucfirst($s->status) }}
                            </span>
                        </td>
                        <td class="whitespace-nowrap px-6 py-3 text-gray-500">{{ $s->created_at->format('d M Y H:i') }}</td>
                        <td class="px-6 py-3">
                            <div class="flex items-center gap-3">
                                <a href="{{ route('photo-editor.show', $s) }}" class="text-xs font-medium text-brand-600 hover:underline">View →</a>
                                <form method="POST" action="{{ route('photo-editor.destroy', $s) }}"
                                      onsubmit="return confirm('Delete session \'{{ addslashes($s->name) }}\' and every edited file it produced?')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="text-xs font-medium text-red-500 transition-colors hover:text-red-700">Delete</button>
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
