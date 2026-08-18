<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Photoroom says how sure it was of every cutout it returns, in a response
 * header, at no extra cost. Keeping it turns reviewing a batch from looking
 * at all of them into looking at the handful it flagged.
 *
 * Nullable rather than defaulted: the API returns -1 for photos it cannot
 * score at all, and "no opinion" must not be stored as "completely confident".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('photo_edit_items', function (Blueprint $table) {
            $table->float('uncertainty_score')->nullable()->after('apparel_mode_applied');
        });
    }

    public function down(): void
    {
        Schema::table('photo_edit_items', function (Blueprint $table) {
            $table->dropColumn('uncertainty_score');
        });
    }
};
