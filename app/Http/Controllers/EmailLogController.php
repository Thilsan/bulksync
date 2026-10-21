<?php

namespace App\Http\Controllers;

use App\Models\EmailLog;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * Every email the app sent — who to, about what, and whether it went.
 */
class EmailLogController extends Controller
{
    public function index(Request $request): View
    {
        $query = EmailLog::with(['user:id,name', 'productRequest:id,reference'])
            ->latest('created_at')->latest('id');

        if ($request->filled('q')) {
            $term = '%' . str_replace(['%', '_'], ['\%', '\_'], trim($request->input('q'))) . '%';
            $query->where(fn ($q) => $q->where('to_address', 'like', $term)->orWhere('subject', 'like', $term));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('date')) {
            $query->whereDate('created_at', $request->input('date'));
        }

        $logs = $query->paginate(50)->withQueryString();

        return view('super-admin.emails.index', compact('logs'));
    }

    public function show(EmailLog $emailLog): View
    {
        return view('super-admin.emails.show', ['log' => $emailLog->load(['user', 'productRequest'])]);
    }

    /**
     * The email as it was sent, for the frame on the detail page.
     *
     * Its own response with a sandboxing policy: the HTML went out to an inbox,
     * not to this app, and must not run anything or reach the page around it.
     */
    public function body(EmailLog $emailLog): Response
    {
        abort_if($emailLog->body === null, 404);

        return response($emailLog->body, 200, [
            'Content-Type'            => 'text/html; charset=UTF-8',
            'Content-Security-Policy' => "sandbox; default-src 'none'; img-src * data:; style-src 'unsafe-inline'",
            'X-Frame-Options'         => 'SAMEORIGIN',
        ]);
    }
}
