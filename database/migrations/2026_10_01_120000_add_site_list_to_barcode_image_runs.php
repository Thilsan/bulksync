<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A run is given several websites to try rather than one.
 *
 * Nobody knows which catalogue carries a given article, and a site that is
 * blocked or that stocks nothing turns a whole list into empty rows. The run
 * now walks a list in order and keeps the first site that answers with
 * pictures, so each barcode records where its images actually came from.
 *
 * site_url stays as it was — the first site on the list — so every existing
 * run, screen and push keeps working unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('barcode_image_sessions', function (Blueprint $table) {
            $table->json('site_urls')->nullable()->after('site_url');
        });

        Schema::table('barcode_image_items', function (Blueprint $table) {
            $table->string('source_site')->nullable()->after('product_url');
        });
    }

    public function down(): void
    {
        Schema::table('barcode_image_sessions', function (Blueprint $table) {
            $table->dropColumn('site_urls');
        });

        Schema::table('barcode_image_items', function (Blueprint $table) {
            $table->dropColumn('source_site');
        });
    }
};
