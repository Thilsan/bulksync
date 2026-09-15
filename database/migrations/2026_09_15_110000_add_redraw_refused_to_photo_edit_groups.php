<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Has a redraw already been tried on this SKU and thrown away?
 *
 * A refused redraw costs a credit and produces nothing: the image that gets
 * published is the plain cutout bought afterwards, so the SKU pays twice per
 * photo. That is tolerable once, as the price of finding out. It is not
 * tolerable ten times on one folder, and a folder is ten photographs of the
 * same garment on the same stand — whatever the redraw did to the first is what
 * it will do to the rest.
 *
 * So the first refusal is remembered and the rest of the SKU goes straight to
 * the cutout. Recorded on the group rather than the session because a run holds
 * several products and they fail independently.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('photo_edit_groups', function (Blueprint $table) {
            $table->boolean('redraw_refused')->default(false)->after('case_height_cm');
        });
    }

    public function down(): void
    {
        Schema::table('photo_edit_groups', function (Blueprint $table) {
            $table->dropColumn('redraw_refused');
        });
    }
};
