<!DOCTYPE html>
<html lang="en" class="h-full bg-gray-50">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'BulkSync') – Ai Ecommerce Studio</title>
    @include('partials.favicon')
    <script>
        /*
            Unread notifications are badged onto the favicon and the tab title,
            so a request waiting on you is visible from a background tab. The
            bell component below calls window.faviconBadge(n) whenever its
            count changes; 0 restores the plain icon and title.
        */
        window.faviconBadge = (function () {
            const staticLinks = Array.from(document.querySelectorAll('link[rel~="icon"]'));
            const baseTitle = document.title;
            let dynamic = null, base = null, current = null;

            const img = new Image();
            img.src = '{{ asset('favicon-96x96.png') }}';
            img.onload = () => { base = img; if (current) draw(current); };

            function draw(count) {
                if (!base) return;   // redrawn from onload once the icon lands
                const S = 64, canvas = document.createElement('canvas');
                canvas.width = canvas.height = S;
                const ctx = canvas.getContext('2d');
                ctx.drawImage(base, 0, 0, S, S);

                const r = S * 0.22, cx = S - r - 1, cy = r + 1;
                // Knock a transparent ring out of the tile first so the dot
                // still reads as a dot against a dark tab strip.
                ctx.globalCompositeOperation = 'destination-out';
                ctx.beginPath();
                ctx.arc(cx, cy, r + S * 0.05, 0, Math.PI * 2);
                ctx.fill();
                ctx.globalCompositeOperation = 'source-over';

                ctx.fillStyle = '#ef4444';
                ctx.beginPath();
                ctx.arc(cx, cy, r, 0, Math.PI * 2);
                ctx.fill();

                // Two digits are unreadable at 16px, so anything past 9 is "9+".
                const label = count > 9 ? '9+' : String(count);
                ctx.fillStyle = '#ffffff';
                ctx.font = 'bold ' + Math.round(r * (label.length > 1 ? 1.05 : 1.35)) +
                           'px -apple-system, "Segoe UI", Helvetica, sans-serif';
                ctx.textAlign = 'center';
                ctx.textBaseline = 'middle';
                ctx.fillText(label, cx, cy);

                try {
                    apply(canvas.toDataURL('image/png'));
                } catch (e) {
                    // Tainted canvas (icon served from another origin) — the
                    // title counter alone still carries the signal.
                }
            }

            function apply(href) {
                if (!dynamic) {
                    staticLinks.forEach(l => l.remove());
                    dynamic = document.createElement('link');
                    dynamic.rel = 'icon';
                    dynamic.type = 'image/png';
                    document.head.appendChild(dynamic);
                }
                dynamic.href = href;
            }

            function restore() {
                if (!dynamic) return;
                dynamic.remove();
                dynamic = null;
                staticLinks.forEach(l => document.head.appendChild(l));
            }

            return function (count) {
                count = parseInt(count, 10) || 0;
                if (count === current) return;
                current = count;
                document.title = count > 0
                    ? '(' + (count > 99 ? '99+' : count) + ') ' + baseTitle
                    : baseTitle;
                count > 0 ? draw(count) : restore();
            };
        })();

        /*
            faviconBadge takes one absolute number, so two features calling it
            directly would each erase the other's count. Everything that wants the
            tab to show something reports its own tally here instead, and the badge
            is drawn from the sum: notifications and chat can both be waiting.
        */
        window.tabBadge = (function () {
            const counts = {};

            return function (source, count) {
                counts[source] = parseInt(count, 10) || 0;
                window.faviconBadge(Object.values(counts).reduce((a, b) => a + b, 0));
            };
        })();
    </script>
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
                        }
                    }
                }
            }
        }
    </script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <style>
        [x-cloak] { display: none !important; }

        /* Sidebar ground: same gradient language as the sign-in showcase. */
        .app-sidebar {
            background:
                radial-gradient(620px 260px at 50% -10%, rgba(105,187,217,.20), transparent 70%),
                linear-gradient(180deg, #1d5a74 0%, #1a5069 48%, #12333f 100%);
        }
        /* A full-height scrollbar would cut the panel in half, so keep it hairline. */
        .nav-scroll { scrollbar-width: thin; scrollbar-color: rgba(255,255,255,.18) transparent; }
        .nav-scroll::-webkit-scrollbar { width: 6px; }
        .nav-scroll::-webkit-scrollbar-track { background: transparent; }
        .nav-scroll::-webkit-scrollbar-thumb { background: rgba(255,255,255,.18); border-radius: 999px; }
        .nav-scroll::-webkit-scrollbar-thumb:hover { background: rgba(255,255,255,.3); }

        .live-dot { animation: live 2.4s ease-in-out infinite; }
        @keyframes live { 0%,100% { opacity: 1 } 50% { opacity: .35 } }
        @media (prefers-reduced-motion: reduce) { .live-dot { animation: none } }
    </style>
</head>
<body class="h-full flex bg-gray-50" x-data="{ nav: false }" @keydown.escape.window="nav = false">

    {{--
        Sidebar. The nav is described as data rather than 12 near-identical
        anchors, so a new module is one array entry and the active/hover
        treatment can never drift between items. Icons are Heroicons outline
        paths; a few need two paths, hence the array.
    --}}
    @php
        $u = auth()->user();

        $ico = [
            'home'     => ['M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6'],
            'upload'   => ['M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12'],
            'history'  => ['M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2'],
            'photo'    => ['M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z'],
            'check'    => ['M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z'],
            'swap'     => ['M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4'],
            'doc'      => ['M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z'],
            'screen'   => ['M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z'],
            'tasks'    => ['M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01'],
            'store'    => ['M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4'],
            'cog'      => ['M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z', 'M15 12a3 3 0 11-6 0 3 3 0 016 0z'],
            'shield'   => ['M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z'],
            'clock'    => ['M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z'],
            'chat'     => ['M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.9 9.9 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z'],
            'sparkle'  => ['M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L21 12l-5.714 2.143L13 21l-2.286-6.857L5 12l5.714-2.143L13 3z'],
        ];

        $navGroups = [
            [
                'label' => null,
                'items' => [
                    ['label' => 'Dashboard', 'url' => route('dashboard'), 'icon' => 'home', 'on' => request()->routeIs('dashboard')],
                    ['label' => 'Chat', 'url' => route('chat.index'), 'icon' => 'chat', 'on' => request()->routeIs('chat.*'), 'badge' => $chatUnreadCount ?? 0],
                ],
            ],
            [
                'label' => 'Media',
                'items' => [
                    [
                        'label' => 'Image Upload',
                        'url'   => route('upload.dashboard'),
                        'icon'  => 'upload',
                        'on'    => request()->routeIs('upload.*'),
                        'show'  => $u->hasFeature('bulk_upload'),
                        // Parent links to the upload dashboard, so it isn't repeated here.
                        'children' => [
                            ['label' => 'New Upload',     'url' => route('upload.create'),  'on' => request()->routeIs('upload.create')],
                            ['label' => 'Upload History', 'url' => route('upload.history'), 'on' => request()->routeIs('upload.history')],
                        ],
                    ],
                    [
                        'label' => 'Photo Editor',
                        'url'   => route('photo-editor.index'),
                        'icon'  => 'sparkle',
                        'on'    => request()->routeIs('photo-editor.*'),
                        'show'  => $u->hasFeature('photo_editor'),
                        // Parent links to the new-edit form, so it isn't repeated here.
                        'children' => [
                            ['label' => 'Edit History', 'url' => route('photo-editor.history'), 'on' => request()->routeIs('photo-editor.history')],
                        ],
                    ],
                    ['label' => 'Image Audit', 'url' => route('image-audit.index'), 'icon' => 'photo', 'on' => request()->routeIs('image-audit.*'), 'show' => $u->hasFeature('image_audit')],
                ],
            ],
            [
                'label' => 'Catalogue',
                'items' => [
                    ['label' => 'Product Migration',    'url' => route('store-image-sync.index'),  'icon' => 'swap',   'on' => request()->routeIs('store-image-sync.*'),  'show' => $u->hasFeature('store_sync')],
                    ['label' => 'SKU Checker',          'url' => route('sku-checker.index'),       'icon' => 'check',  'on' => request()->routeIs('sku-checker.*'),       'show' => $u->hasFeature('sku_checker')],
                    ['label' => 'Metafield Checker',    'url' => route('metafield-update.index'),  'icon' => 'doc',    'on' => request()->routeIs('metafield-update.*'),  'show' => $u->hasFeature('metafield_update')],
                    [
                        'label' => 'AI Content Generator',
                        'url'   => route('ai-content.dashboard'),
                        'icon'  => 'screen',
                        'on'    => request()->routeIs('ai-content.*'),
                        'show'  => $u->hasFeature('ai_content'),
                        // Parent links to the overview, so it isn't repeated here.
                        'children' => [
                            ['label' => 'New Content',  'url' => route('ai-content.index'),   'on' => request()->routeIs('ai-content.index')],
                            ['label' => 'All Sessions', 'url' => route('ai-content.history'), 'on' => request()->routeIs('ai-content.history')],
                        ],
                    ],
                    [
                        'label' => 'Product Requests',
                        'url'   => route('product-requests.index'),
                        'icon'  => 'tasks',
                        'on'    => request()->routeIs('product-requests.*'),
                        'show'  => $u->hasFeature('product_request'),
                        'badge' => $bellUnreadCount ?? 0,
                        // No "Dashboard" entry — the parent link already goes there.
                        'children' => [
                            ['label' => 'All Requests',    'url' => route('product-requests.list'),            'on' => request()->routeIs('product-requests.list')],
                            ['label' => 'Photoshoot Room', 'url' => route('product-requests.photoshoot-room'), 'on' => request()->routeIs('product-requests.photoshoot-room*')],
                            ['label' => 'Assigned to Me',  'url' => route('product-requests.my-tasks'),        'on' => request()->routeIs('product-requests.my-tasks')],
                            ['label' => 'Notifications',   'url' => route('product-requests.notifications'),   'on' => request()->routeIs('product-requests.notifications'), 'badge' => $bellUnreadCount ?? 0],
                        ],
                    ],
                ],
            ],
            [
                'label' => 'Configuration',
                'items' => [
                    ['label' => 'Stores',   'url' => route('stores.index'),   'icon' => 'store', 'on' => request()->routeIs('stores.*')],
                    ['label' => 'Settings', 'url' => route('settings.index'), 'icon' => 'cog',   'on' => request()->routeIs('settings.*')],
                ],
            ],
            [
                'label' => 'Super Admin',
                'items' => [
                    ['label' => 'Admin Panel',  'url' => route('super-admin.index'),    'icon' => 'shield', 'on' => request()->routeIs('super-admin.index'),    'show' => (bool) $u?->is_super_admin],
                    ['label' => 'Activity Log', 'url' => route('super-admin.activity'), 'icon' => 'clock',  'on' => request()->routeIs('super-admin.activity'), 'show' => (bool) $u?->is_super_admin],
                ],
            ],
        ];

        // Drop hidden items, then any group left with nothing in it — so a
        // section heading never sits above an empty space.
        $navGroups = collect($navGroups)
            ->map(fn ($g) => [...$g, 'items' => array_values(array_filter($g['items'], fn ($i) => $i['show'] ?? true))])
            ->filter(fn ($g) => count($g['items']) > 0)
            ->values();

        /*
            Top-bar breadcrumb, read off the same nav data rather than declared
            per page. The section always shows; the parent is added only when a
            sub-page is open, so the crumb never just repeats the page title.
        */
        $crumbs = [];

        foreach ($navGroups as $group) {
            foreach ($group['items'] as $item) {
                if (! ($item['on'] ?? false)) {
                    continue;
                }

                if ($group['label']) {
                    $crumbs[] = ['label' => $group['label']];
                }

                foreach ($item['children'] ?? [] as $child) {
                    if ($child['on'] ?? false) {
                        $crumbs[] = ['label' => $item['label'], 'url' => $item['url']];
                        break;
                    }
                }

                break 2;
            }
        }
    @endphp

    {{-- Backdrop for the mobile drawer --}}
    <div x-show="nav" x-cloak @click="nav = false"
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
         x-transition:leave="transition ease-in duration-150"
         x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
         class="fixed inset-0 z-30 bg-slate-900/60 backdrop-blur-sm lg:hidden"></div>

    <aside class="app-sidebar fixed inset-y-0 left-0 z-40 flex w-64 shrink-0 flex-col overflow-hidden
                  transform transition-transform duration-200 ease-out
                  lg:static lg:h-screen lg:translate-x-0 lg:transition-none"
           :class="nav ? 'translate-x-0 shadow-2xl' : '-translate-x-full'">

        {{-- Brand --}}
        <div class="relative flex flex-col gap-3 px-4 pb-4 pt-5">
            <div class="flex items-start justify-between gap-2">
                <img src="{{ asset('aih_logo_whitegray-3.png') }}" alt="Abuissa Holding" class="h-9 w-auto">
                <button type="button" @click="nav = false"
                        class="-mr-1 grid h-8 w-8 shrink-0 place-items-center rounded-lg text-white/60 transition hover:bg-white/10 hover:text-white lg:hidden"
                        aria-label="Close menu">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>
            <div>
                <p class="text-sm font-semibold leading-tight text-white">Ai Ecommerce Studio</p>
                <p class="mt-0.5 text-[10px] uppercase tracking-[.14em] text-white/40">Abuissa Holding</p>
            </div>
        </div>

        <div class="relative mx-4 h-px bg-gradient-to-r from-transparent via-white/15 to-transparent"></div>

        {{-- Navigation --}}
        <nav class="nav-scroll relative flex-1 overflow-y-auto px-3 py-4">
            @foreach($navGroups as $gi => $group)
                @if($group['label'])
                    <p class="{{ $gi === 0 ? '' : 'mt-5' }} mb-1.5 px-3 text-[10px] font-semibold uppercase tracking-[.14em] text-white/35">
                        {{ $group['label'] }}
                    </p>
                @endif

                <div class="space-y-0.5">
                    @foreach($group['items'] as $item)
                        @php
                            $kids  = $item['children'] ?? [];
                            $badge = (int) ($item['badge'] ?? 0);
                        @endphp

                        @if($kids)
                            {{-- Parent with a sub-menu: the label navigates, the chevron only expands.
                                 It starts open whenever you are anywhere inside its section. --}}
                            <div x-data="{ open: {{ $item['on'] ? 'true' : 'false' }} }">
                                <div class="relative flex items-stretch rounded-lg transition-colors
                                            {{ $item['on'] ? 'bg-white/[.13] ring-1 ring-inset ring-white/10' : 'hover:bg-white/[.07]' }}">
                                    @if($item['on'])
                                        <span class="absolute left-0 top-1/2 h-5 w-[3px] -translate-y-1/2 rounded-r-full bg-brand-300"></span>
                                    @endif
                                    <a href="{{ $item['url'] }}" @click="open = true"
                                       @if($item['on']) aria-current="page" @endif
                                       class="flex min-w-0 flex-1 items-center gap-2.5 px-3 py-2 text-[13px] font-medium
                                              {{ $item['on'] ? 'text-white' : 'text-white/65 hover:text-white' }}">
                                        <svg class="h-4 w-4 shrink-0 {{ $item['on'] ? 'text-brand-200' : 'text-white/45' }}"
                                             fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.85">
                                            @foreach($ico[$item['icon']] as $d)
                                                <path stroke-linecap="round" stroke-linejoin="round" d="{{ $d }}"/>
                                            @endforeach
                                        </svg>
                                        <span class="truncate">{{ $item['label'] }}</span>
                                    </a>
                                    @if($badge > 0)
                                        <span class="flex shrink-0 items-center pr-1">
                                            <span class="rounded-full bg-red-500/90 px-1.5 py-px text-[10px] font-semibold tabular-nums text-white">
                                                {{ $badge > 99 ? '99+' : $badge }}
                                            </span>
                                        </span>
                                    @endif
                                    <button type="button" @click.stop="open = !open"
                                            class="flex shrink-0 items-center px-2 {{ $item['on'] ? 'text-white/70' : 'text-white/40 hover:text-white' }}"
                                            :aria-expanded="open" aria-label="Toggle {{ $item['label'] }} menu">
                                        <svg :class="open ? 'rotate-180' : ''" class="h-3.5 w-3.5 transition-transform"
                                             fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                                        </svg>
                                    </button>
                                </div>

                                <div x-show="open" x-cloak
                                     x-transition:enter="transition ease-out duration-150"
                                     x-transition:enter-start="opacity-0 -translate-y-1"
                                     x-transition:enter-end="opacity-100 translate-y-0"
                                     class="ml-[1.4rem] mt-0.5 space-y-0.5 border-l border-white/15 pl-3">
                                    @foreach($kids as $kid)
                                        @php $kidBadge = (int) ($kid['badge'] ?? 0); @endphp
                                        <a href="{{ $kid['url'] }}"
                                           @if($kid['on']) aria-current="page" @endif
                                           class="flex items-center gap-2 rounded-md px-2.5 py-1.5 text-xs font-medium transition-colors
                                                  {{ $kid['on'] ? 'bg-white/[.12] text-white' : 'text-white/55 hover:bg-white/[.07] hover:text-white' }}">
                                            <span class="flex-1 truncate">{{ $kid['label'] }}</span>
                                            @if($kidBadge > 0)
                                                <span class="shrink-0 text-[10px] font-semibold tabular-nums text-red-300">
                                                    {{ $kidBadge > 99 ? '99+' : $kidBadge }}
                                                </span>
                                            @endif
                                        </a>
                                    @endforeach
                                </div>
                            </div>
                        @else
                            <a href="{{ $item['url'] }}"
                               @if($item['on']) aria-current="page" @endif
                               class="relative flex items-center gap-2.5 rounded-lg px-3 py-2 text-[13px] font-medium transition-colors
                                      {{ $item['on']
                                          ? 'bg-white/[.13] text-white ring-1 ring-inset ring-white/10'
                                          : 'text-white/65 hover:bg-white/[.07] hover:text-white' }}">
                                @if($item['on'])
                                    {{-- Accent rail: marks the current page without relying on tint alone --}}
                                    <span class="absolute left-0 top-1/2 h-5 w-[3px] -translate-y-1/2 rounded-r-full bg-brand-300"></span>
                                @endif
                                <svg class="h-4 w-4 shrink-0 {{ $item['on'] ? 'text-brand-200' : 'text-white/45' }}"
                                     fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.85">
                                    @foreach($ico[$item['icon']] as $d)
                                        <path stroke-linecap="round" stroke-linejoin="round" d="{{ $d }}"/>
                                    @endforeach
                                </svg>
                                <span class="truncate">{{ $item['label'] }}</span>
                            </a>
                        @endif
                    @endforeach
                </div>
            @endforeach
        </nav>

        {{-- Clock --}}
        <div class="relative border-t border-white/10 px-4 py-3"
             x-data="{
                 tz: '{{ config('app.timezone') }}',
                 time: '',
                 date: '',
                 tick() {
                     const now = new Date();
                     this.time = now.toLocaleTimeString('en-GB', { timeZone: this.tz, hour: '2-digit', minute: '2-digit' });
                     this.date = now.toLocaleDateString('en-GB', { timeZone: this.tz, weekday: 'short', day: '2-digit', month: 'short', year: 'numeric' });
                 }
             }"
             x-init="tick(); setInterval(() => tick(), 1000)">
            <div class="flex items-baseline justify-between gap-2">
                <p class="flex items-center gap-2 text-base font-semibold tabular-nums text-white">
                    <span class="live-dot h-1.5 w-1.5 shrink-0 rounded-full bg-emerald-400"></span>
                    <span x-text="time">{{ now()->format('H:i') }}</span>
                </p>
                <p class="truncate text-[11px] text-white/45" x-text="date">{{ now()->format('D, d M Y') }}</p>
            </div>
        </div>

    </aside>

    {{-- Main content. `scrolled` lifts the top bar off the page once you scroll. --}}
    <div class="flex-1 flex flex-col overflow-hidden" x-data="{ scrolled: false }">

        {{-- Top bar --}}
        <header class="relative z-20 flex shrink-0 items-center justify-between gap-3 border-b bg-white px-4 py-3 transition-shadow sm:px-8"
                :class="scrolled ? 'border-transparent shadow-[0_1px_3px_rgba(15,23,42,.10),0_8px_24px_-16px_rgba(15,23,42,.25)]' : 'border-gray-200'">
            <div class="flex min-w-0 items-center gap-3">
                <button type="button" @click="nav = true"
                        class="-ml-1 grid h-9 w-9 shrink-0 place-items-center rounded-lg border border-gray-200 text-gray-500 transition-colors hover:bg-gray-50 hover:text-gray-700 lg:hidden"
                        aria-label="Open menu">
                    <svg class="h-4.5 w-4.5" style="width:1.125rem;height:1.125rem" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16"/>
                    </svg>
                </button>
                <div class="min-w-0">
                    @if($crumbs)
                        <nav class="flex items-center gap-1 text-[11px] font-medium text-gray-400" aria-label="Breadcrumb">
                            @foreach($crumbs as $i => $crumb)
                                @if($i > 0)
                                    <svg class="h-3 w-3 shrink-0 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/>
                                    </svg>
                                @endif
                                @if(!empty($crumb['url']))
                                    <a href="{{ $crumb['url'] }}" class="truncate transition-colors hover:text-gray-600">{{ $crumb['label'] }}</a>
                                @else
                                    <span class="truncate">{{ $crumb['label'] }}</span>
                                @endif
                            @endforeach
                        </nav>
                    @endif
                    <h1 class="truncate text-lg font-semibold leading-tight text-gray-900 sm:text-xl">@yield('page-title', 'Dashboard')</h1>
                </div>
            </div>

            <div class="flex items-center gap-2">
                {{-- Store switcher --}}
                @if($allStores->isNotEmpty())
                <div x-data="{ open: false }" class="relative">
                    <button @click="open = !open" :aria-expanded="open"
                        class="flex h-9 max-w-[13rem] items-center gap-2 rounded-lg border border-gray-200 bg-white px-3 text-sm transition-colors hover:border-gray-300 hover:bg-gray-50">
                        <span class="h-2 w-2 shrink-0 rounded-full {{ $activeStore ? 'bg-emerald-500' : 'bg-gray-300' }}"></span>
                        <span class="truncate font-medium text-gray-700">{{ $activeStore?->name ?? 'No store selected' }}</span>
                        <svg class="h-3 w-3 shrink-0 text-gray-400 transition-transform" :class="open && 'rotate-180'"
                             fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                        </svg>
                    </button>
                    <div x-show="open" @click.outside="open = false" x-cloak
                         x-transition:enter="transition ease-out duration-150"
                         x-transition:enter-start="opacity-0 -translate-y-1"
                         x-transition:enter-end="opacity-100 translate-y-0"
                         class="absolute right-0 z-50 mt-2 w-64 overflow-hidden rounded-xl border border-gray-200 bg-white shadow-lg ring-1 ring-black/5">
                        <p class="border-b border-gray-100 px-4 py-2.5 text-[11px] font-semibold uppercase tracking-wider text-gray-400">
                            Switch store
                        </p>
                        <div class="max-h-72 overflow-y-auto py-1">
                            @foreach($allStores as $s)
                            @php $isActive = $s->id === $activeStore?->id; @endphp
                            <form method="POST" action="{{ route('stores.switch', $s) }}">
                                @csrf
                                <button type="submit"
                                    class="flex w-full items-center gap-3 px-4 py-2 text-sm transition-colors hover:bg-gray-50
                                           {{ $isActive ? 'font-medium text-gray-900' : 'text-gray-600' }}">
                                    <span class="h-2 w-2 shrink-0 rounded-full {{ $isActive ? 'bg-emerald-500' : 'bg-gray-300' }}"></span>
                                    <span class="flex-1 truncate text-left">{{ $s->name }}</span>
                                    @if($isActive)
                                    <svg class="h-3.5 w-3.5 shrink-0 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                                    </svg>
                                    @endif
                                </button>
                            </form>
                            @endforeach
                        </div>
                        <a href="{{ route('stores.index') }}"
                           class="flex items-center justify-between border-t border-gray-100 bg-gray-50 px-4 py-2.5 text-xs font-medium text-gray-500 transition-colors hover:text-gray-800">
                            Manage stores
                            <span aria-hidden="true">&rarr;</span>
                        </a>
                    </div>
                </div>
                @endif
                {{-- Notification bell --}}
                @if(auth()->user()->hasFeature('product_request'))
                {{--
                    The bell polls for its own count so a notification that arrives
                    from a queued job or the hourly SKU check announces itself,
                    instead of waiting for the next page load. Anything genuinely
                    new also raises a toast, bottom-right.
                --}}
                <div x-data="{
                        bell: false,
                        unread: {{ (int) ($bellUnreadCount ?? 0) }},
                        seen: {{ Illuminate\Support\Js::from(($bellNotifications ?? collect())->pluck('id')) }},
                        toasts: [],
                        ring: false,
                        poll() {
                            if (document.hidden) return;
                            fetch('{{ route('product-requests.notifications.feed') }}', { headers: { 'Accept': 'application/json' } })
                                .then(r => r.ok ? r.json() : null)
                                .then(data => {
                                    if (!data) return;
                                    const fresh = data.items.filter(i => !this.seen.includes(i.id));
                                    this.unread = data.unread;
                                    if (!fresh.length) return;
                                    this.seen = data.items.map(i => i.id).concat(this.seen).slice(0, 50);
                                    this.ring = true;
                                    setTimeout(() => this.ring = false, 1200);
                                    // Three at once is a summary, not three toasts.
                                    fresh.slice(0, 3).forEach(item => this.announce(item));
                                })
                                .catch(() => {});
                        },
                        announce(item) {
                            const id = item.id;
                            this.toasts.push(item);
                            setTimeout(() => { this.toasts = this.toasts.filter(t => t.id !== id) }, 9000);
                        },
                        dismiss(id) { this.toasts = this.toasts.filter(t => t.id !== id) },
                     }"
                     x-init="tabBadge('bell', unread);
                             $watch('unread', v => tabBadge('bell', v));
                             setInterval(() => poll(), 30000)"
                     class="relative">
                    <button @click="bell = !bell" :aria-expanded="bell"
                            class="relative flex h-9 w-9 items-center justify-center rounded-lg border border-gray-200 bg-white text-gray-500 transition-colors hover:border-gray-300 hover:bg-gray-50 hover:text-gray-700"
                            :class="ring && 'ring-2 ring-red-400 border-red-300 text-red-600'"
                            aria-label="Notifications">
                        <svg class="w-4.5 h-4.5" style="width:1.125rem;height:1.125rem" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1h6z"/>
                        </svg>
                        <span x-show="unread > 0" x-cloak
                              class="absolute -top-1 -right-1 px-1 h-4 rounded-full bg-red-500 text-white text-[10px] font-semibold flex items-center justify-center"
                              style="min-width:1rem"
                              x-text="unread > 99 ? '99+' : unread"></span>
                    </button>

                    {{-- Toasts. Fixed to the viewport so they are visible wherever
                         the page is scrolled. --}}
                    <div class="fixed bottom-5 right-5 z-[60] w-80 max-w-[calc(100vw-2.5rem)] space-y-2" x-cloak>
                        <template x-for="toast in toasts" :key="toast.id">
                            <div x-transition:enter="transition ease-out duration-200"
                                 x-transition:enter-start="translate-y-3 opacity-0"
                                 x-transition:enter-end="translate-y-0 opacity-100"
                                 class="bg-white rounded-xl shadow-lg border border-gray-200 px-4 py-3 flex items-start gap-3">
                                <span class="w-2 h-2 rounded-full bg-red-500 shrink-0 mt-1.5"></span>
                                <div class="min-w-0 flex-1">
                                    <a :href="toast.url" class="text-sm font-medium text-gray-900 hover:text-brand-700 block truncate" x-text="toast.title"></a>
                                    <p class="text-xs text-gray-500 truncate" x-text="toast.body"></p>
                                </div>
                                <button type="button" @click="dismiss(toast.id)" class="text-gray-300 hover:text-gray-600 shrink-0" aria-label="Dismiss">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                                    </svg>
                                </button>
                            </div>
                        </template>
                    </div>

                    <div x-show="bell" @click.outside="bell = false" x-cloak
                         class="absolute right-0 mt-2 w-96 max-w-[calc(100vw-2rem)] bg-white rounded-xl shadow-lg border border-gray-200 z-50 overflow-hidden">

                        <div class="px-4 py-3 border-b border-gray-100 flex items-center justify-between">
                            <div>
                                <p class="text-sm font-semibold text-gray-800">Your notifications</p>
                                <p class="text-xs text-gray-400">
                                    <span x-show="unread > 0"><span x-text="unread"></span> unread</span>
                                    <span x-show="unread < 1">Nothing waiting on you</span>
                                </p>
                            </div>
                            @if(($bellUnreadCount ?? 0) > 0)
                            <form method="POST" action="{{ route('product-requests.notifications.read') }}">
                                @csrf
                                <button type="submit" class="text-xs text-brand-600 hover:text-brand-700 font-medium">Mark all read</button>
                            </form>
                            @endif
                        </div>

                        <div class="max-h-96 overflow-y-auto divide-y divide-gray-50">
                            @forelse($bellNotifications ?? collect() as $note)
                                @php
                                    $d        = $note->data;
                                    $assigned = ($d['kind'] ?? null) === 'assigned';
                                @endphp
                                <a href="{{ !empty($d['request_id']) ? route('product-requests.show', $d['request_id']) : route('product-requests.notifications') }}"
                                   class="flex items-start gap-3 px-4 py-3 hover:bg-gray-50 transition-colors bg-brand-50/40">
                                    <div class="w-7 h-7 rounded-lg flex items-center justify-center shrink-0 mt-0.5
                                                {{ $assigned ? 'bg-amber-100 text-amber-700' : 'bg-brand-100 text-brand-700' }}">
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                d="{{ $assigned ? 'M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z' : 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z' }}"/>
                                        </svg>
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        @if($assigned)
                                            <p class="text-sm text-gray-800">
                                                <span class="font-medium">{{ $d['reference'] ?? 'A request' }}</span>
                                                {{-- A copy names whoever actually got the job. --}}
                                                @if(!empty($d['assignee']))
                                                    — {{ $d['assignee'] }} is the
                                                @else
                                                    assigned to you as
                                                @endif
                                                <span class="font-medium">{{ $d['role'] ?? 'owner' }}</span>
                                            </p>
                                            <p class="text-xs text-gray-500 truncate">{{ $d['brand'] ?? '' }} &middot; {{ $d['status_label'] ?? '' }}</p>
                                        @else
                                            <p class="text-sm text-gray-800">
                                                <span class="font-medium">{{ $d['reference'] ?? 'A request' }}</span> is now
                                                <span class="font-medium">{{ $d['status_label'] ?? 'updated' }}</span>
                                            </p>
                                            <p class="text-xs text-gray-500 truncate">{{ $d['brand'] ?? '' }}</p>
                                        @endif
                                        <p class="text-xs text-gray-400 mt-0.5">by {{ $d['actor'] ?? 'System' }} &middot; {{ $note->created_at->diffForHumans() }}</p>
                                    </div>
                                    <span class="w-1.5 h-1.5 rounded-full bg-brand-500 shrink-0 mt-2"></span>
                                </a>
                            @empty
                                <div class="px-4 py-10 text-center">
                                    <p class="text-sm text-gray-500">Nothing waiting on you.</p>
                                    <a href="{{ route('product-requests.notifications', ['scope' => 'all']) }}" class="text-xs text-brand-600 hover:text-brand-700 font-medium mt-1 inline-block">
                                        See the team's updates &rarr;
                                    </a>
                                </div>
                            @endforelse
                        </div>

                        <div class="px-4 py-2.5 bg-gray-50 border-t border-gray-100 flex items-center justify-between">
                            <a href="{{ route('product-requests.my-tasks') }}" class="text-xs text-gray-600 hover:text-gray-900 font-medium">Assigned to me</a>
                            <a href="{{ route('product-requests.notifications') }}" class="text-xs text-brand-600 hover:text-brand-700 font-medium">All notifications &rarr;</a>
                        </div>
                    </div>
                </div>
                @endif

                {{-- Utility controls end here; the account sits on its own --}}
                <span class="mx-1 hidden h-6 w-px bg-gray-200 sm:block"></span>

                {{-- User menu --}}
                <div x-data="{ user: false }" class="relative">
                    <button @click="user = !user" :aria-expanded="user"
                            class="flex h-9 items-center gap-2 rounded-lg border border-gray-200 bg-white py-1 pl-1 pr-2 transition-colors hover:border-gray-300 hover:bg-gray-50"
                            aria-label="Account menu">
                        <span class="grid h-7 w-7 shrink-0 place-items-center rounded-full bg-brand-600 text-xs font-semibold text-white">
                            {{ strtoupper(substr(auth()->user()->name, 0, 2)) }}
                        </span>
                        <span class="hidden max-w-32 truncate text-sm font-medium text-gray-700 sm:block">{{ auth()->user()->name }}</span>
                        <svg class="h-3 w-3 shrink-0 text-gray-400 transition-transform" :class="user && 'rotate-180'"
                             fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                        </svg>
                    </button>

                    <div x-show="user" @click.outside="user = false" x-cloak
                         x-transition:enter="transition ease-out duration-150"
                         x-transition:enter-start="opacity-0 -translate-y-1"
                         x-transition:enter-end="opacity-100 translate-y-0"
                         class="absolute right-0 z-50 mt-2 w-64 overflow-hidden rounded-xl border border-gray-200 bg-white shadow-lg ring-1 ring-black/5">
                        <div class="flex items-center gap-3 border-b border-gray-100 px-4 py-3">
                            <span class="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-brand-600 text-sm font-semibold text-white">
                                {{ strtoupper(substr(auth()->user()->name, 0, 2)) }}
                            </span>
                            <div class="min-w-0">
                                <div class="flex items-center gap-1.5">
                                    <p class="truncate text-sm font-semibold text-gray-800">{{ auth()->user()->name }}</p>
                                    @if(auth()->user()?->is_super_admin)
                                        {{-- A badge on the person, not a heading for the menu items below --}}
                                        <span class="shrink-0 rounded bg-brand-50 px-1.5 py-px text-[10px] font-semibold uppercase tracking-wide text-brand-700 ring-1 ring-inset ring-brand-200">
                                            Admin
                                        </span>
                                    @endif
                                </div>
                                <p class="truncate text-xs text-gray-500">{{ auth()->user()->email }}</p>
                            </div>
                        </div>
                        <div class="py-1">
                            <a href="{{ route('settings.index') }}"
                               class="flex w-full items-center gap-2.5 px-4 py-2 text-sm text-gray-600 transition-colors hover:bg-gray-50 hover:text-gray-900">
                                <svg class="h-4 w-4 shrink-0 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.8">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                </svg>
                                Settings
                            </a>
                            {{-- Chat history lives in this browser, so signing out
                                 has to take it with it — otherwise the next person
                                 at a shared desk could read the conversations. --}}
                            <form method="POST" action="{{ route('logout') }}"
                                  @submit="window.chatHistory?.forgetAll()">
                                @csrf
                                <button type="submit"
                                        class="flex w-full items-center gap-2.5 px-4 py-2 text-sm text-gray-600 transition-colors hover:bg-gray-50 hover:text-gray-900">
                                    <svg class="h-4 w-4 shrink-0 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.8">
                                        <path stroke-linecap="round" stroke-linejoin="round"
                                            d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
                                    </svg>
                                    Log out
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </header>

        {{-- Flash messages --}}
        <div class="px-8 pt-4 space-y-2">
            @if (session('success'))
                <div class="flex items-center gap-3 bg-green-50 border border-green-200 text-green-800 rounded-lg px-4 py-3 text-sm">
                    <svg class="w-4 h-4 shrink-0" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                    </svg>
                    {{ session('success') }}
                </div>
            @endif
            @if (session('warning'))
                <div class="flex items-center gap-3 bg-yellow-50 border border-yellow-200 text-yellow-800 rounded-lg px-4 py-3 text-sm">
                    <svg class="w-4 h-4 shrink-0" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
                    </svg>
                    {{ session('warning') }}
                </div>
            @endif
            @if ($errors->any())
                <div class="bg-red-50 border border-red-200 text-red-800 rounded-lg px-4 py-3 text-sm">
                    <ul class="list-disc list-inside space-y-1">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>

        {{-- Page content --}}
        {{-- This is the scrolling element, not the window, so the top bar's
             shadow has to be driven from here. --}}
        <main class="flex-1 overflow-y-auto px-4 py-6 sm:px-8"
              @scroll.passive="scrolled = $event.target.scrollTop > 4">
            @yield('content')
        </main>

        {{-- Footer --}}
        <footer class="shrink-0 border-t border-gray-200 bg-white px-8 py-3 text-center text-xs text-gray-900">
            Powered by the Abuissa Holding E-Commerce Department
        </footer>

    </div>

    {{-- Chat. The runtime holds this browser's own history and must load before
         either the widget or the full-page view reads from it. --}}
    @auth
        @include('partials.chat-runtime')
        @include('partials.chat-widget')
    @endauth

</body>
</html>
