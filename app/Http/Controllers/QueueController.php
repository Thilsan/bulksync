<?php

namespace App\Http\Controllers;

use App\Models\JobRun;
use App\Support\SessionResumer;
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
            'failed'      => $this->failedJobs(),
            'failedTotal' => DB::table('failed_jobs')->count(),
            'sessions' => $this->resumableSessions(),
            'running'  => $this->runningJobs(),
            'today'    => $this->todaysTally(),
            'driver'   => config('queue.default'),
        ];
    }

    /**
     * Jobs a worker is holding right now.
     *
     * Queue depth counts what is waiting, which says nothing about whether
     * anything is actually moving — the question behind every "is it stuck?".
     * A row is only trusted while it is young: a killed worker never writes its
     * own ending, so an old "running" row is a ghost, not live work.
     */
    private function runningJobs(): array
    {
        return JobRun::running()
            ->where('started_at', '>=', now()->subHours(JobRun::LOST_AFTER_HOURS))
            ->orderBy('started_at')
            ->limit(50)
            ->get()
            ->map(fn (JobRun $r) => [
                'id'      => $r->id,
                'name'    => $r->name,
                'queue'   => $r->queue,
                'attempt' => $r->attempt,
                'for'     => $r->started_at?->diffForHumans(null, true),
                'seconds' => (int) ($r->started_at?->diffInSeconds(now()) ?? 0),
            ])
            ->all();
    }

    /** Headline counts for the last 24 hours, so the page opens on an answer. */
    private function todaysTally(): array
    {
        $since = now()->subDay();

        $rows = JobRun::where('started_at', '>=', $since)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return [
            'completed' => (int) ($rows['completed'] ?? 0),
            'failed'    => (int) ($rows['failed'] ?? 0),
            'lost'      => (int) ($rows['lost'] ?? 0),
        ];
    }

    /**
     * Everything the workers have run, newest first, filterable by queue, status
     * and job name.
     */
    public function history(Request $request): JsonResponse
    {
        $runs = JobRun::query()
            ->when($request->filled('queue'), fn ($q) => $q->where('queue', $request->input('queue')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('name'), fn ($q) => $q->where('name', 'like', '%' . $request->input('name') . '%'))
            ->orderByDesc('started_at')
            ->paginate(50)
            ->withQueryString();

        return response()->json([
            'rows'  => collect($runs->items())->map(fn (JobRun $r) => [
                'id'        => $r->id,
                'name'      => $r->name,
                'queue'     => $r->queue,
                'status'    => $r->status,
                'attempt'   => $r->attempt,
                'started'   => $r->started_at?->format('d M H:i:s'),
                'duration'  => $r->humanDuration(),
                'exception' => $r->exception,
            ])->all(),
            'page'  => $runs->currentPage(),
            'pages' => $runs->lastPage(),
            'total' => $runs->total(),
        ]);
    }

    /**
     * The tail of the application log, filtered to queue and job activity.
     *
     * Reading this off the server meant grepping an 18 MB file through a shell
     * that mangles pasted commands — and Laravel pretty-prints JSON bodies across
     * many physical lines, so a line-based grep splits every error in half.
     * Entries are reassembled here on the timestamp that starts each one.
     */
    public function logs(Request $request): JsonResponse
    {
        // Asked of the logging config rather than hardcoded, so this follows the
        // app's own log location — and so a test can point it somewhere harmless
        // instead of writing over the real one.
        $path = config('logging.channels.single.path') ?: storage_path('logs/laravel.log');

        if (!is_readable($path)) {
            return response()->json(['entries' => [], 'error' => 'No readable log at ' . $path]);
        }

        // Only the tail: the file runs to tens of megabytes and the whole point
        // is recent activity.
        $bytes  = 512 * 1024;
        $size   = filesize($path);
        $handle = fopen($path, 'rb');
        fseek($handle, max(0, $size - $bytes));
        $chunk = (string) fread($handle, $bytes);
        fclose($handle);

        $needle  = trim((string) $request->input('q', ''));
        $entries = [];
        $current = null;

        foreach (explode("\n", $chunk) as $line) {
            // A new entry starts with its timestamp; anything else is a
            // continuation of the one before it.
            if (preg_match('/^\[(\d{4}-\d{2}-\d{2} [\d:]+)\] (\w+)\.(\w+): (.*)$/', $line, $m)) {
                if ($current) {
                    $entries[] = $current;
                }

                $current = ['time' => $m[1], 'level' => strtolower($m[3]), 'message' => $m[4]];
            } elseif ($current !== null) {
                $current['message'] .= ' ' . trim($line);
            }
        }

        if ($current) {
            $entries[] = $current;
        }

        // The first entry is usually a fragment, since the read starts mid-file.
        array_shift($entries);

        $entries = array_filter($entries, function ($e) use ($needle) {
            if ($needle !== '') {
                return stripos($e['message'], $needle) !== false;
            }

            // Default view: queue and job activity, plus anything that went wrong.
            return in_array($e['level'], ['error', 'warning'], true)
                || preg_match('/job|queue|session|gemini|shopify/i', $e['message']);
        });

        $entries = array_map(
            fn ($e) => [...$e, 'message' => Str::limit($e['message'], 400)],
            array_slice(array_values($entries), -200),
        );

        return response()->json(['entries' => array_reverse($entries), 'error' => null]);
    }

    /** The whole stack trace for one failure — the page shows only its first line. */
    public function failedDetail(string $uuid): JsonResponse
    {
        $row = DB::table('failed_jobs')->where('uuid', $uuid)->first();

        if (!$row) {
            return response()->json(['exception' => 'That failed job is no longer on the list.']);
        }

        return response()->json(['exception' => $row->exception, 'payload' => $row->payload]);
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
    /**
     * One page of failures for the live poll, plus the true total — the count is
     * a headline in its own right, and capping the list at fifty used to hide
     * how bad a bad night had been.
     */
    public function failedPage(Request $request): JsonResponse
    {
        $page = max(1, (int) $request->input('page', 1));
        $per  = 25;

        $total = DB::table('failed_jobs')->count();

        return response()->json([
            'rows'  => $this->failedJobs($per, ($page - 1) * $per),
            'page'  => $page,
            'pages' => max(1, (int) ceil($total / $per)),
            'total' => $total,
        ]);
    }

    private function failedJobs(int $limit = 25, int $offset = 0): array
    {
        return DB::table('failed_jobs')
            ->orderByDesc('failed_at')
            ->offset($offset)
            ->limit($limit)
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
     * Every kind of session that stopped short, not just AI content.
     *
     * Six things in this app run as a session over a queue and any of them can
     * strand; see SessionResumer for how each one is picked up, and for the one
     * that genuinely cannot be.
     */
    private function resumableSessions(): array
    {
        return SessionResumer::stalled();
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
    public function resumeSession(Request $request, string $type, int $id): RedirectResponse
    {
        $types = SessionResumer::types();

        if (!isset($types[$type])) {
            return back()->with('error', 'Unknown session type.');
        }

        $session = $types[$type]['model']::find($id);

        if ($session && ($session->status ?? '') === 'processing' && $session->updated_at?->gt(now()->subMinutes(5))) {
            return back()->with('error', 'That session moved in the last five minutes, so it is still running. Resume it only once it has genuinely stopped.');
        }

        try {
            $message = SessionResumer::resume($type, $id);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        Log::info('Queue dashboard: session resumed', ['type' => $type, 'session' => $id, 'by' => auth()->id()]);

        return back()->with('success', $message);
    }

    /**
     * Throw away everything waiting on one queue.
     *
     * Nothing here is recoverable, so it asks for the queue's name to be typed
     * rather than trusting a click: the whole point of reaching for this is that
     * the queue is full of work you no longer want, and the neighbouring queue
     * usually is not.
     */
    public function purge(Request $request): RedirectResponse
    {
        $queue = (string) $request->input('queue');

        if (!in_array($queue, self::QUEUES, true)) {
            return back()->with('error', 'Unknown queue.');
        }

        if ($request->input('confirm') !== $queue) {
            return back()->with('error', "Type the queue name exactly to purge it. Nothing was deleted.");
        }

        $size = Queue::size($queue);

        Artisan::call('queue:clear', ['connection' => config('queue.default'), '--queue' => $queue, '--force' => true]);
        Log::warning('Queue dashboard: queue purged', ['queue' => $queue, 'discarded' => $size, 'by' => auth()->id()]);

        return back()->with('success', "Purged {$queue} — {$size} waiting job(s) discarded.");
    }
}
