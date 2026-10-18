@extends('layouts.app')
@section('title', 'Page Analyzer · AI SEO Support')
@section('page-title', 'AI SEO Support')

@section('content')
<div class="space-y-6"
     x-data="aiSeoAnalyzer(@js([
        'url'        => $url,
        'property'   => $property,
        'start'      => $url !== '' && !$urlError,
        'reportUrl'  => route('ai-seo.report'),
        'resolveUrl' => route('ai-seo.resolve'),
     ]))"
     x-init="if (start) load()">

    <div class="bg-white rounded-xl border border-gray-200 p-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h2 class="font-semibold text-gray-800 text-lg">Page analyzer</h2>
                <p class="text-sm text-gray-500 mt-1">
                    A full audit of one page with AI-written rewrites. It reads the live page, Search Console and the
                    AI model in turn, so it takes 10 to 25 seconds.
                </p>
            </div>
            <a href="{{ route('ai-seo.index', ['property' => $property]) }}" class="text-sm text-brand-600 hover:text-brand-800 whitespace-nowrap">← Quick wins</a>
        </div>

        <form method="GET" action="{{ route('ai-seo.analyze') }}" class="mt-5 flex flex-wrap gap-2">
            <select name="property" class="rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
                @foreach($properties as $value => $label)
                    <option value="{{ $value }}" @selected($value === $property)>{{ $label }}</option>
                @endforeach
            </select>
            <input type="url" name="url" value="{{ $url }}" required placeholder="https://…"
                   class="flex-1 min-w-[16rem] rounded-lg border border-gray-300 px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-brand-500">
            <button type="submit" class="px-4 py-2 rounded-lg bg-brand-600 text-white text-sm font-medium hover:bg-brand-700">
                Analyse
            </button>
        </form>
        <p class="text-xs text-gray-400 mt-2">
            Search Console treats every address exactly, so an /ar/ page is separate from its English one.
        </p>

        @if($urlError)
            <div class="mt-4 bg-amber-50 border border-amber-200 rounded-lg p-4 text-sm text-amber-800">{{ $urlError }}</div>
        @endif
    </div>

    {{-- Waiting ───────────────────────────────────────────────────────────── --}}
    <template x-if="loading">
        <div class="bg-white rounded-xl border border-gray-200 px-6 py-14 text-center">
            <svg class="w-8 h-8 mx-auto text-brand-500 animate-spin" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
            </svg>
            <p class="mt-4 text-sm text-gray-600">Analysing the page… <span class="tabular-nums" x-text="elapsed + 's'"></span></p>
            <p class="text-xs text-gray-400 mt-1">Reading the live page, Search Console and writing suggestions.</p>
        </div>
    </template>

    <template x-if="error">
        <div class="bg-amber-50 border border-amber-200 rounded-xl p-5 text-sm text-amber-800 flex flex-wrap items-center justify-between gap-3">
            <span x-text="error"></span>
            <button type="button" @click="load()" class="font-medium underline">Try again</button>
        </div>
    </template>

    {{-- The report ────────────────────────────────────────────────────────── --}}
    <template x-if="report">
        <div class="space-y-6">
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
                <div class="bg-white rounded-xl border border-gray-200 p-5">
                    <p class="text-xs text-gray-500 mb-1">SEO health</p>
                    <p class="text-2xl font-bold tabular-nums" :class="scoreClass()">
                        <span x-text="report.summary?.score ?? '—'"></span><span class="text-sm font-medium text-gray-400"> / 100</span>
                    </p>
                </div>
                <div class="bg-white rounded-xl border border-gray-200 p-5">
                    <p class="text-xs text-gray-500 mb-1">Open findings</p>
                    <p class="text-2xl font-bold text-gray-800 tabular-nums" x-text="openCount()"></p>
                </div>
                <div class="bg-white rounded-xl border border-gray-200 p-5">
                    <p class="text-xs text-gray-500 mb-1">Came back after a fix</p>
                    <p class="text-2xl font-bold tabular-nums" :class="reopenedCount() ? 'text-red-600' : 'text-gray-800'" x-text="reopenedCount()"></p>
                </div>
                <div class="bg-white rounded-xl border border-gray-200 p-5">
                    <p class="text-xs text-gray-500 mb-1">Resolved before</p>
                    <p class="text-2xl font-bold text-gray-800 tabular-nums" x-text="report.summary?.resolvedCount ?? report.resolvedCount ?? 0"></p>
                </div>
            </div>

            <template x-if="(report.targetQueries || []).length">
                <div class="bg-white rounded-xl border border-gray-200 p-5">
                    <p class="text-xs text-gray-500 uppercase tracking-wide mb-2">What people search to find this page</p>
                    <div class="flex flex-wrap gap-2">
                        <template x-for="q in report.targetQueries" :key="q">
                            <span class="px-2.5 py-1 rounded-full bg-gray-100 text-gray-700 text-xs" x-text="q"></span>
                        </template>
                    </div>
                </div>
            </template>

            <template x-if="openCount() === 0">
                <div class="bg-white rounded-xl border border-gray-200 px-6 py-12 text-center text-sm text-gray-500">
                    Nothing left to fix on this page.
                </div>
            </template>

            <template x-for="section in sections()" :key="section.category">
                <div class="bg-white rounded-xl border border-gray-200 overflow-hidden" x-show="section.findings.some(f => !f._done)">
                    <div class="px-6 py-3 border-b border-gray-100 bg-gray-50">
                        <h3 class="font-semibold text-gray-800" x-text="section.category"></h3>
                    </div>
                    <div class="divide-y divide-gray-100">
                        <template x-for="f in section.findings" :key="f.findingId">
                            <div class="px-6 py-4 transition-opacity duration-500"
                                 x-show="!f._done" x-transition:leave.opacity.duration.500ms
                                 :class="f.reopened ? 'bg-red-50/50' : ''">
                                <div class="flex flex-wrap items-start justify-between gap-3">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-semibold"
                                              :class="severityClass(f.severity)" x-text="f.severity || 'low'"></span>
                                        <template x-if="f.reopened">
                                            <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-semibold bg-red-600 text-white"
                                                  title="Marked resolved before, but still detected — the fix did not take.">Back again</span>
                                        </template>
                                        <p class="font-medium text-gray-800" x-text="f.issue"></p>
                                    </div>
                                    <button type="button" @click="resolve(f)" :disabled="f._busy"
                                            class="text-sm px-3 py-1.5 rounded-lg border border-gray-300 text-gray-700 hover:bg-gray-50 disabled:opacity-50 whitespace-nowrap">
                                        <span x-text="f._busy ? 'Saving…' : 'Mark as resolved'"></span>
                                    </button>
                                </div>

                                <div class="mt-3 grid md:grid-cols-2 gap-3" x-show="f.current || f.suggestion">
                                    <div class="rounded-lg border border-gray-200 p-3">
                                        <p class="text-xs text-gray-400 mb-1">Now</p>
                                        <p class="text-sm text-gray-600 whitespace-pre-line break-words" x-text="f.current || '(empty)'"></p>
                                    </div>
                                    <div class="rounded-lg border border-green-200 bg-green-50/50 p-3">
                                        <div class="flex items-center justify-between mb-1">
                                            <p class="text-xs text-green-700">Suggested <span class="text-gray-400" x-show="f.suggestion" x-text="'· ' + (f.suggestion || '').length + ' chars'"></span></p>
                                            <button type="button" x-show="f.suggestion" @click="copy(f)" class="text-xs text-green-700 hover:underline"
                                                    x-text="f._copied ? 'Copied' : 'Copy'"></button>
                                        </div>
                                        <p class="text-sm text-gray-800 whitespace-pre-line break-words" x-text="f.suggestion || '—'"></p>
                                    </div>
                                </div>

                                <p class="mt-2 text-xs text-gray-500" x-show="f.rationale" x-text="f.rationale"></p>
                                <p class="mt-2 text-xs text-red-600" x-show="f._error" x-text="f._error"></p>
                            </div>
                        </template>
                    </div>
                </div>
            </template>

            <template x-if="(report.recommendations || []).filter(r => typeof r === 'string').length">
                <div class="bg-white rounded-xl border border-gray-200 p-6">
                    <h3 class="font-semibold text-gray-800 mb-3">Recommendations</h3>
                    <ul class="list-disc pl-5 space-y-1.5 text-sm text-gray-700">
                        <template x-for="r in report.recommendations.filter(r => typeof r === 'string')">
                            <li x-text="r"></li>
                        </template>
                    </ul>
                </div>
            </template>
        </div>
    </template>

    <div x-show="toast" x-transition.opacity
         class="fixed bottom-6 right-6 z-50 px-4 py-2 rounded-lg bg-gray-900 text-white text-sm shadow-lg" x-text="toast"></div>
