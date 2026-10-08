{{-- Photoroom's own figure, or nothing at all.

     An earlier version of this card counted the app's own rows and showed
     "75 left" on the morning Photoroom began refusing everything — it could
     not see edits made in Photoroom's web app or by another holder of the
     key, and the plan size was an env var typed in by hand. A run was planned
     against it. So this one asks Photoroom, and when Photoroom cannot be
     reached it renders nothing rather than guessing. --}}
@if (!empty($usage))
    @php
        $pct = $usage['subscription'] > 0
            ? min(100, round($usage['used'] / $usage['subscription'] * 100))
            : 0;
    @endphp

    <div class="mb-5 rounded-xl border border-gray-200 bg-white px-5 py-4">
        <div class="flex flex-wrap items-baseline justify-between gap-2">
            <p class="text-[11px] font-medium uppercase tracking-[.12em] text-gray-400">
                Photoroom plan{{ $usage['plan'] ? ' · ' . ucfirst($usage['plan']) : '' }}
            </p>
            <p class="text-xs text-gray-400">Reported by Photoroom</p>
        </div>

        <div class="mt-3 grid grid-cols-3 gap-4">
            <div>
                <p class="figure text-3xl leading-none text-gray-900">{{ number_format($usage['used']) }}</p>
                <p class="text-xs text-gray-500">Used</p>
            </div>
            <div>
                <p class="figure text-3xl leading-none {{ $usage['available'] > 0 ? 'text-emerald-600' : 'text-red-600' }}">
                    {{ number_format($usage['available']) }}
                </p>
                <p class="text-xs text-gray-500">Left</p>
            </div>
            <div>
                <p class="figure text-3xl leading-none text-gray-400">{{ number_format($usage['subscription']) }}</p>
                <p class="text-xs text-gray-500">This month</p>
            </div>
        </div>

        <div class="mt-3 h-1.5 overflow-hidden rounded-full bg-gray-100">
            <div class="h-full rounded-full {{ $pct >= 90 ? 'bg-red-500' : ($pct >= 70 ? 'bg-amber-500' : 'bg-brand-500') }}"
                 style="width: {{ max(2, $pct) }}%"></div>
        </div>
    </div>
@endif
