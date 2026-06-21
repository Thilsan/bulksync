<!DOCTYPE html>
<html lang="en" class="h-full" style="background-color:#1d5a74">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign in – BulkSync</title>
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
</head>
<body class="h-full flex items-center justify-center px-4">

    <div class="w-full max-w-md">
        {{-- Logo --}}
        <div class="flex flex-col items-center mb-8">
            <img src="/aih_logo_whitegray-3.png" alt="Logo" class="h-16 w-auto mb-4">
            <div class="flex items-center gap-3 mb-2">
                <svg class="w-10 h-10 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                </svg>
                <span class="text-white font-bold text-xl">Bulk Image Uploader</span>
            </div>
        </div>

        {{-- Card --}}
        <div class="bg-white rounded-2xl shadow-2xl p-8">
            <h2 class="text-2xl font-bold text-gray-900 mb-1">Welcome back</h2>
            <p class="text-sm text-gray-500 mb-6">Sign in to your admin account</p>

            @if ($errors->any())
                <div class="mb-4 bg-red-50 border border-red-200 text-red-700 rounded-lg px-4 py-3 text-sm">
                    {{ $errors->first() }}
                </div>
            @endif

            <form method="POST" action="{{ route('login') }}" class="space-y-5">
                @csrf

                <div>
                    <label for="email" class="block text-sm font-medium text-gray-700 mb-1.5">Email address</label>
                    <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus
                        class="w-full rounded-lg border border-gray-300 px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent transition"
                        placeholder="admin@example.com">
                </div>

                <div>
                    <label for="password" class="block text-sm font-medium text-gray-700 mb-1.5">Password</label>
                    <input id="password" type="password" name="password" required
                        class="w-full rounded-lg border border-gray-300 px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent transition"
                        placeholder="••••••••">
                </div>

                <div class="flex items-center justify-between">
                    <label class="flex items-center gap-2 text-sm text-gray-600 cursor-pointer">
                        <input type="checkbox" name="remember"
                            class="rounded border-gray-300 text-brand-600 focus:ring-brand-500">
                        Remember me
                    </label>
                </div>

                <button type="submit"
                    class="w-full bg-brand-600 hover:bg-brand-700 text-white font-semibold py-2.5 rounded-lg text-sm transition-colors focus:outline-none focus:ring-2 focus:ring-brand-500 focus:ring-offset-2">
                    Sign in
                </button>
            </form>
        </div>

        <p class="text-center text-xs text-white/70 mt-6">
            BulkSync v1.0 — Internal admin tool
        </p>
    </div>

</body>
</html>
