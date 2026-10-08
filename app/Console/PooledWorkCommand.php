<?php

namespace App\Console;

use App\Support\Queues;
use Illuminate\Queue\Console\WorkCommand;

/**
 * queue:work, except a worker started on a single queue also listens to the
 * queues it helps with (Queues::HELPS). `ps` still shows the one name Cloudways
 * was given; run it without --quiet to see the full list it listens to.
 */
class PooledWorkCommand extends WorkCommand
{
    protected function getQueue($connection)
    {
        $queue = Queues::listenOrder(parent::getQueue($connection));

        $this->components->info("Listening to: {$queue}");

        return $queue;
    }
}
