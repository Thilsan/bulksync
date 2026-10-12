<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A second way into the photo editor: images already on Shopify.
 *
 * The editor has always read OneDrive — a folder of shoot files named by SKU.
 * This adds the other direction, for products whose photographs are already
 * live and want redoing: paste the SKUs, fetch each product's first image,
 * edit it, and put the edited version back in the same gallery slot.
 *
 * Three columns, and the OneDrive route is untouched:
 *
 *   sessions.source       which way this run came in, so a scan, a download
 *                         and a push can each tell without guessing from
 *                         whether onedrive_link happens to be filled
 *   items.source_url      where to fetch the picture from. OneDrive items
 *                         keep using their drive and item ids, which are not
 *                         a URL and expire differently
 *   items.replaces_image_id
 *                         the Shopify image this one is replacing. The push
 *                         already deletes a previous image when re-pushing;
 *                         this gives it the same thing to do on a first push,
 *                         without pretending the row has already been pushed
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('photo_edit_sessions', function (Blueprint $table) {
            $table->string('source', 20)->default('onedrive')->after('onedrive_link');
        });

        Schema::table('photo_edit_items', function (Blueprint $table) {
            $table->text('source_url')->nullable()->after('onedrive_download_url');
            $table->string('replaces_image_id')->nullable()->after('shopify_image_id');
        });
    }

    public function down(): void
    {
        Schema::table('photo_edit_sessions', fn (Blueprint $t) => $t->dropColumn('source'));
        Schema::table('photo_edit_items', fn (Blueprint $t) => $t->dropColumn(['source_url', 'replaces_image_id']));
    }
};
