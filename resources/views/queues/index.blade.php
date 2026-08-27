@extends('layouts.app')
@section('title', 'Queues')
@section('page-title', 'Queues')

@section('content')
<div class="max-w-6xl mx-auto space-y-6" x-data="queueDashboard()" x-init="start()">

    @if(session('success'))
    <div class="bg-green-50 border border-green-200 rounded-xl px-5 py-3 text-sm text-green-700">{{ session('success') }}</div>
    @endif

    @if(session('error'))
    <div class="bg-red-50 border border-red-200 rounded-xl px-5 py-3 text-sm text-red-700">{{ session('error') }}</div>
    @endif

    {{-- ── Headline numbers ──────────────────────────────────────────── --}}
    <div class="grid grid-cols-2 md:grid-cols-5 gap-3">
        <div class="bg-white rounded-xl border border-gray-200 px-4 py-3">
            <p class="text-[11px] font-medium text-gray-500 uppercase tracking-wide">Running now</p>
            <p class="mt-1 text-2xl font-semibold" :class="running.length ? 'text-blue-600' : 'text-gray-900'" x-text="running.length"></p>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 px-4 py-3">
            <p class="text-[11px] font-medium text-gray-500 uppercase tracking-wide">Waiting</p>
            <p class="mt-1 text-2xl font-semibold text-gray-900" x-text="totalWaiting"></p>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 px-4 py-3">
            <p class="text-[11px] font-medium text-gray-500 uppercase tracking-wide">Done 24h</p>
            <p class="mt-1 text-2xl font-semibold text-green-600" x-text="today.completed"></p>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 px-4 py-3">
            <p class="text-[11px] font-medium text-gray-500 uppercase tracking-wide">Failed 24h</p>
            <p class="mt-1 text-2xl font-semibold" :class="today.failed ? 'text-red-600' : 'text-gray-900'" x-text="today.failed"></p>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 px-4 py-3">
            {{-- Killed outright, so it never recorded its own ending. --}}
            <p class="text-[11px] font-medium text-gray-500 uppercase tracking-wide">Lost 24h</p>
            <p class="mt-1 text-2xl font-semibold" :class="today.lost ? 'text-amber-600' : 'text-gray-900'" x-text="today.lost"></p>
        </div>
    </div>

    {{-- ── Tabs ──────────────────────────────────────────────────────── --}}
    <div class="flex items-center justify-between border-b border-gray-200">
        <nav class="flex gap-1 -mb-px">
            <template x-for="t in ['overview','running','history','failed','logs']" :key="t">
                <button type="button" @click="go(t)"
                    class="px-4 py-2 text-sm font-medium border-b-2 transition-colors capitalize"
                    :class="tab === t ? 'border-brand-600 text-brand-700' : 'border-transparent text-gray-500 hover:text-gray-700'">
                    <span x-text="t"></span>
                    <span x-show="t === 'failed' && failedTotal" class="ml-1 text-[11px] px-1.5 py-0.5 rounded-full bg-red-100 text-red-700" x-text="failedTotal"></span>
                </button>
            </template>
        </nav>
        <span class="text-xs text-gray-400 pb-2" x-text="driver + ' · ' + refreshed"></span>
    </div>

    {{-- ── Overview ──────────────────────────────────────────────────── --}}
    <div x-show="tab === 'overview'" x-cloak class="space-y-6">
        <div>
            <h2 class="text-sm font-semibold text-gray-800 mb-2">Waiting per queue</h2>
            <div class="grid grid-cols-3 gap-3">
                <template x-for="q in queues" :key="q.name">
                    <div class="bg-white rounded-xl border border-gray-200 px-4 py-3">
                        <p class="text-xs font-medium text-gray-500" x-text="q.name"></p>
                        <p class="mt-1 text-xl font-semibold text-gray-900" x-show="q.error === null" x-text="q.size"></p>
                        <p class="mt-1 text-xs text-red-600" x-show="q.error !== null" x-text="q.error"></p>
                    </div>
                </template>
            </div>
        </div>

        <div>
            <div class="flex items-center justify-between mb-2">
                <h2 class="text-sm font-semibold text-gray-800">Workers</h2>
                <form method="POST" action="{{ route('super-admin.queues.restart') }}"
                      onsubmit="return confirm('Restart all queue workers? Each finishes the job it is holding first, so nothing in flight is lost.')">
                    @csrf
                    <button type="submit" class="text-sm bg-brand-600 hover:bg-brand-700 text-white px-3 py-1.5 rounded-lg font-medium transition-colors">Restart workers</button>
                </form>
            </div>

            {{-- A worker keeps the code it started with in memory, so one older
                 than the last deploy is still running the old version. --}}
            <div x-show="workers.some(w => w.stale)" x-cloak
                 class="mb-2 bg-amber-50 border border-amber-200 rounded-xl px-4 py-3 text-sm text-amber-800">
                A worker has been up for over a day. Workers hold the code they started with, so if you have deployed since, it is still running the old version — restart them.
            </div>

            <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
                <template x-if="!workersAvailable">
                    <p class="px-4 py-3 text-sm text-gray-500">Process listing is not available on this host, so worker ages cannot be shown.</p>
                </template>
                <template x-if="workersAvailable && workers.length === 0">
                    <p class="px-4 py-3 text-sm text-red-600">No workers are running. Nothing queued will be processed.</p>
                </template>
                <template x-for="(w,i) in workers" :key="i">
                    <div class="px-4 py-2.5 flex items-center justify-between border-b border-gray-100 last:border-0">
                        <span class="text-sm text-gray-700" x-text="w.queue"></span>
                        <span class="text-sm" :class="w.stale ? 'text-amber-600 font-medium' : 'text-gray-500'" x-text="'up ' + w.age"></span>
                    </div>
                </template>
            </div>
        </div>

        {{-- Six different things run as a session over the queue and any of them
             can strand, so this covers all of them rather than only the one we
             happened to be fighting. --}}
        <div x-show="sessions.length > 0" x-cloak>
            <h2 class="text-sm font-semibold text-gray-800 mb-2">Sessions that stopped short</h2>
            <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
                <template x-for="s in sessions" :key="s.type + s.id">
                    <div class="px-4 py-3 flex items-start justify-between gap-4 border-b border-gray-100 last:border-0">
                        <div class="min-w-0">
                            <div class="flex items-center gap-2 flex-wrap">
                                <span class="text-sm font-medium text-gray-800" x-text="s.label + ' #' + s.id"></span>
                                <span class="text-[11px] px-2 py-0.5 rounded-full font-medium"
                                      :class="{'bg-red-100 text-red-700': s.status==='failed','bg-blue-100 text-blue-700': s.status==='processing','bg-gray-100 text-gray-600': s.status!=='failed'&&s.status!=='processing'}"
                                      x-text="s.status"></span>
                                <span class="text-xs text-gray-500" x-show="s.progress" x-text="s.progress"></span>
                                <span class="text-xs text-gray-400" x-text="s.updated"></span>
                            </div>
                            <p class="mt-1 text-xs text-gray-500 truncate" x-show="s.error" x-text="s.error"></p>
                            {{-- Better to say why than to offer a button that queues nothing. --}}
                            <p class="mt-1 text-xs text-amber-700" x-show="s.blocked" x-text="s.blocked"></p>
                        </div>
                        <form x-show="!s.blocked" method="POST" :action="'{{ url('super-admin/queues/sessions') }}/' + s.type + '/' + s.id + '/resume'"
                              onsubmit="return confirm('Resume this session? Only the work that never finished is queued.')">
                            @csrf
                            <button type="submit" class="shrink-0 text-sm border border-gray-300 hover:bg-gray-50 text-gray-700 px-3 py-1.5 rounded-lg font-medium transition-colors">Resume</button>
                        </form>
                    </div>
                </template>
            </div>
        </div>

        {{-- ── Purge ─────────────────────────────────────────────────── --}}
        <div>
            <h2 class="text-sm font-semibold text-gray-800 mb-2">Discard waiting work</h2>
            <div class="bg-white rounded-xl border border-gray-200 px-4 py-4">
                <p class="text-xs text-gray-500 mb-3">
                    Throws away every job still waiting on a queue. Nothing here can be undone, and work already
                    in progress is unaffected — type the queue name to confirm.
                </p>
                <form method="POST" action="{{ route('super-admin.queues.purge') }}" class="flex flex-wrap items-center gap-2">
                    @csrf
                    <select name="queue" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm">
                        <template x-for="q in queues" :key="q.name"><option :value="q.name" x-text="q.name + ' (' + q.size + ')'"></option></template>
                    </select>
                    <input type="text" name="confirm" placeholder="type the queue name"
                           class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-red-500">
                    <button type="submit" class="text-sm border border-red-200 text-red-600 hover:bg-red-50 px-3 py-1.5 rounded-lg font-medium transition-colors">Purge queue</button>
                </form>
            </div>
        </div>
    </div>

    {{-- ── Running ───────────────────────────────────────────────────── --}}
    <div x-show="tab === 'running'" x-cloak>
        <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
            <template x-if="running.length === 0">
                <p class="px-4 py-4 text-sm text-gray-500">No job is being processed right now.</p>
            </template>
            <template x-for="r in running" :key="r.id">
                <div class="px-4 py-3 flex items-center justify-between border-b border-gray-100 last:border-0">
                    <div class="flex items-center gap-2 min-w-0">
                        <span class="w-2 h-2 rounded-full bg-blue-500 animate-pulse shrink-0"></span>
                        <span class="text-sm font-medium text-gray-800" x-text="r.name"></span>
                        <span class="text-[11px] px-2 py-0.5 rounded-full bg-gray-100 text-gray-600" x-text="r.queue"></span>
                        <span class="text-[11px] text-gray-400" x-show="r.attempt > 1" x-text="'attempt ' + r.attempt"></span>
                    </div>
                    {{-- Amber past the hour: that is where the AI content job used
                         to be killed, so it is the point worth noticing. --}}
                    <span class="text-sm" :class="r.seconds > 3600 ? 'text-amber-600 font-medium' : 'text-gray-500'" x-text="'for ' + r.for"></span>
                </div>
            </template>
        </div>
    </div>

    {{-- ── History ───────────────────────────────────────────────────── --}}
    <div x-show="tab === 'history'" x-cloak class="space-y-3">
        <div class="flex flex-wrap items-center gap-2">
            <input type="text" x-model="hName" @input.debounce.400ms="loadHistory(1)" placeholder="Job name…"
                   class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
            <select x-model="hStatus" @change="loadHistory(1)" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm">
                <option value="">Any status</option>
                <option value="completed">Completed</option>
                <option value="failed">Failed</option>
                <option value="running">Running</option>
                <option value="lost">Lost</option>
            </select>
            <select x-model="hQueue" @change="loadHistory(1)" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm">
                <option value="">Any queue</option>
                <template x-for="q in queues" :key="q.name"><option :value="q.name" x-text="q.name"></option></template>
            </select>
            <span class="text-xs text-gray-400" x-text="hTotal + ' runs'"></span>
        </div>

        <div class="bg-white rounded-xl border border-gray-200 overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-gray-500 text-xs uppercase tracking-wide">
                    <tr>
                        <th class="text-left font-medium px-4 py-2">Job</th>
                        <th class="text-left font-medium px-4 py-2">Queue</th>
                        <th class="text-left font-medium px-4 py-2">Started</th>
                        <th class="text-left font-medium px-4 py-2">Took</th>
                        <th class="text-left font-medium px-4 py-2">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <template x-if="history.length === 0">
                        <tr><td colspan="5" class="px-4 py-4 text-gray-500">
                            Nothing recorded yet. History starts from the moment this was deployed — earlier runs were never recorded.
                        </td></tr>
                    </template>
                    <template x-for="h in history" :key="h.id">
                        <tr class="border-t border-gray-100 align-top">
                            <td class="px-4 py-2">
                                <span class="text-gray-800" x-text="h.name"></span>
                                <p class="text-xs text-red-600 mt-0.5" x-show="h.exception" x-text="h.exception"></p>
                            </td>
                            <td class="px-4 py-2 text-gray-500" x-text="h.queue"></td>
                            <td class="px-4 py-2 text-gray-500 whitespace-nowrap" x-text="h.started"></td>
                            <td class="px-4 py-2 text-gray-500 whitespace-nowrap" x-text="h.duration"></td>
                            <td class="px-4 py-2">
                                <span class="text-[11px] px-2 py-0.5 rounded-full font-medium"
                                      :class="{'bg-green-100 text-green-700':h.status==='completed','bg-red-100 text-red-700':h.status==='failed','bg-blue-100 text-blue-700':h.status==='running','bg-amber-100 text-amber-700':h.status==='lost'}"
                                      x-text="h.status"></span>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>

        <div class="flex items-center justify-between" x-show="hPages > 1">
            <button type="button" @click="loadHistory(hPage - 1)" :disabled="hPage <= 1"
                class="text-sm border border-gray-300 px-3 py-1.5 rounded-lg disabled:opacity-40">Previous</button>
            <span class="text-xs text-gray-500" x-text="'Page ' + hPage + ' of ' + hPages"></span>
            <button type="button" @click="loadHistory(hPage + 1)" :disabled="hPage >= hPages"
                class="text-sm border border-gray-300 px-3 py-1.5 rounded-lg disabled:opacity-40">Next</button>
        </div>
    </div>

    {{-- ── Failed ────────────────────────────────────────────────────── --}}
    <div x-show="tab === 'failed'" x-cloak class="space-y-3">
        <div class="flex items-center justify-between gap-2" x-show="failedTotal > 0">
            <span class="text-xs text-gray-500" x-text="failedTotal + ' failed job(s)'"></span>
            <div class="flex items-center gap-2">
            <form method="POST" action="{{ route('super-admin.queues.failed.retry') }}" onsubmit="return confirm('Push every failed job back onto its queue?')">
                @csrf
                <button type="submit" class="text-sm border border-gray-300 hover:bg-gray-50 text-gray-700 px-3 py-1.5 rounded-lg font-medium transition-colors">Retry all</button>
            </form>
            <form method="POST" action="{{ route('super-admin.queues.failed.forget') }}" onsubmit="return confirm('Delete the whole failed job list? This cannot be undone.')">
                @csrf
                <button type="submit" class="text-sm border border-red-200 text-red-600 hover:bg-red-50 px-3 py-1.5 rounded-lg font-medium transition-colors">Clear all</button>
            </form>
            </div>
        </div>

        <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
            <template x-if="failedRows.length === 0">
                <p class="px-4 py-4 text-sm text-gray-500">Nothing has failed.</p>
            </template>
            <template x-for="f in failedRows" :key="f.uuid">
                <div class="px-4 py-3 border-b border-gray-100 last:border-0">
                    <div class="flex items-start justify-between gap-4">
                        <div class="min-w-0">
                            <div class="flex items-center gap-2 flex-wrap">
                                <span class="text-sm font-medium text-gray-800" x-text="f.name"></span>
                                <span class="text-[11px] px-2 py-0.5 rounded-full bg-gray-100 text-gray-600" x-text="f.queue"></span>
                                <span class="text-xs text-gray-400" x-text="f.failed_at"></span>
                            </div>
                            <p class="mt-1 text-xs text-red-600 break-words" x-text="f.reason"></p>
                            <button type="button" @click="toggleTrace(f.uuid)" class="mt-1 text-xs text-brand-600 hover:text-brand-700 font-medium"
                                    x-text="openTrace === f.uuid ? 'Hide full trace' : 'Show full trace'"></button>
                            <pre x-show="openTrace === f.uuid" x-cloak
                                 class="mt-2 max-h-72 overflow-auto bg-gray-900 text-gray-100 text-[11px] leading-relaxed rounded-lg p-3 whitespace-pre-wrap break-words"
                                 x-text="trace"></pre>
                        </div>
                        <div class="flex items-center gap-2 shrink-0">
                            <form method="POST" action="{{ route('super-admin.queues.failed.retry') }}">
                                @csrf
                                <input type="hidden" name="uuid" :value="f.uuid">
                                <button type="submit" class="text-sm border border-gray-300 hover:bg-gray-50 text-gray-700 px-3 py-1.5 rounded-lg font-medium transition-colors">Retry</button>
                            </form>
                            <form method="POST" action="{{ route('super-admin.queues.failed.forget') }}" onsubmit="return confirm('Delete this failed job?')">
                                @csrf
                                <input type="hidden" name="uuid" :value="f.uuid">
                                <button type="submit" class="text-sm text-gray-400 hover:text-red-600 px-2 py-1.5 transition-colors">Delete</button>
                            </form>
                        </div>
                    </div>
                </div>
            </template>
        </div>

        <div class="flex items-center justify-between" x-show="fPages > 1">
            <button type="button" @click="loadFailed(fPage - 1)" :disabled="fPage <= 1"
                class="text-sm border border-gray-300 px-3 py-1.5 rounded-lg disabled:opacity-40">Previous</button>
            <span class="text-xs text-gray-500" x-text="'Page ' + fPage + ' of ' + fPages"></span>
            <button type="button" @click="loadFailed(fPage + 1)" :disabled="fPage >= fPages"
                class="text-sm border border-gray-300 px-3 py-1.5 rounded-lg disabled:opacity-40">Next</button>
        </div>
    </div>

    {{-- ── Logs ──────────────────────────────────────────────────────── --}}
    <div x-show="tab === 'logs'" x-cloak class="space-y-3">
        <div class="flex items-center gap-2">
            <input type="text" x-model="logQ" @input.debounce.400ms="loadLogs()" placeholder="Search the log… (blank shows jobs, queues and errors)"
                   class="flex-1 rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
            <button type="button" @click="loadLogs()" class="text-sm border border-gray-300 hover:bg-gray-50 px-3 py-1.5 rounded-lg font-medium">Refresh</button>
        </div>

        <p class="text-xs text-red-600" x-show="logError" x-text="logError"></p>

        <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
            <template x-if="logs.length === 0">
                <p class="px-4 py-4 text-sm text-gray-500">Nothing matching in the recent log.</p>
            </template>
            <template x-for="(l,i) in logs" :key="i">
                <div class="px-4 py-2 border-b border-gray-100 last:border-0 flex gap-3">
                    <span class="text-[11px] text-gray-400 whitespace-nowrap font-mono" x-text="l.time"></span>
                    <span class="text-[11px] px-1.5 py-0.5 rounded font-medium h-fit shrink-0"
                          :class="{'bg-red-100 text-red-700':l.level==='error','bg-amber-100 text-amber-700':l.level==='warning','bg-gray-100 text-gray-600':l.level!=='error'&&l.level!=='warning'}"
                          x-text="l.level"></span>
                    <span class="text-xs text-gray-700 break-words" x-text="l.message"></span>
                </div>
            </template>
        </div>
    </div>
