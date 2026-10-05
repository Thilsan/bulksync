<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\ProductRequest;
use App\Models\ProductRequestActivity;
use App\Models\Store;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class SuperAdminController extends Controller
{
    public function index(): View
    {
        $users  = User::orderByDesc('is_super_admin')->orderBy('name')->get();
        $stores = Store::orderBy('name')->get();

        // One list of eighty people tells you nothing about who is who. Grouped,
        // "which of them is the Watches brand manager" is a glance instead of a
        // read — and a deactivated account stops sitting among the working ones.
        $groups = [];

        $put = function (string $label, User $member) use (&$groups) {
            $groups[$label][] = $member;
        };

        foreach ($users as $member) {
            match (true) {
                !$member->is_active     => $put('Deactivated', $member),
                $member->is_super_admin => $put('Super Admins', $member),
                (bool) $member->pcr_role => $put($member->pcrRoleLabel(), $member),
                default                 => $put('No workflow role', $member),
            };
        }

        // Ordered deliberately: the people who can change anything first, then
        // each workflow role, then accounts with no part in it.
        $order = array_merge(
            ['Super Admins'],
            array_values(User::PCR_ROLES),
            ['No workflow role', 'Deactivated'],
        );

        $ordered = [];

        foreach ($order as $label) {
            if (!empty($groups[$label])) {
                $ordered[$label] = collect($groups[$label]);
            }
        }

        return view('super-admin.index', [
            'users'          => $users,
            'userGroups'     => $ordered,
            'stores'         => $stores,
            // Shown against each category so it is obvious who holds it already.
            'categoryOwners'        => User::categoryOwners(),
            'categoryBrandManagers' => User::categoryBrandManagers(),
            // Which of them actually gets the Brand Manager task, so the screen
            // stops implying nobody does.
            'brandManagerAssignees' => User::brandManagerMap(),
            'storeCategoryBrandManagers' => User::storeCategoryBrandManagers(),
            // Brands worth naming individually are the ones we actually have
            // requests for; there is no brand list to pick from otherwise.
            'knownBrands'           => ProductRequest::knownBrands(),
            'brandOwners'           => User::brandOwnerMap(),
            'storeCategoryOwners'   => User::storeCategoryOwners(),
            'brandManagersByBrand'  => User::brandManagerByBrandMap(),
        ]);
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

    /**
     * The top bar's live ticker: who is doing what, newest first. Page views and
     * sign-ins come from the activity log, workflow moves from the request
     * history — the latter reads like news, the former like presence.
     */
    public function liveFeed(Request $request): JsonResponse
    {
        $viewerId = $request->user()->id;
        // Only what is happening now: anything older than half an hour is
        // history, and the Activity Log is where history lives.
        $since    = now()->subMinutes(30);

        $logs = ActivityLog::with('user:id,name')
            ->whereIn('action', [ActivityLog::ACTION_PAGE_VIEW, ActivityLog::ACTION_LOGIN, ActivityLog::ACTION_LOGOUT])
            ->whereNotNull('user_id')
            ->where('user_id', '!=', $viewerId)
            ->where('created_at', '>=', $since)
            ->latest('created_at')->latest('id')
            ->limit(80)
            ->get()
            ->map(fn (ActivityLog $log) => [
                'id'   => "log-{$log->id}",
                'user' => $log->user?->name ?? 'Someone',
                'text' => match ($log->action) {
                    ActivityLog::ACTION_LOGIN  => 'signed in',
                    ActivityLog::ACTION_LOGOUT => 'signed out',
                    default                    => 'is on ' . $log->description,
                },
                'kind' => $log->action,
                'at'   => $log->created_at,
                'url'  => null,
                'key'  => "{$log->user_id}|{$log->action}|{$log->description}",
            ]);

        $moves = ProductRequestActivity::with(['user:id,name', 'productRequest:id,reference'])
            ->whereNotNull('user_id')
            ->where('user_id', '!=', $viewerId)
            ->where('created_at', '>=', $since)
            ->latest('created_at')->latest('id')
            ->limit(30)
            ->get()
            ->map(fn (ProductRequestActivity $move) => [
                'id'   => "pcr-{$move->id}",
                'user' => $move->actorName(),
                'text' => trim(lcfirst($move->description) . ($move->productRequest ? " · {$move->productRequest->reference}" : '')),
                'kind' => 'request',
                'at'   => $move->created_at,
                'url'  => $move->productRequest ? route('product-requests.show', $move->product_request_id) : null,
                'key'  => "pcr-{$move->id}",
            ]);

        // A refresh or a back-and-forth between two tabs would otherwise fill the
        // strip with the same line; keep only the latest of each repeat.
        $items = $logs->concat($moves)
            ->sortByDesc('at')
            ->unique('key')
            ->take(25)
            ->map(fn (array $item) => [
                'id'   => $item['id'],
                'user' => $item['user'],
                'text' => $item['text'],
                'kind' => $item['kind'],
                'ago'  => $item['at']->diffForHumans(short: true),
                'url'  => $item['url'],
            ])
            ->values();

        $online = ActivityLog::where('created_at', '>=', $since)
            ->whereNotNull('user_id')
            ->where('user_id', '!=', $viewerId)
            ->distinct()
            ->count('user_id');

        return response()->json(['online' => $online, 'items' => $items]);
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
        // Only categories we actually trade in, in the order the dropdown shows them.
        $categories = array_values(array_intersect(
            ProductRequest::CATEGORIES,
            (array) $request->input('pcr_categories', []),
        ));

        $brandCategories = array_values(array_intersect(
            ProductRequest::CATEGORIES,
            (array) $request->input('pcr_brand_categories', []),
        ));

        // Brands are stored uppercased and trimmed: the sheet writes
        // "COLE HAAN ", "Cole Haan" and "RAGO " for the same brands, and a
        // setting matched literally would miss most of them.
        // "<store id>|<category>", and only for pairings that exist.
        $validPairings = [];

        foreach (Store::pluck('id') as $storeId) {
            foreach (ProductRequest::CATEGORIES as $category) {
                $validPairings[] = User::storeCategoryKey($storeId, $category);
            }
        }

        $storeCategories = array_values(array_intersect(
            $validPairings,
            (array) $request->input('pcr_store_categories', []),
        ));

        $brandStoreCategories = array_values(array_intersect(
            $validPairings,
            (array) $request->input('pcr_brand_store_categories', []),
        ));

        $known        = ProductRequest::knownBrands();
        $ownedBrands  = array_values(array_intersect($known, array_map(
            fn ($b) => User::normalizeBrand($b), (array) $request->input('pcr_owned_brands', []),
        )));
        $managedBrands = array_values(array_intersect($known, array_map(
            fn ($b) => User::normalizeBrand($b), (array) $request->input('pcr_managed_brands', []),
        )));

        $user->update([
            'perm_bulk_upload' => $request->boolean('perm_bulk_upload'),
            'perm_sku_checker' => $request->boolean('perm_sku_checker'),
            'perm_image_audit' => $request->boolean('perm_image_audit'),
            'perm_store_sync'  => $request->boolean('perm_store_sync'),
            'perm_ai_content'       => $request->boolean('perm_ai_content'),
            'perm_metafield_update' => $request->boolean('perm_metafield_update'),
            'perm_product_request'  => $request->boolean('perm_product_request'),
            'perm_photo_editor'     => $request->boolean('perm_photo_editor'),
            'perm_orders_dashboard' => $request->boolean('perm_orders_dashboard'),
            'perm_barcode_images'   => $request->boolean('perm_barcode_images'),
            'perm_seo_audit'        => $request->boolean('perm_seo_audit'),
            'perm_product_performance' => $request->boolean('perm_product_performance'),
            'pcr_role'              => $request->input('pcr_role') ?: null,
            'pcr_categories'        => $categories ?: null,
            'pcr_brand_categories'  => $brandCategories ?: null,
            'pcr_store_categories'  => $storeCategories ?: null,
            'pcr_brand_store_categories' => $brandStoreCategories ?: null,
            'pcr_owned_brands'      => $ownedBrands ?: null,
            'pcr_managed_brands'    => $managedBrands ?: null,
            'pcr_notify_all'        => $request->boolean('pcr_notify_all'),
        ]);

        // A category belongs to one person, so giving it to this user takes it off
        // whoever held it — otherwise two owners claim it and the request form has
        // to guess which one gets the work.
        $takenOver = [];

        if ($categories) {
            foreach (User::where('id', '!=', $user->id)->whereNotNull('pcr_categories')->get() as $other) {
                $keep = array_values(array_diff($other->pcr_categories ?? [], $categories));

                if (count($keep) !== count($other->pcr_categories ?? [])) {
                    $other->update(['pcr_categories' => $keep ?: null]);
                    $takenOver[] = $other->name;
                }
            }
        }

        if ($storeCategories) {
            foreach (User::where('id', '!=', $user->id)->whereNotNull('pcr_store_categories')->get() as $other) {
                $keep = array_values(array_diff($other->pcr_store_categories ?? [], $storeCategories));

                if (count($keep) !== count($other->pcr_store_categories ?? [])) {
                    $other->update(['pcr_store_categories' => $keep ?: null]);
                    $takenOver[] = $other->name;
                }
            }
        }

        if ($ownedBrands) {
            foreach (User::where('id', '!=', $user->id)->whereNotNull('pcr_owned_brands')->get() as $other) {
                $keep = array_values(array_diff($other->pcr_owned_brands ?? [], $ownedBrands));

                if (count($keep) !== count($other->pcr_owned_brands ?? [])) {
                    $other->update(['pcr_owned_brands' => $keep ?: null]);
                    $takenOver[] = $other->name;
                }
            }
        }

        return back()->with('success', "Permissions updated for \"{$user->name}\"."
            . ($takenOver ? ' Categories and brands were taken off ' . implode(', ', array_unique($takenOver)) . '.' : ''));
    }

    public function updateStores(User $user, Request $request): RedirectResponse
    {
        $storeIds = $request->input('store_ids', []);
        $user->stores()->sync($storeIds);

        return back()->with('success', "Store access updated for \"{$user->name}\".");
    }
}
