<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What happened to each website on a run, site by site.
 *
 * A run that quietly dropped a blocked site explained itself in a sentence at
 * the bottom of the screen, which is where nobody looks when the table in
 * front of them says "not found" forty-one times. Keeping the outcome per site
 * lets the site itself be marked as blocked where it is named.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('barcode_image_sessions', function (Blueprint $table) {
            $table->json('site_issues')->nullable()->after('site_urls');
        });
    }

    public function down(): void
    {
        Schema::table('barcode_image_sessions', function (Blueprint $table) {
            $table->dropColumn('site_issues');
        });
    }
};
