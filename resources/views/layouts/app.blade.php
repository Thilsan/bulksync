<!DOCTYPE html>
<html lang="en" class="h-full bg-gray-50">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'BulkSync') – AI Ecommerce Studio</title>
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
    <style>[x-cloak] { display: none !important; }</style>
</head>
<body class="h-full flex" x-data>

    {{-- Sidebar --}}
    <aside class="w-56 shrink-0 flex flex-col min-h-screen" style="background-color:#1d5a74">

        {{-- Logo --}}
        <div class="flex flex-col items-center px-5 py-4 border-b border-white/10 gap-2">
            <img src="{{ asset('aih_logo_whitegray-3.png') }}" alt="Logo" class="h-10 w-auto">
            <div class="flex items-center gap-2">
                <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                </svg>
                <span class="text-white font-semibold text-base tracking-tight">AI Ecommerce Studio</span>
            </div>
        </div>

        {{-- Navigation --}}
        <nav class="flex-1 px-3 py-4 space-y-0.5">

            <a href="{{ route('dashboard') }}"
               class="flex items-center gap-3 px-3 py-2 rounded-md text-sm font-medium transition-colors
                      {{ request()->routeIs('dashboard') ? 'bg-white/20 text-white' : 'text-white/70 hover:bg-white/10 hover:text-white' }}">
                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>
                </svg>
                <span>Dashboard</span>
            </a>

            @if(auth()->user()->hasFeature('bulk_upload'))
            <a href="{{ route('upload.create') }}"
               class="flex items-center gap-3 px-3 py-2 rounded-md text-sm font-medium transition-colors
                      {{ request()->routeIs('upload.create') ? 'bg-white/20 text-white' : 'text-white/70 hover:bg-white/10 hover:text-white' }}">
                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/>
                </svg>
                <span>Image Upload</span>
            </a>

            <a href="{{ route('upload.history') }}"
               class="flex items-center gap-3 px-3 py-2 rounded-md text-sm font-medium transition-colors
                      {{ request()->routeIs('upload.history') ? 'bg-white/20 text-white' : 'text-white/70 hover:bg-white/10 hover:text-white' }}">
                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
                </svg>
                <span>Image Upload History</span>
            </a>
            @endif

            @if(auth()->user()->hasFeature('sku_checker'))
            <a href="{{ route('sku-checker.index') }}"
               class="flex items-center gap-3 px-3 py-2 rounded-md text-sm font-medium transition-colors
                      {{ request()->routeIs('sku-checker.*') ? 'bg-white/20 text-white' : 'text-white/70 hover:bg-white/10 hover:text-white' }}">
                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                <span>SKU Checker</span>
            </a>
            @endif

            @if(auth()->user()->hasFeature('image_audit'))
            <a href="{{ route('image-audit.index') }}"
               class="flex items-center gap-3 px-3 py-2 rounded-md text-sm font-medium transition-colors
                      {{ request()->routeIs('image-audit.*') ? 'bg-white/20 text-white' : 'text-white/70 hover:bg-white/10 hover:text-white' }}">
                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                </svg>
                <span>Image Audit</span>
            </a>
            @endif

            @if(auth()->user()->hasFeature('store_sync'))
            <a href="{{ route('store-image-sync.index') }}"
               class="flex items-center gap-3 px-3 py-2 rounded-md text-sm font-medium transition-colors
                      {{ request()->routeIs('store-image-sync.*') ? 'bg-white/20 text-white' : 'text-white/70 hover:bg-white/10 hover:text-white' }}">
                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/>
                </svg>
                <span>Store Image Migrate</span>
            </a>
            @endif

            @if(auth()->user()->hasFeature('metafield_update'))
            <a href="{{ route('metafield-update.index') }}"
               class="flex items-center gap-3 px-3 py-2 rounded-md text-sm font-medium transition-colors
                      {{ request()->routeIs('metafield-update.*') ? 'bg-white/20 text-white' : 'text-white/70 hover:bg-white/10 hover:text-white' }}">
                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                </svg>
                <span>Metafield Update</span>
            </a>
            @endif

            @if(auth()->user()->hasFeature('ai_content'))
            <a href="{{ route('ai-content.index') }}"
               class="flex items-center gap-3 px-3 py-2 rounded-md text-sm font-medium transition-colors
                      {{ request()->routeIs('ai-content.*') ? 'bg-white/20 text-white' : 'text-white/70 hover:bg-white/10 hover:text-white' }}">
                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                </svg>
                <span>AI Description Generator</span>
            </a>
            @endif

            <a href="{{ route('stores.index') }}"
               class="flex items-center gap-3 px-3 py-2 rounded-md text-sm font-medium transition-colors
                      {{ request()->routeIs('stores.*') ? 'bg-white/20 text-white' : 'text-white/70 hover:bg-white/10 hover:text-white' }}">
                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/>
                </svg>
                <span>Stores</span>
            </a>

            <a href="{{ route('settings.index') }}"
               class="flex items-center gap-3 px-3 py-2 rounded-md text-sm font-medium transition-colors
                      {{ request()->routeIs('settings.*') ? 'bg-white/20 text-white' : 'text-white/70 hover:bg-white/10 hover:text-white' }}">
                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                </svg>
                <span>Settings</span>
            </a>

        </nav>

        {{-- Super admin section --}}
        @if(auth()->user()?->is_super_admin)
        <div class="px-3 pb-2">
            <div class="border-t border-white/10 pt-3 mb-1">
                <p class="px-3 text-xs font-semibold uppercase tracking-wider mb-1" style="color:rgba(255,255,255,0.35)">Super Admin</p>
            </div>
            <a href="{{ route('super-admin.index') }}"
               class="flex items-center gap-3 px-3 py-2 rounded-md text-sm font-medium transition-colors
                      {{ request()->routeIs('super-admin.*') ? 'bg-white/20 text-white' : 'text-white/70 hover:bg-white/10 hover:text-white' }}">
                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                </svg>
                <span>Admin Panel</span>
            </a>
        </div>
        @endif

        {{-- User footer --}}
        <div class="px-3 py-4 border-t border-white/10">
            <div class="flex items-center gap-2.5">
                <div class="w-7 h-7 rounded-full bg-white/20 flex items-center justify-center text-white text-xs font-semibold shrink-0">
                    {{ strtoupper(substr(auth()->user()->name, 0, 2)) }}
                </div>
                <div class="flex-1 min-w-0">
                    <p class="text-xs font-semibold text-white truncate">{{ auth()->user()->name }}</p>
                    <p class="text-xs text-white/60 truncate">{{ auth()->user()->email }}</p>
                </div>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" title="Logout" class="text-white/60 hover:text-white transition-colors">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
                        </svg>
                    </button>
                </form>
            </div>
        </div>

    </aside>

    {{-- Main content --}}
    <div class="flex-1 flex flex-col overflow-hidden">

        {{-- Top bar --}}
        <header class="bg-white border-b border-gray-200 px-8 py-4 flex items-center justify-between shrink-0">
            <h1 class="text-xl font-semibold text-gray-800">@yield('page-title', 'Dashboard')</h1>
            <div class="flex items-center gap-4">
                {{-- Store switcher --}}
                @if($allStores->isNotEmpty())
                <div x-data="{ open: false }" class="relative">
                    <button @click="open = !open"
                        class="flex items-center gap-2 text-sm border border-gray-200 rounded-lg px-3 py-1.5 hover:bg-gray-50 transition-colors">
                        <div class="w-2 h-2 rounded-full {{ $activeStore ? 'bg-green-500' : 'bg-gray-300' }} shrink-0"></div>
                        <span class="text-gray-700 font-medium">{{ $activeStore?->name ?? 'No store selected' }}</span>
                        <svg class="w-3 h-3 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                        </svg>
                    </button>
                    <div x-show="open" @click.outside="open = false" x-cloak
                         class="absolute right-0 mt-1 w-56 bg-white rounded-lg shadow-lg border border-gray-200 py-1 z-50">
                        @foreach($allStores as $s)
                        <form method="POST" action="{{ route('stores.switch', $s) }}">
                            @csrf
                            <button type="submit"
                                class="w-full flex items-center gap-3 px-4 py-2 text-sm hover:bg-gray-50 transition-colors
                                       {{ $s->is_active ? 'text-gray-900 font-medium' : 'text-gray-500' }}">
                                <div class="w-2 h-2 rounded-full shrink-0 {{ $s->is_active ? 'bg-green-500' : 'bg-gray-300' }}"></div>
                                <span class="flex-1 text-left truncate">{{ $s->name }}</span>
                                @if($s->is_active)
                                <svg class="w-3.5 h-3.5 text-green-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/>
                                </svg>
                                @endif
                            </button>
                        </form>
                        @endforeach
                        <div class="border-t border-gray-100 mt-1 pt-1">
                            <a href="{{ route('stores.index') }}"
                               class="flex items-center gap-2 px-4 py-2 text-xs text-gray-400 hover:text-gray-600 hover:bg-gray-50 transition-colors">
                                Manage stores →
                            </a>
                        </div>
                    </div>
                </div>
                @endif
                <div class="text-sm text-gray-400">{{ now()->format('D, d M Y') }}</div>
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
        <main class="flex-1 overflow-y-auto px-8 py-6">
            @yield('content')
        </main>

        {{-- Footer --}}
        <footer class="shrink-0 border-t border-gray-200 bg-white px-8 py-3 text-center text-xs text-gray-900">
            Powered by the Abuissa Holding E-Commerce Department
        </footer>

    </div>

</body>
</html>
