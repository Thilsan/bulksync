@extends('layouts.app')
@section('title', 'SKU Check Results')
@section('page-title', 'SKU Check Results')

@section('content')
<div class="max-w-3xl mx-auto space-y-6"
     x-data="skuCheckPage({{ $skuCheckSession->id }}, '{{ $skuCheckSession->status }}')"
     x-init="init()">

    {{-- Back --}}
    <a href="{{ route('sku-checker.history') }}" class="text-sm text-gray-500 hover:text-gray-700">← Back to History</a>

    {{-- Stats --}}
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="bg-white rounded-xl border border-gray-200 p-5">
            <p class="text-xs text-gray-500 mb-1">Status</p>
            <p class="text-sm font-semibold capitalize"
               :class="{'text-green-600': status==='completed','text-brand-600': status==='running','text-red-500': status==='failed','text-gray-500': status==='pending'}"
               x-text="status"></p>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 p-5">
            <p class="text-xs text-gray-500 mb-1">Total SKUs</p>
            <p class="text-2xl font-bold text-gray-800" x-text="totalSkus.toLocaleString()">{{ $skuCheckSession->total_skus }}</p>
        </div>
        <div class="bg-white rounded-xl border border-green-100 p-5">
            <p class="text-xs text-gray-500 mb-1">Available</p>
            <p class="text-2xl font-bold text-green-600" x-text="available.toLocaleString()">{{ $skuCheckSession->available_count }}</p>
        </div>
        <div class="bg-white rounded-xl border border-red-100 p-5">
            <p class="text-xs text-gray-500 mb-1">Not Available</p>
            <p class="text-2xl font-bold text-red-500" x-text="notAvailable.toLocaleString()">{{ $skuCheckSession->not_available_count }}</p>
        </div>
    </div>

    {{-- Progress bar --}}
    <div x-show="status === 'running' || status === 'pending'" class="bg-white rounded-xl border border-gray-200 p-6">
        <div class="flex items-center justify-between mb-3">
            <p class="text-sm font-medium text-gray-700">Checking SKUs in background…</p>
            <p class="text-sm text-gray-500"><span x-text="scanned.toLocaleString()"></span> / <span x-text="totalSkus.toLocaleString()"></span></p>
        </div>
        <div class="w-full bg-gray-100 rounded-full h-3 overflow-hidden">
            <div class="bg-brand-600 h-3 rounded-full transition-all duration-500" :style="'width: ' + progress + '%'"></div>
        </div>
        <p class="text-xs text-gray-400 mt-2 text-center" x-text="progress + '% complete'"></p>
    </div>

    {{-- Error --}}
    @if($skuCheckSession->status === 'failed')
    <div class="bg-red-50 border border-red-200 rounded-xl p-4 text-sm text-red-700">
        <strong>Check failed:</strong> {{ $skuCheckSession->error_message }}
    </div>
    @endif

    {{-- Download buttons --}}
    <div x-show="status === 'completed'" class="bg-white rounded-xl border border-gray-200 p-6 space-y-4">
        <p class="text-sm font-semibold text-gray-700">Download Results</p>
        <div class="flex flex-wrap gap-3">
            <a href="{{ route('sku-checker.download', $skuCheckSession) }}?filter=not_available"
               class="bg-red-50 hover:bg-red-100 text-red-700 px-4 py-2 rounded-lg text-sm font-medium border border-red-200 transition-colors flex items-center gap-2">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                </svg>
                Not Available (<span x-text="notAvailable.toLocaleString()"></span>)
            </a>
            <a href="{{ route('sku-checker.download', $skuCheckSession) }}?filter=available"
               class="bg-green-50 hover:bg-green-100 text-green-700 px-4 py-2 rounded-lg text-sm font-medium border border-green-200 transition-colors flex items-center gap-2">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                </svg>
                Available (<span x-text="available.toLocaleString()"></span>)
            </a>
            <a href="{{ route('sku-checker.download', $skuCheckSession) }}"
               class="bg-gray-800 hover:bg-gray-900 text-white px-4 py-2 rounded-lg text-sm font-medium transition-colors flex items-center gap-2">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                </svg>
                Download All
            </a>
        </div>
    </div>

</div>

<script>
function skuCheckPage(sessionId, initialStatus) {
    return {
        status:      initialStatus,
        totalSkus:   {{ $skuCheckSession->total_skus }},
        scanned:     {{ $skuCheckSession->scanned_skus }},
        available:   {{ $skuCheckSession->available_count }},
        notAvailable:{{ $skuCheckSession->not_available_count }},
        progress:    {{ $skuCheckSession->progressPercent() }},
        pollTimer:   null,

        init() {
            if (this.status !== 'completed' && this.status !== 'failed') {
                this.startPolling();
            }
        },

        startPolling() {
            this.pollTimer = setInterval(() => this.poll(), 3000);
        },

        async poll() {
            const res  = await fetch(`/sku-checker/${sessionId}/status`);
            const data = await res.json();
            this.status       = data.status;
            this.totalSkus    = data.total_skus;
            this.scanned      = data.scanned_skus;
            this.available    = data.available;
            this.notAvailable = data.not_available;
            this.progress     = data.progress;
            if (data.status === 'completed' || data.status === 'failed') {
                clearInterval(this.pollTimer);
            }
        },
    };
}
</script>
@endsection
