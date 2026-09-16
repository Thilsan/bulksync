<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shopify knows what a website sold but nothing about who visited it, so
 * sessions and visitors come from Google Analytics instead. Which property to
 * ask is a per-website fact that changes as websites are added, so it belongs
 * on the store beside its Shopify credentials rather than in config.
 *
 * Kept as a string: it is an identifier that is only ever compared and passed
 * on, never counted with, and nothing is gained by making it an integer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->string('ga4_property_id')->nullable()->after('shopify_access_token');
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn('ga4_property_id');
        });
    }
};
