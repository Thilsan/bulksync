@extends('layouts.app')
@section('title', 'Settings')
@section('page-title', 'Settings')

@section('content')
<div class="max-w-2xl mx-auto space-y-6" x-data="settingsPage()">

    @if(session('success'))
    <div class="bg-green-50 border border-green-200 text-green-700 rounded-xl px-5 py-4 text-sm">
        {{ session('success') }}
    </div>
    @endif

    @if($errors->any())
    <div class="bg-red-50 border border-red-200 text-red-700 rounded-xl px-5 py-4 text-sm">
        {{ $errors->first() }}
    </div>
    @endif

    {{-- OneDrive Settings --}}
    <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
        <div class="px-6 py-5 border-b border-gray-100 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <div class="w-9 h-9 rounded-lg bg-blue-100 flex items-center justify-center">
                    <svg class="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M3 15a4 4 0 004 4h9a5 5 0 10-.1-9.999 5.002 5.002 0 10-9.78 2.096A4.001 4.001 0 003 15z"/>
                    </svg>
                </div>
                <div>
                    <h2 class="font-semibold text-gray-800">Microsoft OneDrive</h2>
                    <p class="text-xs text-gray-500">Connect your Microsoft account to access your files</p>
                </div>
            </div>
            @if($settings['onedrive_connected'])
            <button type="button" @click="testOneDrive()"
                class="text-xs border border-gray-300 text-gray-700 px-3 py-1.5 rounded-lg hover:bg-gray-50 transition-colors">
                Test Connection
            </button>
            @endif
        </div>

        <div class="px-6 py-5 space-y-4">

            {{-- Connection status --}}
            @if($settings['onedrive_connected'])
            <div class="flex items-center gap-2 bg-green-50 border border-green-200 rounded-lg px-4 py-3">
                <svg class="w-4 h-4 text-green-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                </svg>
                <span class="text-sm text-green-700 font-medium">OneDrive connected</span>
            </div>
            @else
            <div class="flex items-center gap-2 bg-yellow-50 border border-yellow-200 rounded-lg px-4 py-3">
                <svg class="w-4 h-4 text-yellow-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
                </svg>
                <span class="text-sm text-yellow-700 font-medium">OneDrive not connected — click the button below to connect</span>
            </div>
            @endif

            {{-- Test result --}}
            <div x-show="onedriveResult" x-cloak
                 :class="onedriveOk ? 'bg-green-50 text-green-700 border-green-200' : 'bg-red-50 text-red-700 border-red-200'"
                 class="border rounded-lg px-3 py-2 text-sm" x-text="onedriveResult">
            </div>

            <div class="pt-1">
                <a href="{{ route('onedrive.auth.redirect') }}"
                   class="inline-block bg-blue-600 hover:bg-blue-700 text-white px-5 py-2.5 rounded-lg text-sm font-semibold transition-colors">
                    {{ $settings['onedrive_connected'] ? 'Reconnect OneDrive' : 'Connect OneDrive' }}
                </a>
            </div>
        </div>
    </div>

    {{-- Azure App Credentials — super admin only --}}
    @if(auth()->user()->is_super_admin)
    <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
        <div class="px-6 py-5 border-b border-gray-100 flex items-center gap-3">
            <div class="w-9 h-9 rounded-lg bg-gray-100 flex items-center justify-center">
                <svg class="w-5 h-5 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                </svg>
            </div>
            <div>
                <h2 class="font-semibold text-gray-800">Azure App Credentials</h2>
                <p class="text-xs text-gray-500">Shared Microsoft Azure app registration — super admin only</p>
            </div>
        </div>

        <div class="px-6 py-5 space-y-4">
            <div class="bg-amber-50 border border-amber-200 rounded-lg px-4 py-3 text-xs text-amber-700">
                These credentials are shared across all users. Changes affect everyone's OneDrive connection.
            </div>

            <form method="POST" action="{{ route('settings.update') }}" class="space-y-4">
                @csrf
                @method('PUT')

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1.5">Tenant ID</label>
                    <input type="text" name="onedrive_tenant_id" value="{{ $settings['onedrive_tenant_id'] }}"
                        placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx"
                        class="w-full rounded-lg border border-gray-300 px-4 py-2.5 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent">
                    <p class="text-xs text-gray-400 mt-1">Azure Portal → App registrations → your app → Overview (Directory tenant ID)</p>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1.5">Client ID</label>
                    <input type="text" name="onedrive_client_id" value="{{ $settings['onedrive_client_id'] }}"
                        placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx"
                        class="w-full rounded-lg border border-gray-300 px-4 py-2.5 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent">
                    <p class="text-xs text-gray-400 mt-1">Azure Portal → App registrations → your app → Overview</p>
                </div>

                <div x-data="{ show: false }">
                    <label class="block text-sm font-medium text-gray-700 mb-1.5">Client Secret</label>
                    <div class="relative">
                        <input :type="show ? 'text' : 'password'" name="onedrive_client_secret"
                            value="{{ $settings['onedrive_client_secret'] }}"
                            placeholder="Azure app client secret value"
                            class="w-full rounded-lg border border-gray-300 px-4 py-2.5 text-sm pr-10 focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent">
                        <button type="button" @click="show = !show"
                            class="absolute right-3 top-2.5 text-gray-400 hover:text-gray-600">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M15 12a3 3 0 11-6 0 3 3 0 016 0zM2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                            </svg>
                        </button>
                    </div>
                    <p class="text-xs text-gray-400 mt-1">Azure Portal → your app → Certificates & secrets</p>
                </div>

                <div class="pt-1">
                    <button type="submit"
                        class="bg-brand-600 hover:bg-brand-700 text-white px-5 py-2.5 rounded-lg text-sm font-semibold transition-colors">
                        Save Credentials
                    </button>
                </div>
            </form>
        </div>
    </div>
    @endif

</div>

<script>
function settingsPage() {
    return {
        onedriveResult: '',
        onedriveOk:     false,

        async testOneDrive() {
            this.onedriveResult = 'Testing…';
            try {
                const res  = await fetch('{{ route('settings.test-onedrive') }}', {
                    headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content }
                });
                const data = await res.json();
                this.onedriveOk     = data.ok;
                this.onedriveResult = data.message;
            } catch {
                this.onedriveOk     = false;
                this.onedriveResult = 'Request failed.';
            }
        },
    };
}
</script>
@endsection
