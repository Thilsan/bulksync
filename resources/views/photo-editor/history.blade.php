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
