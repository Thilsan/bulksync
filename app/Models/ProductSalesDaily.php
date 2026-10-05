<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Units and revenue for one product on one website on one day, in that
 * website's own timezone and currency.
 */
class ProductSalesDaily extends Model
{
    protected $table = 'product_sales_daily';

    public $timestamps = false;

    protected $fillable = ['store_id', 'product_id', 'date', 'units', 'revenue'];

    protected $casts = [
        'date'    => 'date',
        'units'   => 'integer',
        'revenue' => 'float',
    ];

    /**
     * How far back rows are kept. A little over a year, so the longest range
     * the page offers is always fully covered, and no further: this is the one
     * table in the feature that grows every day, and the disk has filled twice.
     */
    public const KEEP_DAYS = 400;

    /** Deletes rows past KEEP_DAYS in chunks, so no run holds a long lock. Returns rows removed. */
    public static function prune(): int
    {
        $cutoff = now()->subDays(self::KEEP_DAYS)->toDateString();
        $total  = 0;

        do {
            $ids = DB::table('product_sales_daily')->where('date', '<', $cutoff)->limit(5000)->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            $total += DB::table('product_sales_daily')->whereIn('id', $ids)->delete();
        } while (true);

        return $total;
    }
}
