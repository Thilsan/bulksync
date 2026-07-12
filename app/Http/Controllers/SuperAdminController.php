<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Store;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class SuperAdminController extends Controller
{
    public function index(): View
    {
        $users  = User::orderByDesc('is_super_admin')->orderBy('name')->get();
        $stores = Store::orderByDesc('is_active')->orderBy('name')->get();

        return view('super-admin.index', compact('users', 'stores'));
    }

    public function activity(Request $request): View
    {
        $query = ActivityLog::with('user')->latest('created_at')->latest('id');

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->integer('user_id'));
        }

        if ($request->filled('action')) {
            $query->where('action', $request->input('action'));
        }

        if ($request->filled('date')) {
            $query->whereDate('created_at', $request->input('date'));
        }

        $logs  = $query->paginate(50)->withQueryString();
        $users = User::orderBy('name')->get(['id', 'name']);

        return view('super-admin.activity', compact('logs', 'users'));
    }

    public function storeUser(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name'            => ['required', 'string', 'max:255'],
            'email'           => ['required', 'email', 'unique:users,email'],
            'password'        => ['required', 'string', 'min:8', 'confirmed'],
            'is_super_admin'  => ['nullable', 'boolean'],
        ]);

        User::create([
            'name'           => $validated['name'],
            'email'          => $validated['email'],
            'password'       => Hash::make($validated['password']),
            'is_super_admin' => (bool) ($validated['is_super_admin'] ?? false),
            'is_active'      => true,
        ]);

        return back()->with('success', "User \"{$validated['name']}\" created.");
    }

    public function toggleUser(User $user): RedirectResponse
    {
        if ($user->id === auth()->id()) {
            return back()->with('error', 'You cannot deactivate your own account.');
        }

        $user->update(['is_active' => !$user->is_active]);

        $status = $user->is_active ? 'activated' : 'deactivated';

        return back()->with('success', "\"{$user->name}\" has been {$status}.");
    }

    public function toggleSuperAdmin(User $user): RedirectResponse
    {
        if ($user->id === auth()->id()) {
            return back()->with('error', 'You cannot change your own super admin status.');
        }

        $user->update(['is_super_admin' => !$user->is_super_admin]);

        $status = $user->is_super_admin ? 'granted super admin' : 'revoked super admin';

        return back()->with('success', "\"{$user->name}\" has been {$status}.");
    }

    public function updatePermissions(User $user, Request $request): RedirectResponse
    {
        $user->update([
            'perm_bulk_upload' => $request->boolean('perm_bulk_upload'),
            'perm_sku_checker' => $request->boolean('perm_sku_checker'),
            'perm_image_audit' => $request->boolean('perm_image_audit'),
            'perm_store_sync'  => $request->boolean('perm_store_sync'),
            'perm_ai_content'       => $request->boolean('perm_ai_content'),
            'perm_metafield_update' => $request->boolean('perm_metafield_update'),
        ]);

        return back()->with('success', "Permissions updated for \"{$user->name}\".");
    }

    public function updateStores(User $user, Request $request): RedirectResponse
    {
        $storeIds = $request->input('store_ids', []);
        $user->stores()->sync($storeIds);

        return back()->with('success', "Store access updated for \"{$user->name}\".");
    }
}
