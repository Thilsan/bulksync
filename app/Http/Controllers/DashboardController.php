<?php

namespace App\Http\Controllers;

use App\Models\UploadSession;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(): View
    {
        $stats = [
            'total_sessions'   => UploadSession::count(),
            'completed'        => UploadSession::where('status', 'completed')->count(),
            'processing'       => UploadSession::where('status', 'processing')->count(),
            'failed'           => UploadSession::where('status', 'failed')->count(),
            'total_uploaded'   => UploadSession::sum('uploaded_files'),
            'total_matched'    => UploadSession::sum('matched_files'),
            'total_failed'     => UploadSession::sum('failed_files'),
            'total_skipped'    => UploadSession::sum('skipped_files'),
        ];

        $recentSessions = UploadSession::latest()->limit(10)->get();

        return view('dashboard', compact('stats', 'recentSessions'));
    }
}
