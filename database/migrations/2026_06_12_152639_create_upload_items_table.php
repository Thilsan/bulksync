<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('upload_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('upload_session_id')->constrained()->cascadeOnDelete();
            $table->string('filename');
            $table->string('sku_detected')->nullable();
            $table->string('product_id')->nullable();
            $table->string('product_title')->nullable();
            $table->string('variant_id')->nullable();
            $table->string('variant_sku')->nullable();
            $table->string('shopify_image_id')->nullable();
            $table->text('onedrive_download_url')->nullable();
            $table->string('status')->default('pending'); // pending|processing|matched|uploaded|skipped|failed
            $table->text('error_message')->nullable();
            $table->unsignedInteger('original_size_kb')->default(0);
            $table->unsignedInteger('processed_size_kb')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('upload_items');
    }
};
