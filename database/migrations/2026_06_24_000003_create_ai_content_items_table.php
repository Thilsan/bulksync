<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_content_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_id')->constrained('ai_content_sessions')->cascadeOnDelete();
            $table->string('sku')->nullable();
            $table->string('shopify_product_id')->nullable();
            $table->string('product_title')->nullable();
            $table->text('image_url')->nullable();
            $table->text('ai_description')->nullable();
            $table->string('ai_meta_title', 100)->nullable();
            $table->string('ai_meta_description', 200)->nullable();
            $table->string('status')->default('pending'); // pending, processing, done, failed, pushed
            $table->boolean('is_confirmed')->default(false);
            $table->text('error_message')->nullable();
            $table->timestamps();
            $table->index(['session_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_content_items');
    }
};
