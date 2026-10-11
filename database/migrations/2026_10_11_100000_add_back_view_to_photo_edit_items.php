<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether this photograph is the back of the garment.
 *
 * Ghost Mannequin turns back views round. A pink slip shot from behind —
 * straight neckline, wide straps over the shoulders — came back with a V-neck
 * and lace at the bust, on a prompt that says to keep the same orientation.
 * Naming the view in the prompt is the one guidance Photoroom suggested for a
 * specific drift, and nothing in the system knew which way round a photo was.
 *
 * Gemini used to classify this and was removed for getting it wrong: one
 * dress's two views came back labelled front and back on one run and front and
 * front on the next. So this is set from the filename where the photographer
 * says so, and ticked by hand where they don't — both of which are things a
 * person decided, rather than a second model's opinion.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('photo_edit_items', function (Blueprint $table) {
            $table->boolean('back_view')->default(false)->after('skip_edit');
        });
    }

    public function down(): void
    {
        Schema::table('photo_edit_items', function (Blueprint $table) {
            $table->dropColumn('back_view');
        });
    }
};
