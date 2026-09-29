<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When Shopify last actually answered for this request.
 *
 * in_shopify = false is ambiguous on its own: the SKU may genuinely not be
 * there, or the lookup may have failed and been swallowed so one API hiccup
 * could not fail a whole sync. Anything deciding a request is not really
 * published has to tell those apart, so this is stamped only when the
 * catalogue was truly read.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_requests', function (Blueprint $table) {
            $table->timestamp('shopify_verified_at')->nullable()->after('validated_at');
        });
    }

    public function down(): void
    {
        Schema::table('product_requests', function (Blueprint $table) {
            $table->dropColumn('shopify_verified_at');
        });
    }
};
