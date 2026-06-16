<?php

namespace App\Http\Controllers;

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
}
