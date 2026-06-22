<?php

namespace App\Http\Controllers;

use App\Models\SkuCheckSession;
use App\Models\UploadSession;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(): View
    {
        $user  = auth()->user();
        $query = UploadSession::query();

        if (!$user->is_super_admin) {
            $query->where('user_id', $user->id);
        }

        $stats = [
            'total_sessions'   => (clone $query)->count(),
            'completed'        => (clone $query)->where('status', 'completed')->count(),
            'processing'       => (clone $query)->where('status', 'processing')->count(),
            'failed'           => (clone $query)->where('status', 'failed')->count(),
            'total_uploaded'   => (clone $query)->sum('uploaded_files'),
            'total_matched'    => (clone $query)->sum('matched_files'),
            'total_failed'     => (clone $query)->sum('failed_files'),
            'total_skipped'    => (clone $query)->sum('skipped_files'),
        ];

        $recentSessions = (clone $query)->latest()->limit(10)->get();

        $skuQuery = SkuCheckSession::query();
        if (!$user->is_super_admin) {
            $skuQuery->where('user_id', $user->id);
        }

        $skuStats = [
            'total_checks'    => (clone $skuQuery)->count(),
            'total_skus'      => (clone $skuQuery)->sum('total_skus'),
            'total_available' => (clone $skuQuery)->sum('available_count'),
            'total_not_found' => (clone $skuQuery)->sum('not_available_count'),
        ];

        $recentSkuChecks = (clone $skuQuery)->with('store')->latest()->limit(5)->get();

        return view('dashboard', compact('stats', 'recentSessions', 'skuStats', 'recentSkuChecks'));
    }
}
