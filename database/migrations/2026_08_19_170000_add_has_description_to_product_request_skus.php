<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether the live Shopify product already has copy on it.
 *
 * The SKU check reads descriptionHtml back from Shopify anyway — it was being
 * thrown away. Keeping it is what lets the request offer AI content for only the
 * SKUs that need it, instead of regenerating over descriptions somebody wrote.
 *
 * Null means not checked yet, which is not the same as "has none".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_request_skus', function (Blueprint $table) {
            $table->boolean('has_description')->nullable()->after('shopify_published');
        });
    }

    public function down(): void
    {
        Schema::table('product_request_skus', function (Blueprint $table) {
            $table->dropColumn('has_description');
        });
    }
};
