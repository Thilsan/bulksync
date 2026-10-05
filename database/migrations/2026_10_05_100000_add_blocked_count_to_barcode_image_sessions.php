<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How many barcodes on a run went unanswered because a site refused this
 * server part way through.
 *
 * The check before a run only catches a site that blocks the homepage. One
 * that lets the homepage through and blocks the search — or starts blocking
 * after the first few dozen requests — finished as "Completed" with nothing
 * found, which reads as a bad list rather than a blocked server.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('barcode_image_sessions', function (Blueprint $table) {
            $table->unsignedInteger('blocked_count')->default(0)->after('missing_count');
        });
    }

    public function down(): void
    {
        Schema::table('barcode_image_sessions', function (Blueprint $table) {
            $table->dropColumn('blocked_count');
        });
    }
};
