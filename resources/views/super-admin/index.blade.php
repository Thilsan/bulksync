@extends('layouts.app')
@section('title', 'Super Admin')
@section('page-title', 'Super Admin')

@section('content')
<div class="max-w-5xl mx-auto space-y-8" x-data="superAdmin()">

    @if(session('success'))
    <div class="bg-green-50 border border-green-200 rounded-xl px-5 py-3 text-sm text-green-700">
        {{ session('success') }}
    </div>
    @endif

    {{-- ── Users ─────────────────────────────────────────────────────── --}}
    <div>
        <div class="flex items-center justify-between mb-3">
            <h2 class="text-base font-semibold text-gray-800">Users</h2>
            <button type="button" @click="addOpen = !addOpen"
                class="flex items-center gap-1.5 text-sm bg-brand-600 hover:bg-brand-700 text-white px-3 py-1.5 rounded-lg transition-colors font-medium">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                </svg>
                Add User
            </button>
        </div>

        {{-- Add user form --}}
        <div x-show="addOpen" x-cloak class="bg-white rounded-xl border border-gray-200 overflow-hidden mb-4">
            <form method="POST" action="{{ route('super-admin.users.store') }}">
                @csrf
                <div class="px-6 py-5 grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Name</label>
                        <input type="text" name="name" required value="{{ old('name') }}"
                            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Email</label>
                        <input type="email" name="email" required value="{{ old('email') }}"
                            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Password</label>
                        <input type="password" name="password" required
                            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Confirm Password</label>
                        <input type="password" name="password_confirmation" required
                            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent">
                    </div>
                    <div class="col-span-2">
                        <label class="flex items-center gap-2 cursor-pointer select-none">
                            <input type="checkbox" name="is_super_admin" value="1" class="rounded border-gray-300 text-brand-600 focus:ring-brand-500">
                            <span class="text-sm text-gray-700">Grant super admin access</span>
                        </label>
                    </div>
                </div>
                <div class="px-6 py-3 bg-gray-50 border-t border-gray-100 flex items-center justify-end gap-2">
                    <button type="button" @click="addOpen = false"
                        class="text-sm border border-gray-200 text-gray-500 px-4 py-2 rounded-lg hover:bg-white transition-colors">
                        Cancel
                    </button>
                    <button type="submit"
                        class="text-sm bg-brand-600 hover:bg-brand-700 text-white px-4 py-2 rounded-lg transition-colors font-medium">
                        Create User
                    </button>
                </div>
            </form>
        </div>

        {{-- Users table --}}
        <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
            @foreach($users as $user)
            <div x-data="{ open: false }" class="border-b border-gray-100 last:border-0">

                {{-- User row --}}
                <div class="flex items-center gap-3 px-5 py-3">
                    <div class="w-8 h-8 rounded-full flex items-center justify-center text-xs font-semibold shrink-0"
                         style="background-color:#1d5a74; color:white">
                        {{ strtoupper(substr($user->name, 0, 2)) }}
                    </div>

                    <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-2">
                            <span class="text-sm font-medium text-gray-900">{{ $user->name }}</span>
                            @if($user->id === auth()->id())
                                <span class="text-xs text-gray-400">(you)</span>
                            @endif
                            @if($user->is_super_admin)
                                <span class="inline-flex items-center gap-1 text-xs bg-purple-100 text-purple-700 px-2 py-0.5 rounded-full font-medium">
                                    <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/></svg>
                                    Super Admin
                                </span>
                            @endif
                            @if(!$user->is_active)
                                <span class="text-xs bg-red-100 text-red-600 px-2 py-0.5 rounded-full font-medium">Inactive</span>
                            @endif
                        </div>
                        <p class="text-xs text-gray-400 mt-0.5">{{ $user->email }}</p>
                    </div>

                    {{-- Quick actions --}}
                    <div class="flex items-center gap-2 shrink-0">
                        @if(!$user->is_super_admin)
                        <button type="button" @click="open = !open"
                            :class="open ? 'bg-brand-600 text-white border-brand-600' : 'border-gray-200 text-gray-600 hover:bg-gray-50'"
                            class="text-xs border px-3 py-1.5 rounded-lg transition-colors font-medium flex items-center gap-1">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                            </svg>
                            Manage
                        </button>
                        @endif
                        @if($user->id !== auth()->id())
                        <form method="POST" action="{{ route('super-admin.users.toggle-admin', $user) }}">
                            @csrf
                            <button type="submit" class="text-xs border border-gray-200 text-gray-500 px-2.5 py-1.5 rounded-lg hover:bg-gray-50 transition-colors">
                                {{ $user->is_super_admin ? 'Revoke Admin' : 'Make Admin' }}
                            </button>
                        </form>
                        <form method="POST" action="{{ route('super-admin.users.toggle', $user) }}"
                              onsubmit="return confirm('{{ $user->is_active ? 'Deactivate' : 'Activate' }} {{ addslashes($user->name) }}?')">
                            @csrf
                            <button type="submit"
                                class="text-xs px-2.5 py-1.5 rounded-lg transition-colors border
                                       {{ $user->is_active ? 'border-red-200 text-red-500 hover:bg-red-50' : 'border-green-200 text-green-600 hover:bg-green-50' }}">
                                {{ $user->is_active ? 'Deactivate' : 'Activate' }}
                            </button>
                        </form>
                        @endif
                    </div>
                </div>

                {{-- Expandable: Permissions + Stores --}}
                @if(!$user->is_super_admin)
                <div x-show="open" x-cloak class="border-t border-gray-100 bg-gray-50 px-5 py-4 grid grid-cols-1 md:grid-cols-2 gap-6">

                    {{-- Feature Permissions --}}
                    <div>
                        <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-3">Feature Access</p>
                        <form method="POST" action="{{ route('super-admin.users.permissions', $user) }}" class="space-y-2">
                            @csrf
                            @php
                                $features = [
                                    'bulk_upload' => 'Bulk Upload',
                                    'sku_checker' => 'SKU Checker',
                                    'image_audit' => 'Image Audit',
                                    'store_sync'  => 'Store Image Sync',
                                ];
                            @endphp
                            @foreach($features as $key => $label)
                            <label class="flex items-center gap-2.5 cursor-pointer select-none group">
                                <input type="checkbox" name="perm_{{ $key }}" value="1"
                                    {{ $user->{"perm_{$key}"} ? 'checked' : '' }}
                                    class="w-4 h-4 rounded border-gray-300 text-brand-600 focus:ring-brand-500">
                                <span class="text-sm text-gray-700 group-hover:text-gray-900">{{ $label }}</span>
                            </label>
                            @endforeach
                            <div class="pt-2">
                                <button type="submit"
                                    class="text-xs bg-brand-600 hover:bg-brand-700 text-white px-3 py-1.5 rounded-lg transition-colors font-medium">
                                    Save Permissions
                                </button>
                            </div>
                        </form>
                    </div>

                    {{-- Store Access --}}
                    <div>
                        <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-3">Store Access</p>
                        <form method="POST" action="{{ route('super-admin.users.stores', $user) }}" class="space-y-2">
                            @csrf
                            @php $userStoreIds = $user->stores->pluck('id')->toArray(); @endphp
                            @forelse($stores as $store)
                            <label class="flex items-center gap-2.5 cursor-pointer select-none group">
                                <input type="checkbox" name="store_ids[]" value="{{ $store->id }}"
                                    {{ in_array($store->id, $userStoreIds) ? 'checked' : '' }}
                                    class="w-4 h-4 rounded border-gray-300 text-brand-600 focus:ring-brand-500">
                                <span class="text-sm text-gray-700 group-hover:text-gray-900">{{ $store->name }}</span>
                                @if($store->is_active)
                                    <span class="text-xs bg-green-100 text-green-600 px-1.5 py-0.5 rounded-full">Active</span>
                                @endif
                            </label>
                            @empty
                            <p class="text-sm text-gray-400">No stores added yet.</p>
                            @endforelse
                            @if($stores->isNotEmpty())
                            <div class="pt-2">
                                <button type="submit"
                                    class="text-xs bg-brand-600 hover:bg-brand-700 text-white px-3 py-1.5 rounded-lg transition-colors font-medium">
                                    Save Stores
                                </button>
                            </div>
                            @endif
                        </form>
                    </div>

                </div>
                @endif
            </div>
            @endforeach
        </div>
    </div>

</div>

<script>
function superAdmin() {
    return {
        addOpen: {{ $errors->any() ? 'true' : 'false' }},
    };
}
</script>
@endsection
