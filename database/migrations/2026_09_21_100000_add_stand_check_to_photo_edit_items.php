<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether a mannequin or stand is still visible in the finished image,
 * checked against the delivered result rather than trusted from the
 * classification that ran before any editing happened.
 *
 * Two real failures on the same batch showed why the classification alone
 * is not enough. A front-view gown, plainly on a full dress form in the
 * original photo, was classified as having no mannequin visible at all —
 * nothing downstream ever tried to remove one, and the finished image
 * shipped with the entire stand still in it. A back view of the same SKU
 * did trigger removal and mostly succeeded, but left a pale sliver of the
 * mannequin's shoulder still showing above the neckline — invisible to the
 * garment-identity check already run on it, which only asks "is this the
 * same garment," never "is any of the stand still there."
 *
 * Null means not checked (every category outside apparel, or an item
 * edited before this existed). True means a stand was seen, or could not
 * be confirmed absent — the same "cannot explain it, so it counts as a
 * refusal" rule the rest of this app's verification already follows.
 * False means confirmed clean.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('photo_edit_items', function (Blueprint $table) {
            $table->boolean('stand_visible_after_edit')->nullable()->default(null)->after('mannequin_visible');
        });
    }

    public function down(): void
    {
        Schema::table('photo_edit_items', function (Blueprint $table) {
            $table->dropColumn('stand_visible_after_edit');
        });
    }
};
