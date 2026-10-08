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

    /** Every queue a worker is listening to. */
    public const WORKERS = [
        self::SHARED,
        self::SKU_CHECK,
        self::PHOTOS,
        self::AI,
        self::MAINTENANCE,
    ];
}
