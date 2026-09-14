<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How tall this SKU's suitcase is, in centimetres.
 *
 * A column of its own rather than a key in the group's edits, because a group
 * only stores edits when the operator says its settings differ from the run's —
 * otherwise it holds null and follows the run. That is right for settings and
 * wrong for this: a luggage run arrives with cabin, medium and large mixed
 * together under one set of settings, and the size is a fact about the product,
 * not a preference about the edit. Kept in edits it would be dropped from every
 * SKU that did not also opt out of the run's settings — which is most of them.
 *
 * pendant_closeup is here for the same reason and sits next to it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('photo_edit_groups', function (Blueprint $table) {
            $table->unsignedSmallInteger('case_height_cm')->nullable()->after('pendant_closeup');
        });
    }

    public function down(): void
    {
        Schema::table('photo_edit_groups', function (Blueprint $table) {
            $table->dropColumn('case_height_cm');
        });
    }
};
