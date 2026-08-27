@extends('layouts.app')
@section('title', 'Queues')
@section('page-title', 'Queues')

@section('content')
<div class="max-w-5xl mx-auto space-y-8" x-data="queueDashboard()" x-init="poll()">

    @if(session('success'))
    <div class="bg-green-50 border border-green-200 rounded-xl px-5 py-3 text-sm text-green-700">
        {{ session('success') }}
    </div>
    @endif

    @if(session('error'))
    <div class="bg-red-50 border border-red-200 rounded-xl px-5 py-3 text-sm text-red-700">
        {{ session('error') }}
    </div>
    @endif

    {{-- ── Queue depth ───────────────────────────────────────────────── --}}
    <div>
        <div class="flex items-center justify-between mb-3">
            <h2 class="text-base font-semibold text-gray-800">Waiting to run</h2>
            <span class="text-xs text-gray-400" x-text="'driver: ' + driver + ' · refreshed ' + refreshed"></span>
        </div>

        <div class="grid grid-cols-3 gap-4">
            <template x-for="q in queues" :key="q.name">
                <div class="bg-white rounded-xl border border-gray-200 px-5 py-4">
                    <p class="text-xs font-medium text-gray-500 uppercase tracking-wide" x-text="q.name"></p>
                    <p class="mt-1 text-2xl font-semibold text-gray-900" x-show="q.error === null" x-text="q.size"></p>
                    <p class="mt-1 text-xs text-red-600" x-show="q.error !== null" x-text="q.error"></p>
                </div>
            </template>
        </div>
    </div>

    {{-- ── Workers ───────────────────────────────────────────────────── --}}
    <div>
        <div class="flex items-center justify-between mb-3">
            <h2 class="text-base font-semibold text-gray-800">Workers</h2>
            <form method="POST" action="{{ route('super-admin.queues.restart') }}"
                  onsubmit="return confirm('Restart all queue workers? Each finishes the job it is holding first, so nothing in flight is lost.')">
                @csrf
                <button type="submit"
                    class="text-sm bg-brand-600 hover:bg-brand-700 text-white px-3 py-1.5 rounded-lg transition-colors font-medium">
                    Restart workers
                </button>
            </form>
        </div>

        {{-- A worker holds the code it started with, so age is the thing to read
             here: one older than the last deploy is still running the old code. --}}
        <div x-show="workers.some(w => w.stale)" x-cloak
             class="mb-3 bg-amber-50 border border-amber-200 rounded-xl px-5 py-3 text-sm text-amber-800">
            A worker has been running for over a day. Workers keep the code they
            started with in memory, so if you have deployed since, it is still
            running the old version — restart them.
        </div>

        <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
            <template x-if="!workersAvailable">
                <p class="px-5 py-4 text-sm text-gray-500">
                    Process listing is not available on this host, so worker ages cannot be shown.
                </p>
            </template>

            <template x-if="workersAvailable && workers.length === 0">
                <p class="px-5 py-4 text-sm text-red-600">
                    No workers are running. Nothing queued will be processed until they start.
                </p>
            </template>

            <template x-for="(w, i) in workers" :key="i">
                <div class="px-5 py-3 flex items-center justify-between border-b border-gray-100 last:border-0">
                    <span class="text-sm text-gray-700" x-text="w.queue"></span>
                    <span class="text-sm" :class="w.stale ? 'text-amber-600 font-medium' : 'text-gray-500'"
                          x-text="'up ' + w.age"></span>
                </div>
            </template>
        </div>
    </div>

    {{-- ── Sessions that stopped short ───────────────────────────────── --}}
    <div x-show="sessions.length > 0" x-cloak>
        <h2 class="text-base font-semibold text-gray-800 mb-3">AI content sessions to pick up</h2>

        <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
            <template x-for="s in sessions" :key="s.id">
                <div class="px-5 py-4 border-b border-gray-100 last:border-0">
                    <div class="flex items-start justify-between gap-4">
                        <div class="min-w-0">
                            <div class="flex items-center gap-2">
                                <span class="text-sm font-medium text-gray-800" x-text="'#' + s.id"></span>
                                <span class="text-[11px] px-2 py-0.5 rounded-full font-medium"
                                      :class="{
                                        'bg-red-100 text-red-700':    s.status === 'failed',
                                        'bg-blue-100 text-blue-700':  s.status === 'processing',
                                        'bg-gray-100 text-gray-600':  s.status === 'pending',
                                      }" x-text="s.status"></span>
                                <span class="text-xs text-gray-500" x-text="s.processed + '/' + s.total"></span>
                                <span class="text-xs text-gray-400" x-text="s.updated"></span>
                            </div>
                            <p class="mt-1 text-xs text-gray-500 truncate" x-show="s.error" x-text="s.error"></p>
                        </div>

                        {{-- Resuming skips whatever already generated, so it only
                             pays for the SKUs that never finished. --}}
                        <form method="POST" :action="'{{ url('super-admin/queues/sessions') }}/' + s.id + '/resume'"
                              onsubmit="return confirm('Resume this session? Finished SKUs are skipped, so only the unfinished ones are generated.')">
                            @csrf
                            <button type="submit"
                                class="shrink-0 text-sm border border-gray-300 hover:bg-gray-50 text-gray-700 px-3 py-1.5 rounded-lg transition-colors font-medium">
                                Resume
                            </button>
                        </form>
                    </div>
                </div>
            </template>
        </div>
    </div>

    {{-- ── Failed jobs ───────────────────────────────────────────────── --}}
    <div>
        <div class="flex items-center justify-between mb-3">
            <h2 class="text-base font-semibold text-gray-800">
                Failed jobs <span class="text-gray-400 font-normal" x-text="failed.length ? '(' + failed.length + ')' : ''"></span>
            </h2>

            <div class="flex items-center gap-2" x-show="failed.length > 0" x-cloak>
                <form method="POST" action="{{ route('super-admin.queues.failed.retry') }}"
                      onsubmit="return confirm('Push every failed job back onto its queue?')">
                    @csrf
                    <button type="submit"
                        class="text-sm border border-gray-300 hover:bg-gray-50 text-gray-700 px-3 py-1.5 rounded-lg transition-colors font-medium">
                        Retry all
                    </button>
                </form>
                <form method="POST" action="{{ route('super-admin.queues.failed.forget') }}"
                      onsubmit="return confirm('Delete the whole failed job list? This cannot be undone.')">
                    @csrf
                    <button type="submit"
                        class="text-sm border border-red-200 text-red-600 hover:bg-red-50 px-3 py-1.5 rounded-lg transition-colors font-medium">
                        Clear all
                    </button>
                </form>
            </div>
        </div>

        <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
            <template x-if="failed.length === 0">
                <p class="px-5 py-4 text-sm text-gray-500">Nothing has failed.</p>
            </template>

            <template x-for="f in failed" :key="f.uuid">
                <div class="px-5 py-4 border-b border-gray-100 last:border-0">
                    <div class="flex items-start justify-between gap-4">
                        <div class="min-w-0">
                            <div class="flex items-center gap-2">
                                <span class="text-sm font-medium text-gray-800" x-text="f.name"></span>
                                <span class="text-[11px] px-2 py-0.5 rounded-full bg-gray-100 text-gray-600" x-text="f.queue"></span>
                                <span class="text-xs text-gray-400" x-text="f.failed_at"></span>
                            </div>
                            <p class="mt-1 text-xs text-red-600 break-words" x-text="f.reason"></p>
                        </div>

                        <div class="flex items-center gap-2 shrink-0">
                            <form method="POST" action="{{ route('super-admin.queues.failed.retry') }}">
                                @csrf
                                <input type="hidden" name="uuid" :value="f.uuid">
                                <button type="submit"
                                    class="text-sm border border-gray-300 hover:bg-gray-50 text-gray-700 px-3 py-1.5 rounded-lg transition-colors font-medium">
                                    Retry
                                </button>
                            </form>
                            <form method="POST" action="{{ route('super-admin.queues.failed.forget') }}"
                                  onsubmit="return confirm('Delete this failed job?')">
                                @csrf
                                <input type="hidden" name="uuid" :value="f.uuid">
                                <button type="submit"
                                    class="text-sm text-gray-400 hover:text-red-600 px-2 py-1.5 transition-colors">
                                    Delete
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </template>
        </div>
    </div>
</div>

<script>
function queueDashboard() {
    return {
        queues:           @json($queues),
        workers:          @json($workers['list']),
        workersAvailable: @json($workers['available']),
        failed:           @json($failed),
        sessions:         @json($sessions),
        driver:           @json($driver),
        refreshed:        'just now',

        /*
         * The numbers are only useful if they move on their own — the whole point
         * is watching a queue drain without hammering F5. Five seconds is often
         * enough to see a job picked up, and the payload is small.
         */
        poll() {
            setInterval(async () => {
                try {
                    const res  = await fetch('{{ route('super-admin.queues.status') }}', {
                        headers: { 'Accept': 'application/json' },
                    });
                    if (!res.ok) return;

                    const data = await res.json();

                    this.queues           = data.queues;
                    this.workers          = data.workers.list;
                    this.workersAvailable = data.workers.available;
                    this.failed           = data.failed;
                    this.sessions         = data.sessions;
                    this.driver           = data.driver;
                    this.refreshed        = new Date().toLocaleTimeString();
                } catch (e) {
                    // A dropped poll is not worth surfacing; the next one will land.
                }
            }, 5000);
        },
    };
}
</script>
@endsection
