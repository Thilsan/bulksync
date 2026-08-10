<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One launch moment instead of two dates.
 *
 * Everything in this module launches online, so a separate store date was noise
 * — and a date alone could not say whether something goes live first thing or
 * after a midday price change. The online launch becomes a datetime.
 *
 * store_launch_date is left in place rather than dropped: existing requests hold
 * real values, and losing them would be a one-way trip. It is simply no longer
 * asked for or shown.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_requests', function (Blueprint $table) {
            $table->dateTime('online_launch_date')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('product_requests', function (Blueprint $table) {
            $table->date('online_launch_date')->nullable()->change();
        });
    }
};
