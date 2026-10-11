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
        $this->assertSame('bulkupload,skucheck,aicontent', Queues::listenOrder(Queues::SHARED));
        $this->assertSame('skucheck,aicontent', Queues::listenOrder(Queues::SKU_CHECK));
        $this->assertSame('aicontent,skucheck', Queues::listenOrder(Queues::AI));
    }

    /*
     * The photo editor shares the upload queue rather than holding one of its
     * own.
     *
     * It had its own for a while. The server has under two gigabytes of memory
     * and was already into swap, and a worker parked on a queue that is empty
     * most of the day costs the same memory as one doing work — so those
     * processes went where the work is. When nothing is uploading, every
     * bulkupload worker is free for photographs.
     *
     * The cost is that bulkupload also carries image and SEO audits, which run
     * long, so an edit can queue behind one. Returning to a dedicated queue
     * means adding the Cloudways worker first and pointing PHOTOS back at its
     * own name — the other way round leaves photographs on Pending with
     * nothing listening.
     */
    public function test_the_photo_editor_shares_the_upload_queue(): void
    {
        $this->assertSame(Queues::SHARED, Queues::PHOTOS);
        $this->assertNotContains('photos', Queues::WORKERS, 'a worker is configured for a queue nothing uses');
    }

    public function test_maintenance_and_explicit_lists_are_left_alone(): void
    {
        $this->assertSame('maintenance', Queues::listenOrder(Queues::MAINTENANCE));
        $this->assertSame('skucheck,bulkupload', Queues::listenOrder('skucheck,bulkupload'));
    }

    public function test_dedicated_workers_never_take_the_long_shared_jobs(): void
    {
        foreach ([Queues::SKU_CHECK, Queues::AI] as $queue) {
            $this->assertNotContains(Queues::SHARED, Queues::HELPS[$queue], "{$queue} would wait behind an audit");
        }
    }
}
