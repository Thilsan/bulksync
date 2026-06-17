<?php

namespace App\Http\Controllers;

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

        return view('dashboard', compact('stats', 'recentSessions'));
    }
}
