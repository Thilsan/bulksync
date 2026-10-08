<?php

namespace App\Support;

/**
 * Which queue each feature's jobs go on. Every queue in WORKERS has its own
 * worker in Cloudways → Application Settings → Supervisord Jobs.
 *
 * Everything used to share 'bulkupload', so one person's photo-editor run held
 * a colleague's one-SKU check on Pending until it finished. The photo editor,
 * SKU checker and AI content now have workers of their own; uploads, audits
 * and product requests still share 'bulkupload' (two processes) to keep the
 * worker count down.
 *
 * To give a shared feature its own worker later: add the worker in Cloudways
 * first, then point its constant at the new name and add it to WORKERS. The
 * other way round leaves its jobs on Pending with nothing listening.
 */
final class Queues
{
    public const SHARED      = 'bulkupload';
    public const SKU_CHECK   = 'skucheck';
    public const PHOTOS      = 'photos';
    public const AI          = 'aicontent';
    public const MAINTENANCE = 'maintenance';

    public const UPLOADS          = self::SHARED;
    public const AUDITS           = self::SHARED;
    public const PRODUCT_REQUESTS = self::SHARED;

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
     * audits that run for a long time, and a photos worker stuck on one would
     * leave the photo editor waiting — the thing the split exists to prevent.
     * A worker checks its own queue again before every job, so helping only
     * ever delays its own feature by one job.
     */
    public const HELPS = [
        self::SHARED    => [self::SKU_CHECK, self::AI, self::PHOTOS],
        self::SKU_CHECK => [self::AI, self::PHOTOS],
        self::AI        => [self::SKU_CHECK, self::PHOTOS],
        self::PHOTOS    => [self::SKU_CHECK, self::AI],
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
        self::PHOTOS,
        self::AI,
        self::MAINTENANCE,
    ];
}
