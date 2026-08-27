<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateAiContentJob;
use App\Models\AiContentSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Operational view of the queues.
 *
 * Diagnosing a stalled batch used to mean SSH plus tinker: how deep is the
 * queue, are the workers even alive, what did the exception actually say, and
 * then a hand-written dispatch to start the work again. Every one of those is a
 * question this page answers, and the two that matter most — restarting workers
 * after a deploy, and resuming a session that stopped short — are the ones that
 * previously needed a command nobody could be expected to remember.
 */
class QueueController extends Controller
{
    /**
     * Queues worth showing. Only 'bulkupload' is dispatched to by the app, but a
     * worker also runs on 'maintenance', and 'default' catches anything queued
     * without an explicit name — a job landing there is itself worth seeing,
     * since nothing is listening to it.
     */
    private const QUEUES = ['bulkupload', 'maintenance', 'default'];

    public function index(): View
    {
        return view('queues.index', $this->snapshot());
    }

    /** Polled by the page so the numbers move without a reload. */
    public function status(): JsonResponse
    {
        return response()->json($this->snapshot());
    }

    private function snapshot(): array
    {
        return [
            'queues'   => $this->queueDepths(),
            'workers'  => $this->workers(),
            'failed'   => $this->failedJobs(),
            'sessions' => $this->resumableSessions(),
            'driver'   => config('queue.default'),
        ];
    }

    /**
     * Pending job count per queue.
     *
     * Queue::size() is asked rather than counting the jobs table directly: this
     * app runs on Redis in production and the jobs table is empty there, so a
     * table count would report a reassuring zero while work piled up unseen.
     */
    private function queueDepths(): array
    {
        $depths = [];

        foreach (self::QUEUES as $queue) {
            try {
                $depths[] = ['name' => $queue, 'size' => Queue::size($queue), 'error' => null];
            } catch (\Throwable $e) {
                $depths[] = ['name' => $queue, 'size' => null, 'error' => Str::limit($e->getMessage(), 120)];
            }
        }

        return $depths;
    }

    /**
     * The worker processes, with how long each has been alive.
     *
     * Age is the point of this, not presence. A worker loads the code once and
     * keeps it in memory for its whole life, so one that has been up since before
     * the last deploy is still running the old code no matter what is on disk —
     * which is exactly how a fixed bug went on being reported as broken.
     */
    private function workers(): array
    {
        if (!function_exists('shell_exec') || in_array('shell_exec', explode(',', (string) ini_get('disable_functions')), true)) {
            return ['available' => false, 'list' => []];
        }

        $output = (string) @shell_exec('ps -eo etimes,args 2>/dev/null');
        $list   = [];

        foreach (explode("\n", $output) as $line) {
            if (!str_contains($line, 'queue:work') || str_contains($line, 'grep')) {
                continue;
            }

            $line    = trim($line);
            $seconds = (int) strtok($line, ' ');

            preg_match('/--queue=([\w,-]+)/', $line, $q);

            $list[] = [
                'queue'   => $q[1] ?? 'default',
                'seconds' => $seconds,
                'age'     => $this->humanAge($seconds),
                'stale'   => $seconds > 86400,
            ];
        }

        return ['available' => true, 'list' => $list];
    }

    private function humanAge(int $seconds): string
    {
        return match (true) {
            $seconds < 60    => $seconds . 's',
            $seconds < 3600  => intdiv($seconds, 60) . 'm',
            $seconds < 86400 => intdiv($seconds, 3600) . 'h ' . intdiv($seconds % 3600, 60) . 'm',
            default          => intdiv($seconds, 86400) . 'd ' . intdiv($seconds % 86400, 3600) . 'h',
        };
    }

    /**
     * Failed jobs, newest first, with the exception's first line pulled out —
     * the full stack trace is what makes `queue:failed` unreadable in a terminal.
     */
    private function failedJobs(): array
    {
        return DB::table('failed_jobs')
            ->orderByDesc('failed_at')
            ->limit(50)
            ->get()
            ->map(function ($row) {
                $payload = json_decode($row->payload, true) ?: [];

                return [
                    'id'        => $row->id,
                    'uuid'      => $row->uuid,
                    'queue'     => $row->queue,
                    'name'      => class_basename($payload['displayName'] ?? 'Unknown'),
                    'failed_at' => $row->failed_at,
                    'reason'    => Str::limit(strtok((string) $row->exception, "\n"), 200),
                ];
            })
            ->all();
    }

    /**
     * AI content sessions that stopped short and can be picked up again.
     *
     * Re-dispatching skips whatever already generated, so resuming costs only the
     * SKUs that never finished rather than paying Gemini for the whole list twice.
     */
    private function resumableSessions(): array
    {
        return AiContentSession::whereIn('status', ['failed', 'processing', 'pending'])
            ->latest()
            ->limit(20)
            ->get()
            ->map(fn ($s) => [
                'id'        => $s->id,
                'status'    => $s->status,
                'processed' => $s->processed_items,
                'total'     => $s->total_items,
                'error'     => Str::limit((string) $s->error_message, 160),
                'updated'   => optional($s->updated_at)->diffForHumans(),
            ])
            ->all();
    }

    /**
     * Tell every worker to finish its current job and exit, so the supervisor
     * starts fresh ones. This is the deploy step that is easy to forget and
     * silently keeps old code running.
     */
    public function restartWorkers(): RedirectResponse
    {
        Artisan::call('queue:restart');
        Log::info('Queue dashboard: workers signalled to restart', ['by' => auth()->id()]);

        return back()->with('success', 'Workers signalled to restart. They finish the job in hand first, so give them a few seconds — then check the ages below have reset.');
    }

    public function retryFailed(Request $request): RedirectResponse
    {
        $uuid = $request->input('uuid');

        Artisan::call('queue:retry', ['id' => $uuid ? [$uuid] : ['all']]);
        Log::info('Queue dashboard: failed job(s) retried', ['uuid' => $uuid ?: 'all', 'by' => auth()->id()]);

        return back()->with('success', $uuid ? 'Job pushed back onto its queue.' : 'All failed jobs pushed back onto their queues.');
    }

    public function forgetFailed(Request $request): RedirectResponse
    {
        if ($uuid = $request->input('uuid')) {
            Artisan::call('queue:forget', ['id' => $uuid]);
            $message = 'Failed job deleted.';
        } else {
            Artisan::call('queue:flush');
            $message = 'Failed job list cleared.';
        }

        Log::info('Queue dashboard: failed job(s) deleted', ['uuid' => $request->input('uuid') ?: 'all', 'by' => auth()->id()]);

        return back()->with('success', $message);
    }

    /**
     * Start an AI content session again. Guarded against double-dispatching a
     * session that is genuinely still working — the usual reason a session looks
     * stuck is a worker that died, but a slow product looks identical from here,
     * and two jobs on one session would generate everything twice.
     */
    public function resumeSession(AiContentSession $aiContentSession): RedirectResponse
    {
        if ($aiContentSession->status === 'processing' && $aiContentSession->updated_at?->gt(now()->subMinutes(5))) {
            return back()->with('error', 'That session moved in the last five minutes, so it is still running. Resume it only once it has genuinely stopped.');
        }

        GenerateAiContentJob::dispatch($aiContentSession->id)->onQueue('bulkupload');
        Log::info('Queue dashboard: AI content session resumed', ['session' => $aiContentSession->id, 'by' => auth()->id()]);

        return back()->with('success', "Session #{$aiContentSession->id} queued. Finished SKUs are skipped, so it picks up where it stopped.");
    }
}
