<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Each website's catalogue as of the last nightly read. Needed because a
        // product that never sold never appears in an order, so the "no sales"
        // list can only be found by starting from here.
        Schema::create('store_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('product_id', 32);
            $table->string('title', 512);
            $table->string('handle')->nullable();
            $table->string('vendor')->nullable();
            $table->string('product_type')->nullable();
            $table->string('status', 20);
            $table->integer('total_inventory')->nullable();
            $table->string('sku')->nullable();
            $table->text('image_url')->nullable();
            $table->timestamp('shopify_created_at')->nullable();
            $table->timestamps();

            $table->unique(['store_id', 'product_id']);
            $table->index(['store_id', 'status']);
        });

        // One row per product per day it sold. No timestamps: the table is the
        // one in this feature that grows, so every column has to earn its place.
        Schema::create('product_sales_daily', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('product_id', 32);
            $table->date('date');
            $table->unsignedInteger('units');
            $table->decimal('revenue', 14, 2);

            $table->unique(['store_id', 'date', 'product_id']);
            $table->index(['store_id', 'product_id']);
        });

        Schema::table('stores', function (Blueprint $table) {
            $table->timestamp('sales_synced_at')->nullable();
            $table->date('sales_covered_from')->nullable();
            $table->string('sales_currency', 8)->nullable();
            $table->text('sales_sync_error')->nullable();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('perm_product_performance')->default(false)->after('perm_seo_audit');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('perm_product_performance');
        });

        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn(['sales_synced_at', 'sales_covered_from', 'sales_currency', 'sales_sync_error']);
        });

        Schema::dropIfExists('product_sales_daily');
        Schema::dropIfExists('store_products');
    }
};
