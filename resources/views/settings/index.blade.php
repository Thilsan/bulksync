@extends('layouts.app')
@section('title', 'Settings')
@section('page-title', 'Settings')

@section('content')
<div class="max-w-2xl mx-auto space-y-6" x-data="settingsPage()">

    <div class="bg-blue-50 border border-blue-200 rounded-xl px-5 py-4 text-sm text-blue-700">
        Shopify store credentials have moved to
        <a href="{{ route('stores.index') }}" class="font-semibold underline">Stores</a>.
    </div>

    <form method="POST" action="{{ route('settings.update') }}" class="space-y-6">
        @csrf
        @method('PUT')

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
                        <p class="text-xs text-gray-500">Azure App Registration credentials</p>
                    </div>
                </div>
                <button type="button" @click="testOneDrive()"
                    class="text-xs border border-gray-300 text-gray-700 px-3 py-1.5 rounded-lg hover:bg-gray-50 transition-colors">
                    Test Connection
                </button>
            </div>

            <div class="px-6 py-5 space-y-4">
                <div class="bg-blue-50 border border-blue-200 rounded-lg px-4 py-3 text-sm text-blue-800">
                    <p class="font-semibold mb-1">Azure App Registration Setup</p>
                    <ol class="list-decimal list-inside space-y-0.5 text-xs text-blue-700">
                        <li>Go to Azure Portal → Azure Active Directory → App registrations</li>
                        <li>New registration → Name it (e.g. BulkSync) → Register</li>
                        <li>Certificates & secrets → New client secret → Copy the value</li>
                        <li>API permissions → Add → Microsoft Graph → Application → Files.Read.All → Grant admin consent</li>
                        <li>Copy the Tenant ID, Client ID, and Client Secret below</li>
                    </ol>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1.5">Tenant ID</label>
                    <input type="text" name="onedrive_tenant_id" value="{{ $settings['onedrive_tenant_id'] }}"
                        placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx"
                        class="w-full rounded-lg border border-gray-300 px-4 py-2.5 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent">
                    <p class="text-xs text-gray-400 mt-1">Use <code>common</code> for personal Microsoft accounts</p>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1.5">Client ID (Application ID)</label>
                    <input type="text" name="onedrive_client_id" value="{{ $settings['onedrive_client_id'] }}"
                        placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx"
                        class="w-full rounded-lg border border-gray-300 px-4 py-2.5 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent">
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
                </div>

                {{-- OneDrive test result --}}
                <div x-show="onedriveResult" x-cloak
                     :class="onedriveOk ? 'bg-green-50 text-green-700 border-green-200' : 'bg-red-50 text-red-700 border-red-200'"
                     class="border rounded-lg px-3 py-2 text-sm" x-text="onedriveResult">
                </div>
            </div>
        </div>

        {{-- Save --}}
        <div class="flex gap-3">
            <button type="submit"
                class="bg-brand-600 hover:bg-brand-700 text-white px-6 py-2.5 rounded-lg text-sm font-semibold transition-colors">
                Save Settings
            </button>
        </div>
    </form>

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
