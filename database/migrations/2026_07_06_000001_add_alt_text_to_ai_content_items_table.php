<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_content_items', function (Blueprint $table) {
            $table->string('shopify_image_id')->nullable()->after('image_url');
            $table->string('ai_alt_text', 255)->nullable()->after('ai_meta_description');
        });
    }

    public function down(): void
    {
        Schema::table('ai_content_items', function (Blueprint $table) {
            $table->dropColumn(['shopify_image_id', 'ai_alt_text']);
        });
    }
};
