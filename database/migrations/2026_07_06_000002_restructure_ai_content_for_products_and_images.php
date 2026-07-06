<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_content_sessions', function (Blueprint $table) {
            $table->text('skus_json')->nullable()->after('sku_raw');
        });

        Schema::table('ai_content_items', function (Blueprint $table) {
            $table->text('all_skus')->nullable()->after('sku');
            $table->dropColumn('ai_alt_text');
        });

        Schema::create('ai_content_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained('ai_content_items')->cascadeOnDelete();
            $table->string('shopify_image_id')->nullable();
            $table->text('image_url')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->string('ai_alt_text', 255)->nullable();
            $table->string('status')->default('pending'); // pending, done, failed, pushed
            $table->text('error_message')->nullable();
            $table->timestamps();
            $table->index(['item_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_content_images');

        Schema::table('ai_content_items', function (Blueprint $table) {
            $table->string('ai_alt_text', 255)->nullable();
            $table->dropColumn('all_skus');
        });

        Schema::table('ai_content_sessions', function (Blueprint $table) {
            $table->dropColumn('skus_json');
        });
    }
};
