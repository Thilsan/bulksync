<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What this photograph actually cost, in Photoroom requests.
 *
 * It could not be worked out from anything already stored. The mode says which
 * route was taken, and most routes are one request — but a redraw that is
 * refused and falls back to a cutout is two, and an ironing pass that shifts
 * the colour too far is retried without it, which is another, and nothing in
 * the mode name says so.
 *
 * So the job counts its own calls and files the number here. Zero on rows
 * written before this existed, and on the "as is" photos that never reach
 * Photoroom at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('photo_edit_items', function (Blueprint $table) {
            $table->unsignedSmallInteger('photoroom_requests')->default(0)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('photo_edit_items', function (Blueprint $table) {
            $table->dropColumn('photoroom_requests');
        });
    }
};
