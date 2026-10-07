@extends('layouts.app')
@section('title', 'Upload History')
@section('page-title', 'Upload History')

@section('content')
<div class="space-y-5">

    @if (session('success'))
    <div class="bg-green-50 border border-green-200 text-green-800 text-sm rounded-lg px-4 py-3">
        {{ session('success') }}
    </div>
    @endif

    <div class="flex items-center justify-between gap-3">
        <p class="flex items-center gap-2 text-sm text-gray-500">
            <span class="inline-flex items-center rounded-full bg-brand-50 px-2.5 py-0.5 text-xs font-semibold tabular-nums text-brand-700 ring-1 ring-inset ring-brand-100">
                {{ number_format($sessions->total()) }}
            </span>
            upload session{{ $sessions->total() !== 1 ? 's' : '' }}
        </p>
        <a href="{{ route('upload.create') }}"
           class="inline-flex items-center gap-1.5 rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-brand-700">
            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.4"><path stroke-linecap="round" d="M12 5v14M5 12h14"/></svg>
            New Upload
        </a>
    </div>

    <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
        @if ($sessions->isEmpty())
        <div class="px-6 py-16 text-center">
            <div class="mx-auto grid h-12 w-12 place-items-center rounded-xl bg-brand-50 text-brand-600">
                <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.6">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/>
                </svg>
            </div>
            <p class="mt-4 font-semibold text-gray-800">No uploads yet</p>
            <a href="{{ route('upload.create') }}"
               class="mt-5 inline-flex items-center gap-2 rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-brand-700">
                Start your first upload
            </a>
        </div>
        @else
        <div class="overflow-x-auto">
            <table class="w-full min-w-[56rem] text-sm">
                <thead>
                    <tr class="border-b border-gray-100 bg-gray-50/70 text-[11px] font-semibold text-gray-400">
                        <th class="py-3 pl-6 pr-4 text-left">Session</th>
                        <th class="px-3 py-3 text-center">Total</th>
                        <th class="px-3 py-3 text-left">Uploaded</th>
                        <th class="px-3 py-3 text-center">No match</th>
                        <th class="px-3 py-3 text-center">Failed</th>
                        <th class="px-4 py-3 text-left">Status</th>
                        <th class="px-4 py-3 text-left">Created</th>
                        <th class="py-3 pl-4 pr-6"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach ($sessions as $session)
                    @php
                        // Written out rather than interpolated: Tailwind only ships
                        // the class names it can find as complete strings.
                        [$pill, $dot] = match ($session->status) {
                            'completed'  => ['bg-emerald-50 text-emerald-700 ring-emerald-200', 'bg-emerald-500'],
                            'processing' => ['bg-brand-50 text-brand-700 ring-brand-200',       'bg-brand-500 live-dot'],
                            'failed'     => ['bg-red-50 text-red-700 ring-red-200',             'bg-red-500'],
                            default      => ['bg-gray-50 text-gray-600 ring-gray-200',          'bg-gray-400'],
                        };

                        // Share of the folder that actually reached Shopify.
                        $pct = $session->total_files > 0
                            ? min(100, (int) round($session->uploaded_files / $session->total_files * 100))
                            : 0;
                    @endphp
                    <tr class="group relative bg-white transition-colors hover:bg-[#f7f9fc]">
                        <td class="relative py-3.5 pl-6 pr-4">
                            {{-- Accent rail on hover: which row the actions belong to. --}}
                            <span class="absolute inset-y-2 left-0 w-[3px] origin-center scale-y-0 rounded-r-full bg-[#6185b2] transition-transform duration-200 group-hover:scale-y-100"></span>
                            <div class="flex items-center gap-3">
                                <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-brand-50 text-brand-600 ring-1 ring-inset ring-brand-100">
                                    <svg style="width:1.1rem;height:1.1rem" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.7">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/>
                                    </svg>
                                </span>
                                <div class="min-w-0">
                                    <a href="{{ route('upload.show', $session) }}"
                                       class="block max-w-[20rem] truncate font-semibold text-gray-800 transition-colors hover:text-brand-700">{{ $session->name }}</a>
                                    <p class="mt-0.5">
                                        <span class="rounded bg-gray-100 px-1.5 py-px text-[10px] font-medium capitalize text-gray-600">{{ $session->image_size }}</span>
                                    </p>
                                </div>
                            </div>
                        </td>
                        <td class="px-3 py-3.5 text-center tabular-nums text-gray-600">{{ number_format($session->total_files) }}</td>
                        <td class="px-3 py-3.5">
                            {{-- Uploaded against total: the number and how much of the folder it is. --}}
                            <div class="w-28">
                                <p class="text-xs tabular-nums">
                                    <span class="font-semibold {{ $session->uploaded_files > 0 ? 'text-emerald-600' : 'text-gray-300' }}">{{ number_format($session->uploaded_files) }}</span>
                                    @if ($session->total_files > 0)
                                        <span class="text-gray-400">/ {{ number_format($session->total_files) }}</span>
                                        <span class="ml-1 text-[10px] text-gray-400">{{ $pct }}%</span>
                                    @endif
                                </p>
                                @if ($session->total_files > 0)
                                    <div class="mt-1 h-1.5 overflow-hidden rounded-full bg-gray-100">
                                        <div class="h-full rounded-full {{ $pct >= 100 ? 'bg-emerald-500' : 'bg-[#6185b2]' }}" style="width: {{ $pct }}%"></div>
                                    </div>
                                @endif
                            </div>
                        </td>
                        <td class="px-3 py-3.5 text-center">
                            @if ($session->skipped_files > 0)
                                <span class="inline-flex min-w-[1.75rem] justify-center rounded-full bg-amber-50 px-2 py-0.5 text-xs font-semibold tabular-nums text-amber-700 ring-1 ring-inset ring-amber-200">{{ number_format($session->skipped_files) }}</span>
                            @else
                                <span class="tabular-nums text-gray-300">0</span>
                            @endif
                        </td>
                        <td class="px-3 py-3.5 text-center">
                            @if ($session->failed_files > 0)
                                <span class="inline-flex min-w-[1.75rem] justify-center rounded-full bg-red-50 px-2 py-0.5 text-xs font-semibold tabular-nums text-red-600 ring-1 ring-inset ring-red-200">{{ number_format($session->failed_files) }}</span>
                            @else
                                <span class="tabular-nums text-gray-300">0</span>
                            @endif
                        </td>
                        <td class="px-4 py-3.5">
                            <span class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-full px-2.5 py-1 text-xs font-medium ring-1 ring-inset {{ $pill }}">
                                <span class="h-1.5 w-1.5 rounded-full {{ $dot }}"></span>
                                {{ ucfirst($session->status) }}
                            </span>
                        </td>
                        <td class="whitespace-nowrap px-4 py-3.5">
                            <p class="text-gray-700">{{ $session->created_at->format('d M Y') }}</p>
                            <p class="text-xs text-gray-400" title="{{ $session->created_at->diffForHumans() }}">{{ $session->created_at->format('H:i') }} · {{ $session->created_at->diffForHumans(null, true, true) }} ago</p>
                        </td>
                        <td class="py-3.5 pl-4 pr-6">
                            <div class="flex items-center justify-end gap-1.5">
                                <a href="{{ route('upload.show', $session) }}"
                                   class="inline-flex items-center gap-1 rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-xs font-semibold text-gray-700 shadow-sm transition hover:border-[#6185b2] hover:bg-[#6185b2] hover:text-white">
                                    View
                                    <svg class="h-3 w-3 transition-transform group-hover:translate-x-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                                </a>
                                <form method="POST" action="{{ route('upload.destroy', $session) }}"
                                      onsubmit="return confirm('Delete session \'{{ addslashes($session->name) }}\' and all its items?')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" title="Delete session" aria-label="Delete session {{ $session->name }}"
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

        <div class="px-6 py-4 border-t border-gray-100">
            {{ $sessions->links() }}
        </div>
        @endif
    </div>

</div>
@endsection
