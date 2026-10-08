<?php

namespace Tests\Feature;

use App\Console\PooledWorkCommand;
use App\Support\Queues;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class QueuePoolTest extends TestCase
{
    public function test_queue_work_is_the_pooled_command(): void
    {
        // Cloudways starts workers with `artisan queue:work --queue=<one name>`;
        // if this stops resolving, every worker silently goes back to its own queue.
        $this->assertInstanceOf(PooledWorkCommand::class, Artisan::all()['queue:work']);
    }

    public function test_each_worker_takes_its_own_queue_first_then_helps(): void
    {
        $this->assertSame('bulkupload,skucheck,aicontent,photos', Queues::listenOrder(Queues::SHARED));
        $this->assertSame('skucheck,aicontent,photos', Queues::listenOrder(Queues::SKU_CHECK));
        $this->assertSame('aicontent,skucheck,photos', Queues::listenOrder(Queues::AI));
        $this->assertSame('photos,skucheck,aicontent', Queues::listenOrder(Queues::PHOTOS));
    }

    public function test_maintenance_and_explicit_lists_are_left_alone(): void
    {
        $this->assertSame('maintenance', Queues::listenOrder(Queues::MAINTENANCE));
        $this->assertSame('photos,bulkupload', Queues::listenOrder('photos,bulkupload'));
    }

    public function test_dedicated_workers_never_take_the_long_shared_jobs(): void
    {
        foreach ([Queues::SKU_CHECK, Queues::AI, Queues::PHOTOS] as $queue) {
            $this->assertNotContains(Queues::SHARED, Queues::HELPS[$queue], "{$queue} would wait behind an audit");
        }
    }
}
