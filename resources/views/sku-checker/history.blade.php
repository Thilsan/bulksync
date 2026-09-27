@extends('layouts.app')
@section('title', 'SKU Check History')
@section('page-title', 'SKU Check History')

@section('content')
<div class="space-y-5">

    <div class="flex items-center justify-end">
        <a href="{{ route('sku-checker.index') }}"
           class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-brand-700">
            New check
        </a>
    </div>

    <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
        @if($sessions->isEmpty())
            <div class="px-6 py-20 text-center">
                <p class="font-display text-xl text-gray-900">Nothing checked yet</p>
                <a href="{{ route('sku-checker.index') }}"
                   class="mt-2 inline-block text-sm font-semibold text-brand-600 transition-colors hover:text-brand-800">
                    Run your first check →
                </a>
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-100 bg-gray-50/60 text-left">
                            <th class="px-5 py-3 text-[11px] font-semibold uppercase tracking-[.12em] text-gray-400">Run</th>
                            <th class="px-5 py-3 text-[11px] font-semibold uppercase tracking-[.12em] text-gray-400">Store</th>
                            <th class="px-5 py-3 text-right text-[11px] font-semibold uppercase tracking-[.12em] text-gray-400">SKUs</th>
                            <th class="px-5 py-3 text-[11px] font-semibold uppercase tracking-[.12em] text-gray-400">Coverage</th>
                            <th class="px-5 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-50">
                        @foreach($sessions as $session)
                            @php
                                $status = $session->status ?? 'completed';
                                $live   = in_array($status, ['running', 'pending'], true);
                                $pct    = $status === 'completed' && $session->total_skus > 0
                                    ? (int) round($session->available_count / $session->total_skus * 100)
                                    : null;
                            @endphp
                            <tr class="group transition-colors hover:bg-gray-50/70">
                                <td class="px-5 py-3.5">
                                    <a href="{{ route('sku-checker.show', $session) }}" class="flex items-center gap-2.5">
                                        <span class="h-1.5 w-1.5 shrink-0 rounded-full
                                            {{ $live ? 'pulse-dot bg-brand-500 text-brand-500'
                                                     : ($status === 'failed' ? 'bg-red-400' : 'bg-emerald-400') }}"></span>
                                        <span>
                                            <span class="block text-gray-800">{{ $session->created_at->format('d M Y') }}</span>
                                            <span class="block text-xs text-gray-400">{{ $session->created_at->format('h:i A') }}</span>
                                        </span>
                                    </a>
                                </td>
                                <td class="px-5 py-3.5 text-gray-500">{{ $session->store?->name ?? '—' }}</td>
                                <td class="px-5 py-3.5 text-right">
                                    <span class="figure text-base text-gray-900">{{ number_format($session->total_skus) }}</span>
                                </td>
                                <td class="px-5 py-3.5">
                                    @if($pct !== null)
                                        <div class="flex items-center gap-3">
                                            <div class="h-1.5 w-28 overflow-hidden rounded-full bg-gray-100">
                                                <div class="h-1.5 rounded-full bg-emerald-500" style="width: {{ $pct }}%"></div>
                                            </div>
                                            <span class="figure text-sm text-gray-700">{{ $pct }}%</span>
                                            @if($session->not_available_count > 0)
                                                <span class="text-xs text-red-500">
                                                    {{ number_format($session->not_available_count) }} missing
                                                </span>
                                            @endif
                                        </div>
                                    @else
                                        <span class="text-xs font-medium capitalize text-gray-400">{{ $status }}</span>
                                    @endif
                                </td>
                                <td class="px-5 py-3.5">
                                    <div class="flex items-center justify-end gap-1 opacity-60 transition-opacity group-hover:opacity-100">
                                        <a href="{{ route('sku-checker.show', $session) }}"
                                           class="rounded-md px-2.5 py-1.5 text-xs font-semibold text-brand-600 transition-colors hover:bg-brand-50">Open</a>
                                        <a href="{{ route('sku-checker.download', $session) }}"
                                           class="rounded-md px-2.5 py-1.5 text-xs font-medium text-gray-500 transition-colors hover:bg-gray-100 hover:text-gray-700">CSV</a>
                                        <form method="POST" action="{{ route('sku-checker.destroy', $session) }}"
                                              onsubmit="return confirm('Delete this check and its result files?')">
                                            @csrf @method('DELETE')
                                            <button type="submit"
                                                    class="rounded-md px-2.5 py-1.5 text-xs font-medium text-gray-400 transition-colors hover:bg-red-50 hover:text-red-600">
                                                Delete
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if($sessions->hasPages())
                <div class="border-t border-gray-100 px-5 py-4">{{ $sessions->links() }}</div>
            @endif
        @endif
    </div>
</div>
@endsection
