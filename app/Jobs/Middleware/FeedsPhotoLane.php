<?php

namespace App\Jobs\Middleware;

use App\Support\PhotoLane;

/**
 * When a photo job ends, successfully or not, send its owner's next photo.
 *
 * In `finally` so a throw (including the one that asks for a retry) still frees
 * the place. A worker killed outright skips this; the scheduled
 * photo-lane:feed catches that case within a minute.
 */
class FeedsPhotoLane
{
    public function handle(object $job, callable $next): void
    {
        try {
            $next($job);
        } finally {
            PhotoLane::finished($job->itemId);
        }
    }
}
