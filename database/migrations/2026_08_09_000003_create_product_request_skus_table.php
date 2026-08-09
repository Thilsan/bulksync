<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_request_skus', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_request_id')->constrained()->cascadeOnDelete();
            $table->string('sku')->index();

            // mapped | pending | not_mapped
            $table->string('mapping_status', 20)->default('pending')->index();

            $table->boolean('in_cegid')->nullable();   // null = Cegid lookup unavailable / not answered yet
            $table->boolean('in_shopify')->default(false);

            $table->string('shopify_product_id')->nullable();
            $table->string('shopify_product_title')->nullable();
            $table->boolean('shopify_published')->nullable();

            $table->timestamp('last_checked_at')->nullable();
            $table->timestamps();

            $table->unique(['product_request_id', 'sku']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_request_skus');
    }
};
