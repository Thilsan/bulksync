<?php

namespace App\Support;

use App\Models\JobRun;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Writes a row for every job a worker picks up, so the queue has a history.
 *
 * Nothing here may throw. These hooks run inside the worker around real work; an
 * exception escaping the bookkeeping would fail the job it is only supposed to
 * be describing. Every method therefore swallows its own errors and logs them.
 */
class JobRunRecorder
{
    /**
     * Started times are kept in memory rather than re-read from the database on
     * completion: one worker handles one job at a time, so a small map keyed by
     * job id is enough, and it saves a query on the hot path.
     *
     * @var array<string, array{id: int, at: float}>
     */
    private static array $inFlight = [];

    public static function starting(JobProcessing $event): void
    {
        try {
            $run = JobRun::create([
                'job_uuid'   => $event->job->uuid(),
                'name'       => class_basename($event->job->resolveName()),
                'queue'      => $event->job->getQueue(),
                'connection' => $event->connectionName,
                'status'     => 'running',
                'attempt'    => $event->job->attempts(),
                'started_at' => now(),
            ]);

            self::$inFlight[$event->job->getJobId()] = ['id' => $run->id, 'at' => microtime(true)];
        } catch (\Throwable $e) {
            Log::warning('JobRunRecorder could not record a start', ['error' => $e->getMessage()]);
        }
    }

    public static function finished(JobProcessed $event): void
    {
        self::close($event->job->getJobId(), 'completed', null);
    }

    public static function failed(JobFailed $event): void
    {
        self::close($event->job->getJobId(), 'failed', $event->exception?->getMessage());
    }

    private static function close(string|int|null $jobId, string $status, ?string $error): void
    {
        try {
            $tracked = self::$inFlight[$jobId] ?? null;

            if (!$tracked) {
                return;
            }

            unset(self::$inFlight[$jobId]);

            JobRun::whereKey($tracked['id'])->update([
                'status'      => $status,
                'finished_at' => now(),
                'duration_ms' => (int) round((microtime(true) - $tracked['at']) * 1000),
                // The first lines are the useful part; a whole stack trace per
                // row would dominate the table.
                'exception'   => $error ? Str::limit($error, 2000) : null,
                'updated_at'  => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('JobRunRecorder could not record an ending', ['error' => $e->getMessage()]);
        }
    }
}
