<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The Shopify price and stock of each SKU, as the SKU check last read
     * them — so a product with no price or no stock shows on the request.
     */
    public function up(): void
    {
        Schema::table('product_request_skus', function (Blueprint $table) {
            if (!Schema::hasColumn('product_request_skus', 'shopify_price')) {
                $table->decimal('shopify_price', 12, 2)->nullable();
            }
            if (!Schema::hasColumn('product_request_skus', 'shopify_stock')) {
                $table->integer('shopify_stock')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('product_request_skus', function (Blueprint $table) {
            $table->dropColumn(['shopify_price', 'shopify_stock']);
        });
    }
};
