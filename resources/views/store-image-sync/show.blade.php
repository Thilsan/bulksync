@extends('layouts.app')
@section('title', 'Product Migration - Image — Progress')
@section('page-title', 'Product Migration - Image')

@section('content')
<div class="max-w-3xl mx-auto space-y-6"
     x-data="syncPage('{{ $token }}', '{{ $progress['status'] }}')"
     x-init="init()">

    <a href="{{ route('store-image-sync.index') }}" class="text-sm text-gray-500 hover:text-gray-700">← New Sync</a>

    {{-- Store info --}}
    @if(!empty($progress['from_store']) && !empty($progress['to_store']))
    <div class="bg-white rounded-xl border border-gray-200 px-6 py-4 flex items-center gap-3 text-sm text-gray-600">
        <span class="font-semibold text-gray-800">{{ $progress['from_store'] }}</span>
        <svg class="w-5 h-5 text-brand-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3"/>
        </svg>
        <span class="font-semibold text-gray-800">{{ $progress['to_store'] }}</span>
    </div>
    @endif

    {{-- Stats --}}
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="bg-white rounded-xl border border-gray-200 p-5">
            <p class="text-xs text-gray-500 mb-1">Status</p>
            <p class="text-sm font-semibold capitalize"
               :class="{
                   'text-green-600': status==='completed',
                   'text-brand-600': status==='running',
                   'text-red-500':   status==='failed',
                   'text-gray-500':  status==='pending'
               }"
               x-text="status"></p>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 p-5">
            <p class="text-xs text-gray-500 mb-1">Total SKUs</p>
            <p class="text-2xl font-bold text-gray-800" x-text="total.toLocaleString()">{{ $progress['total'] }}</p>
        </div>
        <div class="bg-white rounded-xl border border-green-100 p-5">
            <p class="text-xs text-gray-500 mb-1">Succeeded</p>
            <p class="text-2xl font-bold text-green-600" x-text="success.toLocaleString()">{{ $progress['success'] }}</p>
        </div>
        <div class="bg-white rounded-xl border border-red-100 p-5">
            <p class="text-xs text-gray-500 mb-1">Failed / Skipped</p>
            <p class="text-2xl font-bold text-red-500" x-text="failed.toLocaleString()">{{ $progress['failed'] }}</p>
        </div>
    </div>

    {{-- Progress bar --}}
    <div x-show="status === 'running' || status === 'pending'" class="bg-white rounded-xl border border-gray-200 p-6">
        <div class="flex items-center justify-between mb-3">
            <p class="text-sm font-medium text-gray-700">Copying images in background…</p>
            <p class="text-sm text-gray-500">
                <span x-text="processed.toLocaleString()"></span> / <span x-text="total.toLocaleString()"></span>
            </p>
        </div>
        <div class="w-full bg-gray-100 rounded-full h-3 overflow-hidden">
            <div class="bg-brand-600 h-3 rounded-full transition-all duration-500"
                 :style="'width: ' + progress + '%'"></div>
        </div>
        <p class="text-xs text-gray-400 mt-2 text-center" x-text="progress + '% complete'"></p>
    </div>

    {{-- Error --}}
    @if(($progress['status'] ?? '') === 'failed' && !empty($progress['error']))
    <div class="bg-red-50 border border-red-200 rounded-xl p-4 text-sm text-red-700">
        <strong>Sync failed:</strong> {{ $progress['error'] }}
    </div>
    @endif

    {{-- Download --}}
    <div x-show="status === 'completed'" class="bg-white rounded-xl border border-gray-200 p-6 space-y-4">
        <p class="text-sm font-semibold text-gray-700">Sync Complete — Download Result</p>
        <p class="text-sm text-gray-500">The CSV shows every SKU with how many images were copied and the status.</p>
        <a href="{{ route('store-image-sync.download', $token) }}"
           class="inline-flex items-center gap-2 bg-brand-600 hover:bg-brand-700 text-white px-5 py-2.5 rounded-lg text-sm font-semibold transition-colors">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
            </svg>
            Download Result CSV
        </a>
    </div>

</div>

<script>
function syncPage(token, initialStatus) {
    return {
        status:    initialStatus,
        total:     {{ $progress['total'] ?? 0 }},
        processed: {{ $progress['processed'] ?? 0 }},
        success:   {{ $progress['success'] ?? 0 }},
        failed:    {{ $progress['failed'] ?? 0 }},
        progress:  0,
        pollTimer: null,

        init() {
            this.progress = this.total > 0 ? Math.round((this.processed / this.total) * 100) : 0;
            if (this.status !== 'completed' && this.status !== 'failed') {
                this.startPolling();
            }
        },

        startPolling() {
            this.pollTimer = setInterval(() => this.poll(), 3000);
        },

        async poll() {
            const res  = await fetch(`/store-image-sync/${token}/status`);
            const data = await res.json();
            this.status    = data.status;
            this.total     = data.total;
            this.processed = data.processed;
            this.success   = data.success;
            this.failed    = data.failed;
            this.progress  = data.total > 0 ? Math.round((data.processed / data.total) * 100) : 0;
            if (data.status === 'completed' || data.status === 'failed') {
                clearInterval(this.pollTimer);
            }
        },
    };
}
</script>
@endsection
