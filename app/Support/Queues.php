<?php

namespace App\Support;

/**
 * Which queue each feature's jobs go on. Every queue in WORKERS has its own
 * worker in Cloudways → Application Settings → Supervisord Jobs.
 *
 * Everything used to share 'bulkupload', so one person's photo-editor run held
 * a colleague's one-SKU check on Pending until it finished. The SKU checker
 * and AI content have workers of their own; uploads, audits, product requests
 * and the photo editor share 'bulkupload' to keep the worker count down.
 *
 * The photo editor had its own queue for a while and gave it back. The server
 * it runs on has under two gigabytes of memory and was already into swap, and
 * a worker parked on a queue that is empty most of the day costs the same
 * memory as one doing work. Sharing puts those processes where the work is:
 * when nothing is uploading, every one of them is free for photographs.
 *
 * What that costs is the thing the split was for. 'bulkupload' also carries
 * image and SEO audits, which run long, so a photo edit can now queue behind
 * one. The staggered release in PhotoEditorController keeps the ready list
 * short, which limits the wait to whatever is already running rather than a
 * whole batch — but it is a real trade, and the way back is to add the worker
 * in Cloudways first and then point PHOTOS at its own name again.
 *
 * To give a shared feature its own worker later: add the worker in Cloudways
 * first, then point its constant at the new name and add it to WORKERS. The
 * other way round leaves its jobs on Pending with nothing listening.
 */
final class Queues
{
    public const SHARED      = 'bulkupload';
    public const SKU_CHECK   = 'skucheck';
    public const AI          = 'aicontent';
    public const MAINTENANCE = 'maintenance';

    public const UPLOADS          = self::SHARED;
    public const AUDITS           = self::SHARED;
    public const PRODUCT_REQUESTS = self::SHARED;
    public const PHOTOS           = self::SHARED;

    /**
     * What each worker picks up once its own queue is empty, in order.
     *
     * Cloudways' Queue field only accepts one lowercase name, so a worker can't
     * be given "skucheck,aicontent,photos" there. The worker command expands
     * the single name instead (see PooledWorkCommand). With fifteen people on
     * the system, the second person running a SKU check is served by whichever
     * worker is idle, not queued behind the first.
     *
     * The dedicated workers don't help with SHARED: it carries image and SEO
     * audits that run long, and a skucheck worker stuck on one would leave a
     * colleague's check waiting — the thing the split exists to prevent. A
     * worker checks its own queue again before every job, so helping only ever
     * delays its own feature by one job.
     */
    public const HELPS = [
        self::SHARED    => [self::SKU_CHECK, self::AI],
        self::SKU_CHECK => [self::AI],
        self::AI        => [self::SKU_CHECK],
    ];

    /**
     * The queue list a worker started on $queue should listen to. A list given
     * explicitly ("a,b") is left alone, so a hand-run worker still does exactly
     * what it was told.
     */
    public static function listenOrder(string $queue): string
    {
        if (str_contains($queue, ',') || !isset(self::HELPS[$queue])) {
            return $queue;
        }

        return implode(',', [$queue, ...self::HELPS[$queue]]);
    }

    /** Every queue a worker is listening to. */
    public const WORKERS = [
        self::SHARED,
        self::SKU_CHECK,
        self::AI,
        self::MAINTENANCE,
    ];
}
