@php
    /**
     * Showcase slides for the login page.
     *
     * Drop a real screenshot at public/slides/<image> to replace the built-in
     * mock UI for any slide — it is used automatically when the file exists.
     */
    $slides = [
        [
            'key'    => 'ai-content',
            'module' => 'AI Content Generator',
            'title'  => 'Product copy that writes itself',
            'text'   => 'Generate titles, descriptions and SEO metadata for entire collections in one pass — reviewed and published from a single screen.',
            'mock'   => 'editor',
            'image'  => 'slides/ai-content.png',
        ],
        [
            'key'    => 'image-upload',
            'module' => 'Image Upload & Store Sync',
            'title'  => 'Thousands of images, one drop',
            'text'   => 'Bulk upload by SKU, map images to the right variants and push them to every connected store automatically.',
            'mock'   => 'grid',
            'image'  => 'slides/image-upload.png',
        ],
        [
            'key'    => 'image-audit',
            'module' => 'Image Audit',
            'title'  => 'Catch missing media before customers do',
            'text'   => 'Scan your catalogue for products without photos, broken links and low-quality assets — then fix them in place.',
            'mock'   => 'audit',
            'image'  => 'slides/image-audit.png',
        ],
        [
            'key'    => 'checkers',
            'module' => 'SKU & Metafield Checker',
            'title'  => 'Clean data across every store',
            'text'   => 'Validate SKUs, compare metafields between stores and bulk-update whatever falls out of line.',
            'mock'   => 'table',
            'image'  => 'slides/checkers.png',
        ],
        [
            'key'    => 'requests',
            'module' => 'Product Creation Requests',
            'title'  => 'From request to live listing',
            'text'   => 'Track every new product through photoshoot, content and publishing — with tasks, owners and notifications built in.',
            'mock'   => 'kanban',
            'image'  => 'slides/requests.png',
        ],
    ];
