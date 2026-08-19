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

    {{-- AI content generation reachability. A session stuck on 0% cannot say
         whether the key is wrong or the server simply has no route to Google;
         this can. --}}
    <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
        <div class="px-6 py-5 border-b border-gray-100 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <div class="w-9 h-9 rounded-lg bg-violet-100 flex items-center justify-center">
                    <svg class="w-5 h-5 text-violet-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"/>
                    </svg>
                </div>
                <div>
                    <h2 class="font-semibold text-gray-800">AI Content (Gemini)</h2>
                    <p class="text-xs text-gray-500">Checks that this server can reach Google and that the key works</p>
                </div>
            </div>
            <button type="button" @click="testGemini()"
                class="text-xs border border-gray-300 text-gray-700 px-3 py-1.5 rounded-lg hover:bg-gray-50 transition-colors">
                Test Connection
            </button>
        </div>

        <div class="px-6 py-5">
            <div x-show="geminiResult" x-cloak
                 :class="geminiOk ? 'bg-green-50 text-green-700 border-green-200' : 'bg-red-50 text-red-700 border-red-200'"
                 class="border rounded-lg px-3 py-2 text-sm" x-text="geminiResult">
            </div>
            <p x-show="!geminiResult" class="text-sm text-gray-500">
                Run this whenever AI content generation sits at 0% — it separates a bad key from blocked outbound access.
            </p>
        </div>
    </div>

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
            {{-- Offered while there is anything to test, including a dead token:
                 that is exactly when you want to know what Microsoft says. --}}
            @if($settings['onedrive_connected'] || $settings['onedrive_stale'])
            <button type="button" @click="testOneDrive()"
                class="text-xs border border-gray-300 text-gray-700 px-3 py-1.5 rounded-lg hover:bg-gray-50 transition-colors">
                Test Connection
            </button>
            @endif
        </div>

        <div class="px-6 py-5 space-y-4">

            {{-- Connection status --}}
            @if($settings['onedrive_connected'])
            <div class="flex items-start gap-2 bg-green-50 border border-green-200 rounded-lg px-4 py-3">
                <svg class="w-4 h-4 text-green-600 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                </svg>
                <div>
                    <span class="text-sm text-green-700 font-medium">OneDrive connected</span>
                    @if($settings['onedrive_expires_at'])
                        <p class="text-xs text-green-600/80">
                            Access token
                            {{ $settings['onedrive_expires_at']->isPast() ? 'expired' : 'valid until' }}
                            {{ $settings['onedrive_expires_at']->format('d M Y, H:i') }}{{ $settings['onedrive_expires_at']->isPast() ? ' — it renews itself on the next use' : '' }}.
                        </p>
                    @endif
                </div>
            </div>
            @elseif($settings['onedrive_stale'])
            {{-- A token with no way to renew it: this is what "connected" used to
                 look like right up until something tried to use it. --}}
            <div class="flex items-start gap-2 bg-red-50 border border-red-200 rounded-lg px-4 py-3">
                <svg class="w-4 h-4 text-red-600 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
                </svg>
                <div>
                    <span class="text-sm text-red-700 font-medium">OneDrive needs reconnecting</span>
                    <p class="text-xs text-red-600/90">
                        There is an old token on this account but no way to renew it — the sign-in was revoked or never
                        finished. Nothing will work until you click Reconnect.
                    </p>
                </div>
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

    {{-- The Product Request sheet's own Azure app — super admin only.

         Deliberately its own registration and its own sign-in: the sheet lives in
         somebody else's OneDrive and is read by a background job, so it signs in
         once as itself. Nothing here touches the shared credentials above. --}}
    @if(auth()->user()->is_super_admin)
    <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
        <div class="px-6 py-5 border-b border-gray-100 flex items-center gap-3">
            <div class="w-9 h-9 rounded-lg bg-gray-100 flex items-center justify-center">
                <svg class="w-5 h-5 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                </svg>
            </div>
            <div>
                <h2 class="font-semibold text-gray-800">Product Request Sheet Access</h2>
                <p class="text-xs text-gray-500">Its own Azure app and sign-in — used by nothing else</p>
            </div>
        </div>

        <div class="px-6 py-5 space-y-5">
            <div class="rounded-lg border px-4 py-3 text-sm
                        {{ $settings['pcr_onedrive_connected'] ? 'border-green-200 bg-green-50 text-green-800' : 'border-amber-200 bg-amber-50 text-amber-900' }}">
                @if($settings['pcr_onedrive_connected'])
                    Connected as <span class="font-medium">{{ $settings['pcr_onedrive_account'] ?? 'an unidentified account' }}</span>.
                    The sheet is read as this account, whoever is using the app.
                @else
                    Not connected. The sheet sync and the Shopify draft builder cannot read the sheet until
                    the account that owns it signs in here.
                @endif
            </div>

            <form method="POST" action="{{ route('settings.sheet-app') }}" class="space-y-4">
                @csrf
                @method('PUT')

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1.5">Tenant ID</label>
                    <input type="text" name="pcr_onedrive_tenant_id" value="{{ old('pcr_onedrive_tenant_id', $settings['pcr_onedrive_tenant_id']) }}"
                        class="w-full rounded-lg border border-gray-300 px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent">
                    <p class="text-xs text-gray-400 mt-1">From the app registration in <span class="font-medium">their</span> Azure, not yours.</p>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1.5">Client ID</label>
                    <input type="text" name="pcr_onedrive_client_id" value="{{ old('pcr_onedrive_client_id', $settings['pcr_onedrive_client_id']) }}"
                        class="w-full rounded-lg border border-gray-300 px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent">
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1.5">Client Secret</label>
                    <input type="password" name="pcr_onedrive_client_secret" autocomplete="new-password"
                        placeholder="{{ $settings['pcr_onedrive_secret_set'] ? 'Saved — leave blank to keep it' : '' }}"
                        class="w-full rounded-lg border border-gray-300 px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent">
                    <p class="text-xs text-gray-400 mt-1">
                        The <span class="font-medium">Value</span> from Azure → Certificates &amp; secrets, not the Secret ID —
                        the value is only shown when the secret is first created. Never shown back here; blank keeps the saved one.
                    </p>
                </div>

                <div class="rounded-lg bg-gray-50 border border-gray-200 px-4 py-3">
                    <p class="text-xs text-gray-600">
                        Add this exact redirect URI to that app registration, or the sign-in is refused:
                    </p>
                    <code class="block mt-1 text-xs text-gray-800 break-all">{{ str_replace('http://127.0.0.1', 'http://localhost', route('product-request-sheet.auth.callback')) }}</code>
                </div>

                <div class="flex flex-wrap items-center gap-2 pt-1">
                    <button type="submit"
                        class="bg-brand-600 hover:bg-brand-700 text-white px-5 py-2.5 rounded-lg text-sm font-semibold transition-colors">
                        Save App
                    </button>
                    <a href="{{ route('product-request-sheet.auth.redirect') }}"
                       class="bg-blue-600 hover:bg-blue-700 text-white px-5 py-2.5 rounded-lg text-sm font-semibold transition-colors">
                        {{ $settings['pcr_onedrive_connected'] ? 'Reconnect the sheet account' : 'Connect the sheet account' }}
                    </a>
                </div>
            </form>

            @if($settings['pcr_onedrive_connected'])
                <form method="POST" action="{{ route('product-request-sheet.auth.disconnect') }}"
                      onsubmit="return confirm('Disconnect? The sheet sync will stop until it is reconnected.');">
                    @csrf
                    <button type="submit" class="text-xs text-red-600 hover:text-red-700">Disconnect this account</button>
                </form>
            @endif
        </div>
    </div>
    @endif

    {{-- Mail / SMTP — super admin only --}}
    @if(auth()->user()->is_super_admin)
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-100 flex items-start gap-3">
            <div class="w-9 h-9 rounded-lg bg-brand-50 text-brand-600 flex items-center justify-center shrink-0">
                <svg class="w-4.5 h-4.5" style="width:1.125rem;height:1.125rem" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                </svg>
            </div>
            <div>
                <h2 class="text-base font-semibold text-gray-800">Mail (SMTP)</h2>
                <p class="text-sm text-gray-500 mt-0.5">
                    Used for every product creation request notification — assignments, deadlines, blockers and the daily digest.
                </p>
            </div>
        </div>

        <div class="px-6 py-5">

            {{-- What the app is actually using right now --}}
            <div class="rounded-lg border px-4 py-3 mb-5
                        {{ $mailState['sending'] ? 'border-green-200 bg-green-50/60' : 'border-amber-200 bg-amber-50/60' }}">
                <div class="flex items-start gap-2.5">
                    <span class="w-2 h-2 rounded-full mt-1.5 shrink-0 {{ $mailState['sending'] ? 'bg-green-500' : 'bg-amber-500' }}"></span>
                    <div class="text-sm">
                        @if($mailState['sending'])
                            <p class="font-medium text-green-900">Emails are being sent.</p>
                            <p class="text-xs text-green-800 mt-0.5">
                                {{ $mailState['host'] ?: 'no host' }}:{{ $mailState['port'] }}
                                &middot; from {{ $mailState['from'] }}
                                &middot; source: <span class="font-medium">{{ $mailState['source'] }}</span>
                            </p>
                        @else
                            <p class="font-medium text-amber-900">Emails are not being sent.</p>
                            <p class="text-xs text-amber-800 mt-0.5">
                                The mailer is set to <code class="bg-white px-1 rounded">log</code>, so notifications only appear in the
                                bell inside the app. Fill in the SMTP details below and tick "Send email through these settings".
                            </p>
                        @endif
                    </div>
                </div>
            </div>

            <form method="POST" action="{{ route('settings.mail.update') }}" class="space-y-4" x-data="{ on: {{ $mail['mail_enabled'] ? 'true' : 'false' }} }">
                @csrf
                @method('PUT')

                <label class="flex items-start gap-2.5 cursor-pointer select-none">
                    <input type="checkbox" name="mail_enabled" value="1" x-model="on"
                        class="w-4 h-4 mt-0.5 rounded border-gray-300 text-brand-600 focus:ring-brand-500">
                    <span>
                        <span class="text-sm text-gray-700">Send email through these settings</span>
                        <span class="block text-xs text-gray-400">
                            Leave unticked to use the server's <code class="bg-gray-100 px-1 rounded">.env</code> values instead.
                        </span>
                    </span>
                </label>

                <div x-show="on" x-cloak class="space-y-4 pt-1">
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                        <div class="sm:col-span-2">
                            <label class="block text-sm font-medium text-gray-700 mb-1.5">SMTP Host</label>
                            <input type="text" name="mail_host" value="{{ old('mail_host', $mail['mail_host']) }}"
                                placeholder="send.smtp.com"
                                class="w-full rounded-lg border border-gray-300 px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1.5">Port</label>
                            <input type="number" name="mail_port" value="{{ old('mail_port', $mail['mail_port'] ?: 587) }}"
                                placeholder="587"
                                class="w-full rounded-lg border border-gray-300 px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent">
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1.5">Username</label>
                            <input type="text" name="mail_username" value="{{ old('mail_username', $mail['mail_username']) }}"
                                placeholder="ecommerce@abuissa.com" autocomplete="off"
                                class="w-full rounded-lg border border-gray-300 px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent">
                        </div>
                        <div x-data="{ show: false }">
                            <label class="block text-sm font-medium text-gray-700 mb-1.5">Password</label>
                            <div class="relative">
                                <input :type="show ? 'text' : 'password'" name="mail_password" value="" autocomplete="new-password"
                                    placeholder="{{ $mail['mail_password_set'] ? 'Saved — leave blank to keep it' : 'SMTP password' }}"
                                    class="w-full rounded-lg border border-gray-300 px-4 py-2.5 text-sm pr-10 focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent">
                                <button type="button" @click="show = !show"
                                    class="absolute right-3 top-2.5 text-gray-400 hover:text-gray-600">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M15 12a3 3 0 11-6 0 3 3 0 016 0zM2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                                    </svg>
                                </button>
                            </div>
                            <p class="text-xs text-gray-400 mt-1">Never shown back for safety — blank keeps the saved one.</p>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1.5">Encryption</label>
                            <select name="mail_scheme"
                                class="w-full rounded-lg border border-gray-300 px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
                                <option value="auto" @selected($mail['mail_scheme'] !== 'smtps')>STARTTLS / automatic</option>
                                <option value="smtps" @selected($mail['mail_scheme'] === 'smtps')>SSL (port 465)</option>
                            </select>
                            <p class="text-xs text-gray-400 mt-1">Use automatic for ports 587 and 2525.</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1.5">From Address</label>
                            <input type="email" name="mail_from_address" value="{{ old('mail_from_address', $mail['mail_from_address']) }}"
                                placeholder="ecommerce@abuissa.com"
                                class="w-full rounded-lg border border-gray-300 px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1.5">From Name</label>
                            <input type="text" name="mail_from_name" value="{{ old('mail_from_name', $mail['mail_from_name']) }}"
                                placeholder="AI E-Commerce Studio"
                                class="w-full rounded-lg border border-gray-300 px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent">
                        </div>
                    </div>
                </div>

                <div class="pt-1">
                    <button type="submit"
                        class="bg-brand-600 hover:bg-brand-700 text-white text-sm font-medium px-5 py-2.5 rounded-lg transition-colors">
                        Save Mail Settings
                    </button>
                </div>
            </form>

            {{-- Test, so nobody has to raise a real request to find out it is broken --}}
            <div class="mt-6 pt-5 border-t border-gray-100">
                <form method="POST" action="{{ route('settings.mail.test') }}" class="flex flex-wrap items-end gap-3">
                    @csrf
                    <div class="flex-1 min-w-[14rem]">
                        <label class="block text-sm font-medium text-gray-700 mb-1.5">Send a test email to</label>
                        <input type="email" name="test_email" required value="{{ auth()->user()->email }}"
                            class="w-full rounded-lg border border-gray-300 px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent">
                    </div>
                    <button type="submit"
                        class="border border-gray-300 text-gray-700 text-sm font-medium px-5 py-2.5 rounded-lg hover:bg-gray-50 transition-colors">
                        Send Test
                    </button>
                </form>
                <p class="text-xs text-gray-400 mt-2">
                    Sent immediately rather than queued, so the result here is the real one — no worker involved.
                </p>
            </div>
        </div>
    </div>
    @endif

    {{-- Clear Cache (super admin only) --}}
    @if(auth()->user()->is_super_admin)
    <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
        <div class="px-6 py-5 border-b border-gray-100">
            <h2 class="font-semibold text-gray-800">System</h2>
            <p class="text-xs text-gray-500 mt-0.5">Server maintenance tools</p>
        </div>
        <div class="px-6 py-5">
            <form method="POST" action="{{ route('settings.clear-cache') }}">
                @csrf
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-700">Clear Application Cache</p>
                        <p class="text-xs text-gray-500 mt-0.5">Runs <code class="bg-gray-100 px-1 rounded">php artisan optimize:clear</code> — use if queue workers stop processing.</p>
                    </div>
                    <button type="submit"
                        class="bg-gray-800 hover:bg-gray-900 text-white px-4 py-2 rounded-lg text-sm font-medium transition-colors">
                        Clear Cache
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
        geminiResult:   '',
        geminiOk:       false,

        async testGemini() {
            this.geminiResult = 'Testing…';
            try {
                const res  = await fetch('{{ route('settings.test-gemini') }}', {
                    headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content }
                });
                const data = await res.json();
                this.geminiOk     = data.ok;
                this.geminiResult = data.message;
            } catch {
                this.geminiOk     = false;
                this.geminiResult = 'Request failed.';
            }
        },

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
