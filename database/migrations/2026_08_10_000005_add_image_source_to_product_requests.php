<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One question instead of two contradictory ones.
 *
 * "Supplier images available?" and "Photoshoot required?" were separate booleans
 * encoding a single fact — where the images come from — and four combinations,
 * one of which was nonsense ("no supplier images and no photoshoot"). It also
 * had no way to express the real third case: pulling images off the brand's own
 * website, which needs collecting and editing but no shoot.
 *
 * The two booleans are kept in step on save so existing logic and historic rows
 * stay valid; image_source is the one that decides the workflow.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_requests', function (Blueprint $table) {
            $table->string('image_source', 20)->nullable()->after('supplier_images_available');
        });

        // Backfill from what the old booleans were trying to say.
        DB::table('product_requests')->where('photoshoot_required', true)
            ->update(['image_source' => 'photoshoot']);

        DB::table('product_requests')->whereNull('image_source')
            ->where('supplier_images_available', true)
            ->update(['image_source' => 'supplier']);

        // Whatever is left was the nonsense combination: neither supplier images
        // nor a shoot. Brand website is the closest real meaning.
        DB::table('product_requests')->whereNull('image_source')
            ->update(['image_source' => 'brand_website']);
    }

    public function down(): void
    {
        Schema::table('product_requests', function (Blueprint $table) {
            $table->dropColumn('image_source');
        });
    }
};