</div>

<script>
function queueDashboard() {
    return {
        tab:              'overview',
        queues:           @json($queues),
        workers:          @json($workers['list']),
        workersAvailable: @json($workers['available']),
        failed:           @json($failed),
        failedRows:       @json($failed),
        failedTotal:      @json($failedTotal),
        fPage: 1, fPages: 1,
        sessions:         @json($sessions),
        running:          @json($running),
        today:            @json($today),
        driver:           @json($driver),
        refreshed:        'just now',

        history: [], hPage: 1, hPages: 1, hTotal: 0, hName: '', hStatus: '', hQueue: '',
        logs: [], logQ: '', logError: null,
        openTrace: null, trace: '',

        get totalWaiting() {
            return this.queues.reduce((sum, q) => sum + (q.size || 0), 0);
        },

        start() {
            // Five seconds is short enough to watch a queue drain and long enough
            // that the page is not a load generator of its own.
            setInterval(() => this.refresh(), 5000);
        },

        go(t) {
            this.tab = t;
            if (t === 'history' && this.history.length === 0) this.loadHistory(1);
            if (t === 'logs' && this.logs.length === 0) this.loadLogs();
            if (t === 'failed') this.loadFailed(this.fPage);
        },

        async refresh() {
            try {
                const res = await fetch('{{ route('super-admin.queues.status') }}', { headers: { 'Accept': 'application/json' } });
                if (!res.ok) return;

                const d = await res.json();
                this.queues = d.queues; this.workers = d.workers.list;
                this.workersAvailable = d.workers.available;
                this.failed = d.failed; this.failedTotal = d.failedTotal; this.sessions = d.sessions;
                if (this.tab !== 'failed') this.failedRows = d.failed;
                this.running = d.running; this.today = d.today; this.driver = d.driver;
                this.refreshed = new Date().toLocaleTimeString();
            } catch (e) {
                // A dropped poll is not worth surfacing; the next one will land.
            }
        },

        async loadHistory(page) {
            if (page < 1) return;

            const params = new URLSearchParams({ page, name: this.hName, status: this.hStatus, queue: this.hQueue });

            try {
                const res = await fetch('{{ route('super-admin.queues.history') }}?' + params, { headers: { 'Accept': 'application/json' } });
                if (!res.ok) return;

                const d = await res.json();
                this.history = d.rows; this.hPage = d.page; this.hPages = d.pages; this.hTotal = d.total;
            } catch (e) {}
        },

        async loadFailed(page) {
            if (page < 1) return;

            try {
                const res = await fetch('{{ route('super-admin.queues.failed.page') }}?page=' + page, { headers: { 'Accept': 'application/json' } });
                if (!res.ok) return;

                const d = await res.json();
                this.failedRows = d.rows; this.fPage = d.page; this.fPages = d.pages; this.failedTotal = d.total;
            } catch (e) {}
        },

        async loadLogs() {
            try {
                const res = await fetch('{{ route('super-admin.queues.logs') }}?q=' + encodeURIComponent(this.logQ), { headers: { 'Accept': 'application/json' } });
                if (!res.ok) return;

                const d = await res.json();
                this.logs = d.entries; this.logError = d.error;
            } catch (e) {}
        },

        async toggleTrace(uuid) {
            if (this.openTrace === uuid) { this.openTrace = null; return; }

            this.openTrace = uuid;
            this.trace     = 'Loading…';

            try {
                const res = await fetch('{{ url('super-admin/queues/failed') }}/' + uuid, { headers: { 'Accept': 'application/json' } });
                const d   = await res.json();
                this.trace = d.exception;
            } catch (e) {
                this.trace = 'Could not load the trace.';
            }
        },
    };
}
</script>
@endsection