@endphp
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign in – AI Ecommerce Studio</title>
    @include('partials.favicon')
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        brand: {
                            50:  '#e9f7fc',
                            100: '#d2eef8',
                            200: '#b0e0f2',
                            300: '#8fcfea',
                            400: '#69bbd9',
                            500: '#439fc1',
                            600: '#3083a6',
                            700: '#276b89',
                            800: '#215873',
                            900: '#1c4961',
                            950: '#12333f',
                        }
                    }
                }
            }
        }
    </script>
    <style>
        :root { --slide-duration: 7s; }

        body { font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; }

        /* ---------- Showcase panel background ---------- */
        .showcase {
            background:
                radial-gradient(1000px 520px at 12% -10%, rgba(105,187,217,.30), transparent 60%),
                radial-gradient(760px 480px at 92% 105%, rgba(48,131,166,.42), transparent 62%),
                linear-gradient(160deg, #1c4961 0%, #1d5a74 45%, #12333f 100%);
        }
        /* ---------- Slider ---------- */
        .slide {
            position: absolute;
            inset: 0;
            opacity: 0;
            visibility: hidden;
            transform: translateY(14px) scale(.985);
            transition: opacity .7s ease, transform .7s cubic-bezier(.22,.61,.36,1), visibility .7s;
        }
        .slide.is-active {
            opacity: 1;
            visibility: visible;
            transform: none;
        }
        .slide > * { opacity: 0; transform: translateY(12px); }
        .slide.is-active > * {
            animation: slide-in .65s cubic-bezier(.22,.61,.36,1) forwards;
        }
        .slide.is-active > *:nth-child(1) { animation-delay: .06s; }
        .slide.is-active > *:nth-child(2) { animation-delay: .14s; }
        .slide.is-active > *:nth-child(3) { animation-delay: .22s; }
        .slide.is-active > *:nth-child(4) { animation-delay: .30s; }
        @keyframes slide-in { to { opacity: 1; transform: none; } }

        /* Dots: active dot stretches into a progress track */
        .dot {
            height: 6px;
            width: 6px;
            border-radius: 999px;
            background: rgba(255,255,255,.28);
            transition: width .45s cubic-bezier(.22,.61,.36,1), background-color .3s;
            position: relative;
            overflow: hidden;
        }
        .dot:hover { background: rgba(255,255,255,.5); }
        .dot.is-active { width: 42px; background: rgba(255,255,255,.28); }
        .dot .dot-fill {
            position: absolute;
            inset: 0 auto 0 0;
            width: 0;
            background: #fff;
            border-radius: 999px;
        }
        .dot.is-active .dot-fill { animation: dot-fill var(--slide-duration) linear forwards; }
        .slider.is-paused .dot.is-active .dot-fill { animation-play-state: paused; }
        @keyframes dot-fill { from { width: 0 } to { width: 100% } }

        .float { animation: float 7s ease-in-out infinite; }
        @keyframes float { 0%,100% { transform: translateY(0) } 50% { transform: translateY(-9px) } }

        @media (prefers-reduced-motion: reduce) {
            .slide, .slide > *, .dot, .float { transition: none !important; animation: none !important; }
            .slide > * { opacity: 1; transform: none; }
            .dot.is-active .dot-fill { width: 100%; }
        }
    </style>
</head>
<body class="h-full bg-white text-slate-900">

<div class="min-h-full lg:grid lg:grid-cols-[1.05fr_minmax(0,480px)] xl:grid-cols-[1.2fr_minmax(0,520px)]">

    {{-- ============================ SHOWCASE ============================ --}}
    <section class="showcase relative overflow-hidden px-6 py-10 sm:px-10 lg:flex lg:flex-col lg:px-14 lg:py-12">

        {{-- Brand --}}
        <div class="relative flex items-center gap-3">
            <img src="/aih_logo_whitegray-3.png" alt="Abuissa Holding" class="h-9 w-auto sm:h-11">
            <span class="h-8 w-px bg-white/20"></span>
            <span class="text-sm font-semibold tracking-wide text-white sm:text-base">AI Ecommerce Studio</span>
        </div>

        {{-- Slider --}}
        <div id="slider" class="slider relative mt-8 lg:mt-auto lg:mb-auto">
            {{-- Sizing box: tall enough for the tallest slide, since slides are absolutely stacked --}}
            <div class="relative min-h-[200px] sm:min-h-[560px] lg:min-h-[580px]">
                @foreach ($slides as $i => $slide)
                    <article class="slide {{ $i === 0 ? 'is-active' : '' }}"
                             data-index="{{ $i }}"
                             role="group"
                             aria-roledescription="slide"
                             aria-label="{{ $i + 1 }} of {{ count($slides) }}: {{ $slide['module'] }}">

                        <span class="inline-flex items-center gap-2 rounded-full border border-white/15 bg-white/10 px-3 py-1.5 text-[11px] font-semibold uppercase tracking-[.12em] text-brand-100 backdrop-blur">
                            <span class="h-1.5 w-1.5 rounded-full bg-brand-300"></span>
                            {{ $slide['module'] }}
                        </span>

                        {{-- min-h reserves two lines so the visual below never shifts between slides --}}
                        <h1 class="mt-5 max-w-xl text-3xl font-bold leading-[1.15] text-white sm:min-h-[5.3rem] sm:text-4xl lg:min-h-[6rem] lg:text-[2.6rem]">
                            {{ $slide['title'] }}
                        </h1>

                        <p class="mt-4 max-w-lg text-sm leading-relaxed text-brand-100/85 sm:min-h-[3.2rem] sm:text-[15px]">
                            {{ $slide['text'] }}
                        </p>

                        {{-- Visual: real screenshot when present, otherwise a mock UI --}}
                        <div class="float mt-8 hidden sm:block">
                            @if (file_exists(public_path($slide['image'])))
                                <img src="/{{ $slide['image'] }}" alt="{{ $slide['module'] }} preview"
                                     class="w-full max-w-lg rounded-xl border border-white/15 shadow-2xl shadow-black/40">
                            @else
                                {{-- Browser frame --}}
                                <div class="w-full max-w-xl overflow-hidden rounded-xl border border-white/15 bg-white/[.07] shadow-2xl shadow-black/40 backdrop-blur">
                                    <div class="flex items-center gap-2 border-b border-white/10 bg-white/[.06] px-4 py-2.5">
                                        <span class="h-2.5 w-2.5 rounded-full bg-white/25"></span>
                                        <span class="h-2.5 w-2.5 rounded-full bg-white/20"></span>
                                        <span class="h-2.5 w-2.5 rounded-full bg-white/15"></span>
                                        <span class="ml-3 truncate rounded-md bg-black/20 px-2.5 py-1 text-[10px] text-white/45">
                                            studio.abuissa.com/{{ $slide['key'] }}
                                        </span>
                                    </div>

                                    <div class="p-4">
                                        @switch ($slide['mock'])

                                            @case ('editor')
                                                <div class="grid grid-cols-3 gap-3">
                                                    <div class="col-span-2 space-y-2.5 rounded-lg bg-white/[.06] p-3">
                                                        <div class="h-2 w-24 rounded bg-brand-300/70"></div>
                                                        <div class="h-1.5 w-full rounded bg-white/20"></div>
                                                        <div class="h-1.5 w-[92%] rounded bg-white/15"></div>
                                                        <div class="h-1.5 w-[78%] rounded bg-white/15"></div>
                                                        <div class="h-1.5 w-[86%] rounded bg-white/10"></div>
                                                        <div class="h-1.5 w-[40%] rounded bg-white/10"></div>
                                                    </div>
                                                    <div class="space-y-2 rounded-lg bg-white/[.06] p-3">
                                                        <div class="h-2 w-12 rounded bg-white/25"></div>
                                                        <div class="rounded-md border border-brand-300/30 bg-brand-300/10 px-2 py-1.5 text-[9px] font-semibold text-brand-100">SEO title ✓</div>
                                                        <div class="rounded-md border border-white/10 bg-white/5 px-2 py-1.5 text-[9px] text-white/60">Meta description</div>
                                                        <div class="rounded-md border border-white/10 bg-white/5 px-2 py-1.5 text-[9px] text-white/60">Tags · 8</div>
                                                    </div>
                                                </div>
                                                <div class="mt-3 flex items-center gap-2">
                                                    <div class="h-7 flex-1 rounded-md bg-white/[.06]"></div>
                                                    <div class="h-7 w-28 rounded-md bg-brand-400/80"></div>
                                                </div>
                                                @break

                                            @case ('grid')
                                                <div class="grid grid-cols-4 gap-2.5">
                                                    @foreach ([0,1,2,3,4,5,6,7] as $n)
                                                        <div class="aspect-[5/4] rounded-lg bg-gradient-to-br {{ $n % 3 === 0 ? 'from-brand-300/35 to-brand-500/20' : 'from-white/15 to-white/5' }} ring-1 ring-white/10"></div>
                                                    @endforeach
                                                </div>
                                                <div class="mt-3 rounded-lg bg-white/[.06] p-3">
                                                    <div class="flex items-center justify-between text-[9px] text-white/55">
                                                        <span>Uploading 1,248 images</span><span>84%</span>
                                                    </div>
                                                    <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-white/10">
                                                        <div class="h-full w-[84%] rounded-full bg-brand-300"></div>
                                                    </div>
                                                </div>
                                                @break

                                            @case ('audit')
                                                <div class="grid grid-cols-3 gap-2.5">
                                                    <div class="rounded-lg bg-white/[.06] p-3">
                                                        <div class="text-[9px] uppercase tracking-wider text-white/45">Missing</div>
                                                        <div class="mt-1 text-lg font-bold text-white">312</div>
                                                    </div>
                                                    <div class="rounded-lg bg-white/[.06] p-3">
                                                        <div class="text-[9px] uppercase tracking-wider text-white/45">Low-res</div>
                                                        <div class="mt-1 text-lg font-bold text-white">87</div>
                                                    </div>
                                                    <div class="rounded-lg bg-brand-300/15 p-3 ring-1 ring-brand-300/25">
                                                        <div class="text-[9px] uppercase tracking-wider text-brand-100/80">Healthy</div>
                                                        <div class="mt-1 text-lg font-bold text-white">9.4k</div>
                                                    </div>
                                                </div>
                                                <div class="mt-3 flex h-24 items-end gap-1.5 rounded-lg bg-white/[.06] p-3">
                                                    @foreach ([35,52,44,68,58,76,64,88,72,94] as $h)
                                                        <div class="flex-1 rounded-sm bg-brand-300/70" style="height: {{ $h }}%"></div>
                                                    @endforeach
                                                </div>
                                                @break

                                            @case ('table')
                                                <div class="overflow-hidden rounded-lg bg-white/[.06]">
                                                    <div class="grid grid-cols-[1.6fr_1fr_auto] gap-3 border-b border-white/10 px-3 py-2 text-[9px] uppercase tracking-wider text-white/40">
                                                        <span>SKU</span><span>Metafield</span><span>Status</span>
                                                    </div>
                                                    @foreach ([['ok','Matched'],['ok','Matched'],['warn','Mismatch'],['ok','Matched'],['warn','Missing']] as $row)
                                                        <div class="grid grid-cols-[1.6fr_1fr_auto] items-center gap-3 border-b border-white/5 px-3 py-2.5">
                                                            <div class="h-1.5 w-full rounded bg-white/20"></div>
                                                            <div class="h-1.5 w-2/3 rounded bg-white/12"></div>
                                                            <span class="rounded-full px-2 py-0.5 text-[9px] font-semibold {{ $row[0] === 'ok' ? 'bg-emerald-400/15 text-emerald-200' : 'bg-amber-400/15 text-amber-200' }}">
                                                                {{ $row[1] }}
                                                            </span>
                                                        </div>
                                                    @endforeach
                                                </div>
                                                @break

                                            @case ('kanban')
                                                <div class="grid grid-cols-3 gap-2.5">
                                                    @foreach ([['Requested', 2], ['Photoshoot', 3], ['Publishing', 1]] as $col)
                                                        <div class="rounded-lg bg-white/[.06] p-2.5">
                                                            <div class="mb-2 flex items-center justify-between text-[9px] uppercase tracking-wider text-white/45">
                                                                <span>{{ $col[0] }}</span><span>{{ $col[1] }}</span>
                                                            </div>
                                                            <div class="space-y-2">
                                                                @for ($n = 0; $n < $col[1]; $n++)
                                                                    <div class="space-y-1.5 rounded-md bg-white/10 p-2">
                                                                        <div class="h-1.5 w-full rounded bg-white/25"></div>
                                                                        <div class="h-1.5 w-1/2 rounded bg-white/15"></div>
                                                                        <div class="flex gap-1 pt-0.5">
                                                                            <span class="h-3.5 w-3.5 rounded-full bg-brand-300/70"></span>
                                                                            <span class="h-3.5 w-3.5 rounded-full bg-white/20"></span>
                                                                        </div>
                                                                    </div>
                                                                @endfor
                                                            </div>
                                                        </div>
                                                    @endforeach
                                                </div>
                                                @break

                                        @endswitch
                                    </div>
                                </div>
                            @endif
                        </div>
                    </article>
                @endforeach
            </div>

            {{-- Controls --}}
            <div class="relative mt-6 flex items-center justify-between">
                <div id="dots" class="flex items-center gap-2" role="tablist" aria-label="Choose slide">
                    @foreach ($slides as $i => $slide)
                        <button type="button" class="dot {{ $i === 0 ? 'is-active' : '' }}"
                                data-index="{{ $i }}"
                                role="tab"
                                aria-label="{{ $slide['module'] }}"
                                aria-selected="{{ $i === 0 ? 'true' : 'false' }}">
                            <span class="dot-fill"></span>
                        </button>
                    @endforeach
                </div>

                <div class="flex items-center gap-2">
                    <button type="button" data-slide-prev aria-label="Previous slide"
                            class="grid h-9 w-9 place-items-center rounded-full border border-white/15 text-white/70 transition hover:border-white/30 hover:bg-white/10 hover:text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-white/60">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/>
                        </svg>
                    </button>
                    <button type="button" data-slide-next aria-label="Next slide"
                            class="grid h-9 w-9 place-items-center rounded-full border border-white/15 text-white/70 transition hover:border-white/30 hover:bg-white/10 hover:text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-white/60">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/>
                        </svg>
                    </button>
                </div>
            </div>
        </div>

        {{-- Footer strip --}}
        <div class="relative mt-8 hidden flex-wrap items-center gap-x-6 gap-y-2 border-t border-white/10 pt-6 text-[11px] text-white/45 lg:flex">
            <span>Multi-store ready</span>
            <span class="h-1 w-1 rounded-full bg-white/25"></span>
            <span>Role-based access</span>
            <span class="h-1 w-1 rounded-full bg-white/25"></span>
            <span>Full activity audit trail</span>
        </div>
    </section>

    {{-- ============================= SIGN IN ============================= --}}
    <section class="flex items-center justify-center px-6 py-12 sm:px-10 lg:px-12">
        <div class="w-full max-w-sm">
            <h2 class="text-2xl font-bold tracking-tight text-slate-900">Welcome back</h2>
            <p class="mt-1.5 text-sm text-slate-500">Sign in to your admin account to continue.</p>

            @if ($errors->any())
                <div class="mt-6 flex gap-2.5 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                    <svg class="mt-0.5 h-4 w-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m0 3.25h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
                    </svg>
                    <span>{{ $errors->first() }}</span>
                </div>
            @endif

            @if (session('status'))
                <div class="mt-6 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
                    {{ session('status') }}
                </div>
            @endif

            <form method="POST" action="{{ route('login') }}" class="mt-7 space-y-5">
                @csrf

                <div>
                    <label for="email" class="mb-1.5 block text-sm font-medium text-slate-700">Email address</label>
                    <div class="relative">
                        <svg class="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.8">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                        </svg>
                        <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus
                               autocomplete="username"
                               placeholder="you@abuissa.com"
                               class="w-full rounded-xl border border-slate-300 bg-white py-2.5 pl-10 pr-4 text-sm text-slate-900 placeholder:text-slate-400 transition focus:border-brand-500 focus:outline-none focus:ring-4 focus:ring-brand-500/15">
                    </div>
                </div>

                <div>
                    <label for="password" class="mb-1.5 block text-sm font-medium text-slate-700">Password</label>
                    <div class="relative">
                        <svg class="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.8">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M16 11V8a4 4 0 10-8 0v3m-1 0h10a2 2 0 012 2v6a2 2 0 01-2 2H7a2 2 0 01-2-2v-6a2 2 0 012-2z"/>
                        </svg>
                        <input id="password" type="password" name="password" required
                               autocomplete="current-password"
                               placeholder="••••••••"
                               class="w-full rounded-xl border border-slate-300 bg-white py-2.5 pl-10 pr-11 text-sm text-slate-900 placeholder:text-slate-400 transition focus:border-brand-500 focus:outline-none focus:ring-4 focus:ring-brand-500/15">
                        <button type="button" id="togglePassword" aria-label="Show password" aria-pressed="false"
                                class="absolute right-2 top-1/2 grid h-8 w-8 -translate-y-1/2 place-items-center rounded-lg text-slate-400 transition hover:bg-slate-100 hover:text-slate-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500">
                            <svg data-eye-open class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.8">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12z"/>
                                <circle cx="12" cy="12" r="3"/>
                            </svg>
                            <svg data-eye-off class="hidden h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.8">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M3 3l18 18M10.6 10.6A3 3 0 0014.4 14.4M6.2 6.7C3.9 8.4 2.5 12 2.5 12s3.5 6.5 9.5 6.5c1.5 0 2.8-.4 4-1M17.5 15.2c1.6-1.3 2.6-3 3.1-3.2 0 0-3.5-6.5-9.1-6.5"/>
                            </svg>
                        </button>
                    </div>
                </div>

                <label class="flex cursor-pointer items-center gap-2.5 text-sm text-slate-600">
                    <input type="checkbox" name="remember"
                           class="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                    Keep me signed in
                </label>

                <button type="submit"
                        class="w-full rounded-xl bg-brand-700 py-3 text-sm font-semibold text-white shadow-lg shadow-brand-700/20 transition hover:bg-brand-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 focus-visible:ring-offset-2">
                    Sign in
                </button>
            </form>

            <p class="mt-8 border-t border-slate-100 pt-6 text-center text-xs leading-relaxed text-slate-400">
                Powered by the Abuissa Holding<br>E-Commerce Department
            </p>
        </div>
    </section>
</div>

<script>
(function () {
    const slider = document.getElementById('slider');
    if (!slider) return;

    const slides = Array.from(slider.querySelectorAll('.slide'));
    const dots   = Array.from(slider.querySelectorAll('.dot'));
    if (slides.length < 2) return;

    const reduced  = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const DURATION = 7000;
    let current = 0;
    let timer   = null;

    function show(next) {
        current = (next + slides.length) % slides.length;

        slides.forEach((el, i) => el.classList.toggle('is-active', i === current));
        dots.forEach((el, i) => {
            const active = i === current;
            el.classList.toggle('is-active', active);
            el.setAttribute('aria-selected', active ? 'true' : 'false');
            // Restart the progress fill animation on the newly active dot.
            const fill = el.querySelector('.dot-fill');
            if (fill) {
                fill.style.animation = 'none';
                void fill.offsetWidth;
                fill.style.animation = '';
            }
        });

        restart();
    }

    function restart() {
        clearTimeout(timer);
        if (!reduced) timer = setTimeout(() => show(current + 1), DURATION);
    }

    function pause(state) {
        slider.classList.toggle('is-paused', state);
        if (state) clearTimeout(timer);
        else restart();
    }

    dots.forEach(dot => dot.addEventListener('click', () => show(Number(dot.dataset.index))));
    slider.querySelector('[data-slide-prev]').addEventListener('click', () => show(current - 1));
    slider.querySelector('[data-slide-next]').addEventListener('click', () => show(current + 1));

    slider.addEventListener('mouseenter', () => pause(true));
    slider.addEventListener('mouseleave', () => pause(false));
    document.addEventListener('visibilitychange', () => pause(document.hidden));

    document.addEventListener('keydown', e => {
        const typing = /^(INPUT|TEXTAREA|SELECT)$/.test(document.activeElement?.tagName || '');
        if (typing) return;
        if (e.key === 'ArrowLeft')  show(current - 1);
        if (e.key === 'ArrowRight') show(current + 1);
    });

    // Touch swipe
    let startX = null;
    slider.addEventListener('touchstart', e => { startX = e.touches[0].clientX; pause(true); }, { passive: true });
    slider.addEventListener('touchend', e => {
        if (startX !== null) {
            const dx = e.changedTouches[0].clientX - startX;
            if (Math.abs(dx) > 45) show(current + (dx < 0 ? 1 : -1));
        }
        startX = null;
        pause(false);
    });

    restart();
})();

// Password visibility toggle
(function () {
    const btn   = document.getElementById('togglePassword');
    const input = document.getElementById('password');
    if (!btn || !input) return;

    btn.addEventListener('click', () => {
        const hidden = input.type === 'password';
        input.type = hidden ? 'text' : 'password';
        btn.setAttribute('aria-pressed', hidden ? 'true' : 'false');
        btn.setAttribute('aria-label', hidden ? 'Hide password' : 'Show password');
        btn.querySelector('[data-eye-open]').classList.toggle('hidden', hidden);
        btn.querySelector('[data-eye-off]').classList.toggle('hidden', !hidden);
    });
})();
</script>

</body>
</html>
