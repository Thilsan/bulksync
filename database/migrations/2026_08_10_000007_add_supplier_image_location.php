<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Saying "the supplier has sent the images" left everyone guessing where they
 * actually are — the editor and the content team had to ask. Record it on the
 * request: either a link to the folder, or that they are already in the PIM.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_requests', function (Blueprint $table) {
            // 'url' | 'pim' — only meaningful when the supplier sent the images.
            $table->string('images_location', 10)->nullable()->after('image_source');
            $table->string('images_url', 2048)->nullable()->after('images_location');
        });
    }

    public function down(): void
    {
        Schema::table('product_requests', function (Blueprint $table) {
            $table->dropColumn(['images_location', 'images_url']);
        });
    }
};
