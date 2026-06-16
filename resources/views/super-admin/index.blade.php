@extends('layouts.app')
@section('title', 'Super Admin')
@section('page-title', 'Super Admin')

@section('content')
<div class="max-w-4xl mx-auto space-y-8" x-data="superAdmin()">

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
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-100 bg-gray-50 text-xs text-gray-500 uppercase tracking-wide">
                        <th class="px-5 py-3 text-left font-medium">Name</th>
                        <th class="px-5 py-3 text-left font-medium">Email</th>
                        <th class="px-5 py-3 text-left font-medium">Role</th>
                        <th class="px-5 py-3 text-left font-medium">Status</th>
                        <th class="px-5 py-3 text-right font-medium">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach($users as $user)
                    <tr class="{{ !$user->is_active ? 'opacity-50' : '' }}">
                        <td class="px-5 py-3 font-medium text-gray-900">
                            <div class="flex items-center gap-2">
                                <div class="w-7 h-7 rounded-full flex items-center justify-center text-xs font-semibold shrink-0"
                                     style="background-color:#1d5a74; color:white">
                                    {{ strtoupper(substr($user->name, 0, 2)) }}
                                </div>
                                {{ $user->name }}
                                @if($user->id === auth()->id())
                                <span class="text-xs text-gray-400">(you)</span>
                                @endif
                            </div>
                        </td>
                        <td class="px-5 py-3 text-gray-500">{{ $user->email }}</td>
                        <td class="px-5 py-3">
                            @if($user->is_super_admin)
                            <span class="inline-flex items-center gap-1 text-xs bg-purple-100 text-purple-700 px-2 py-0.5 rounded-full font-medium">
                                <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20">
                                    <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/>
                                </svg>
                                Super Admin
                            </span>
                            @else
                            <span class="text-xs text-gray-400">User</span>
                            @endif
                        </td>
                        <td class="px-5 py-3">
                            @if($user->is_active)
                            <span class="inline-flex items-center gap-1 text-xs bg-green-100 text-green-700 px-2 py-0.5 rounded-full font-medium">
                                <div class="w-1.5 h-1.5 rounded-full bg-green-500"></div>
                                Active
                            </span>
                            @else
                            <span class="inline-flex items-center gap-1 text-xs bg-red-100 text-red-600 px-2 py-0.5 rounded-full font-medium">
                                <div class="w-1.5 h-1.5 rounded-full bg-red-500"></div>
                                Inactive
                            </span>
                            @endif
                        </td>
                        <td class="px-5 py-3">
                            <div class="flex items-center justify-end gap-2">
                                @if($user->id !== auth()->id())
                                {{-- Toggle super admin --}}
                                <form method="POST" action="{{ route('super-admin.users.toggle-admin', $user) }}">
                                    @csrf
                                    <button type="submit"
                                        class="text-xs border border-gray-200 text-gray-500 px-2.5 py-1 rounded-lg hover:bg-gray-50 transition-colors"
                                        title="{{ $user->is_super_admin ? 'Revoke super admin' : 'Grant super admin' }}">
                                        {{ $user->is_super_admin ? 'Revoke Admin' : 'Make Admin' }}
                                    </button>
                                </form>
                                {{-- Toggle active --}}
                                <form method="POST" action="{{ route('super-admin.users.toggle', $user) }}"
                                      onsubmit="return confirm('{{ $user->is_active ? 'Deactivate' : 'Activate' }} {{ addslashes($user->name) }}?')">
                                    @csrf
                                    <button type="submit"
                                        class="text-xs px-2.5 py-1 rounded-lg transition-colors border
                                               {{ $user->is_active
                                                    ? 'border-red-200 text-red-500 hover:bg-red-50'
                                                    : 'border-green-200 text-green-600 hover:bg-green-50' }}">
                                        {{ $user->is_active ? 'Deactivate' : 'Activate' }}
                                    </button>
                                </form>
                                @endif
                            </div>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    {{-- ── Stores ─────────────────────────────────────────────────────── --}}
    <div>
        <div class="flex items-center justify-between mb-3">
            <h2 class="text-base font-semibold text-gray-800">All Stores</h2>
            <a href="{{ route('stores.index') }}"
               class="text-sm text-brand-600 hover:underline">Manage stores →</a>
        </div>

        <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-100 bg-gray-50 text-xs text-gray-500 uppercase tracking-wide">
                        <th class="px-5 py-3 text-left font-medium">Store Name</th>
                        <th class="px-5 py-3 text-left font-medium">Domain</th>
                        <th class="px-5 py-3 text-left font-medium">Status</th>
                        <th class="px-5 py-3 text-left font-medium">Created</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse($stores as $store)
                    <tr>
                        <td class="px-5 py-3 font-medium text-gray-900">
                            <div class="flex items-center gap-2">
                                {{ $store->name }}
                                @if($store->is_active)
                                <span class="text-xs bg-green-100 text-green-700 px-2 py-0.5 rounded-full font-medium">Active</span>
                                @endif
                            </div>
                        </td>
                        <td class="px-5 py-3 text-gray-500 font-mono text-xs">{{ $store->shopify_domain }}</td>
                        <td class="px-5 py-3">
                            <div class="w-2 h-2 rounded-full {{ $store->is_active ? 'bg-green-500' : 'bg-gray-300' }}"></div>
                        </td>
                        <td class="px-5 py-3 text-gray-400 text-xs">{{ $store->created_at->format('d M Y') }}</td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="4" class="px-5 py-6 text-center text-gray-400 text-sm">No stores added yet.</td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
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
