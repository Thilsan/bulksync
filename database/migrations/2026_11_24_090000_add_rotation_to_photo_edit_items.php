<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The turn one photograph needs, when it is not the run's.
 *
 * The first screen already asks "are the photos lying on their side?" and
 * applies one answer to everything, with a tickbox to spare the shots that
 * are already upright. That is the right question for a folder shot in one
 * go, and the wrong one for a folder where two views of the same SKU came off
 * the camera differently — there was no way to say that at all.
 *
 * Null rather than a default of none, so the column can tell "this photo was
 * turned by hand" apart from "nobody touched this photo". Only the first
 * overrides the run, and a photo left alone keeps following the run's answer
 * the way it always did.
 *
 * The value is a direction rather than degrees because that is what
 * ImageProcessingService::rotate already takes, and INPUT_ROTATIONS already
 * lists the four of them. Degrees here would mean a translation table in the
 * job whose only job is to undo this choice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('photo_edit_items', function (Blueprint $table) {
            $table->string('rotation', 8)->nullable()->after('back_view');
        });
    }

    public function down(): void
    {
        Schema::table('photo_edit_items', function (Blueprint $table) {
            $table->dropColumn('rotation');
        });
    }
};
