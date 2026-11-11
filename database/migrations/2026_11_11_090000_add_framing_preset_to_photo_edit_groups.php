<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The category one SKU is framed to, when it is not the run's.
 *
 * A column of its own rather than one of the group's edits, for the same
 * reason the case height is one: a group that follows the run stores no edits
 * at all, and a folder arrives holding several categories at once — shirts,
 * trousers and shorts photographed in one session. Putting the category inside
 * the edits would mean opting the SKU out of the run's settings entirely just
 * to say it is a pair of shorts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('photo_edit_groups', function (Blueprint $table) {
            $table->string('framing_preset')->nullable()->after('case_height_cm');
        });
    }

    public function down(): void
    {
        Schema::table('photo_edit_groups', function (Blueprint $table) {
            $table->dropColumn('framing_preset');
        });
    }
};
