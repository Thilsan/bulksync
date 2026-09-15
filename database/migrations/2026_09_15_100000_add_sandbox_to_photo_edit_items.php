<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Was this image produced by a sandbox key?
 *
 * A sandbox key returns a watermarked image that has not really been edited —
 * the background is still there, the mannequin is still standing in it, and
 * "Photoroom" is written across the whole frame. Nothing downstream could tell:
 * the status read ready, the badge claimed the mannequin had been segmented
 * out, and the push would have put it on a live product page.
 *
 * Recorded per item rather than read from the key at push time, because the two
 * happen on different days. An image edited on the sandbox key does not become
 * safe to publish because somebody has since switched the key over — it is
 * still the watermarked one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('photo_edit_items', function (Blueprint $table) {
            $table->boolean('sandbox')->default(false)->after('apparel_mode_applied');
        });
    }

    public function down(): void
    {
        Schema::table('photo_edit_items', function (Blueprint $table) {
            $table->dropColumn('sandbox');
        });
    }
};