</div>

<script>
function aiSeoAnalyzer(cfg) {
    const csrf = () => document.querySelector('meta[name=csrf-token]').content;

    return {
        ...cfg,
        loading: false,
        error: null,
        report: null,
        elapsed: 0,
        toast: null,

        async load() {
            this.loading = true;
            this.error = null;
            this.report = null;
            this.elapsed = 0;
            const tick = setInterval(() => this.elapsed++, 1000);

            try {
                const res = await fetch(this.reportUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf() },
                    body: JSON.stringify({ url: this.url, property: this.property }),
                });
                const json = await res.json().catch(() => ({}));

                if (!res.ok) {
                    this.error = json.message || 'The analysis failed. It is safe to try again.';
                    return;
                }

                this.report = json;
            } catch (e) {
                this.error = 'Could not reach the server. It is safe to try again.';
            } finally {
                clearInterval(tick);
                this.loading = false;
            }
        },

        sections() {
            return (this.report?.sections || []).filter(s => Array.isArray(s.findings) && s.findings.length);
        },

        findings() {
            return this.sections().flatMap(s => s.findings);
        },

        openCount() {
            return this.findings().filter(f => !f._done).length;
        },

        reopenedCount() {
            return this.findings().filter(f => !f._done && f.reopened).length;
        },

        scoreClass() {
            const s = this.report?.summary?.score;
            if (typeof s !== 'number') return 'text-gray-800';
            return s >= 80 ? 'text-green-600' : (s >= 50 ? 'text-amber-600' : 'text-red-600');
        },

        severityClass(severity) {
            return severity === 'high' ? 'bg-red-50 text-red-700'
                : severity === 'medium' ? 'bg-amber-50 text-amber-700'
                : 'bg-gray-100 text-gray-600';
        },

        async copy(f) {
            try {
                await navigator.clipboard.writeText(f.suggestion);
                f._copied = true;
                setTimeout(() => f._copied = false, 1500);
            } catch (e) {}
        },

        // One-way: the engine has no un-resolve. A mistake simply comes back,
        // flagged, on the next scan.
        async resolve(f) {
            if (!confirm('Mark "' + f.issue + '" as resolved?\n\nThis cannot be undone. If it is not actually fixed, it will come back on the next scan.')) {
                return;
            }

            f._busy = true;
            f._error = null;

            try {
                const res = await fetch(this.resolveUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf() },
                    body: JSON.stringify({
                        findingId: f.findingId,
                        url: this.report?.summary?.url || this.url,
                        property: this.property,
                        issue: f.issue,
                        category: f.category,
                    }),
                });
                const json = await res.json().catch(() => ({}));

                if (!res.ok || json.ok === false) {
                    f._error = json.message || 'Could not save. It is safe to try again.';
                    return;
                }

                f._done = true;
                this.toast = 'Marked resolved';
                setTimeout(() => this.toast = null, 2000);
            } catch (e) {
                f._error = 'Could not reach the server. It is safe to try again.';
            } finally {
                f._busy = false;
            }
        },
    };
}
</script>
@endsection
