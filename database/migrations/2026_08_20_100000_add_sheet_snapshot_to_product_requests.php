<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the tracking sheet said about this request the last time it was read.
 *
 * Without it, keeping a request in step with the sheet is a guess: if the brand
 * on the sheet and the brand on the request differ, there is no way to tell
 * whether the sheet was edited or the request was. Holding the last-known sheet
 * value makes that answerable — the sheet's change is applied, a person's change
 * is left alone and reported as a disagreement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_requests', function (Blueprint $table) {
            $table->json('sheet_snapshot')->nullable()->after('sheet_requested_by');
        });
    }

    public function down(): void
    {
        Schema::table('product_requests', function (Blueprint $table) {
            $table->dropColumn('sheet_snapshot');
        });
    }
};
