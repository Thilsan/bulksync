<?php

namespace App\Support;

/**
 * One queue per feature, each with its own worker in Cloudways → Application
 * Settings → Supervisord Jobs.
 *
 * Everything used to share 'bulkupload', so one person's 300-photo edit run
 * held a colleague's one-SKU check or AI content batch on Pending until it
 * finished. With a worker per queue, features run side by side; people using
 * the same feature still take turns on that feature's worker.
 *
 * A queue listed here with no worker listening means its jobs sit on Pending
 * forever — add the worker before deploying a new name.
 */
final class Queues
{
    public const SKU_CHECK        = 'skucheck';
    public const UPLOADS          = 'uploads';
    public const PHOTOS           = 'photos';
    public const AI               = 'ai';
    public const AUDITS           = 'audits';
    public const PRODUCT_REQUESTS = 'productrequests';
    public const MAINTENANCE      = 'maintenance';

    /** Before the split. Kept on the dashboard while its backlog drains. */
    public const LEGACY = 'bulkupload';

    /** Every queue a worker should be listening to. */
    public const ALL = [
        self::SKU_CHECK,
        self::UPLOADS,
        self::PHOTOS,
        self::AI,
        self::AUDITS,
        self::PRODUCT_REQUESTS,
        self::MAINTENANCE,
    ];
}
