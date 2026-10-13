<?php

namespace App\Jobs\Concerns;

/**
 * Lets a long job work for a couple of minutes at a time instead of hours.
 *
 * A job that walks two thousand SKUs in one go holds a worker for the whole
 * walk, and with a handful of workers shared by fifteen people that is a
 * colleague's one-SKU check sitting on Pending for an afternoon. Instead, the
 * job works until its slice is up, then queues the rest of the run behind
 * whatever arrived meanwhile and lets go. Everyone's work takes turns.
 *
 * The run stays serial: each slice dispatches the next only when it is done,
 * so there is never more than one slice of a run in flight. That matters for
 * the jobs that read somebody else's website, where fanning out would look
 * like an attack.
 *
 * How long a slice lasts is config('queue.slice_seconds'). Tests set it to 0,
 * which hands over after every item and so exercises every boundary.
 */
trait RunsInSlices
{
    private ?float $sliceStartedAt = null;

    protected function startSlice(): void
    {
        $this->sliceStartedAt = microtime(true);
    }

    protected function sliceIsUp(): bool
    {
        return microtime(true) - ($this->sliceStartedAt ?? microtime(true)) >= (int) config('queue.slice_seconds', 120);
    }

    /** Queue the rest of the run on the same connection and queue as this slice. */
    protected function handOver(self $next): void
    {
        dispatch($next->onConnection($this->connection)->onQueue($this->queue));
    }
}
