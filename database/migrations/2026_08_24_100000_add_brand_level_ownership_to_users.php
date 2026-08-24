<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ownership at brand level, not just category.
 *
 * A category is usually the right unit — one person handles Lingerie end to end.
 * But not always: Cole Haan sits in Leather Goods and is somebody else's brand,
 * and until now the only way to say so was to hand over the whole category.
 *
 * These override the category settings for the brands named in them, and are
 * empty for everybody by default — so nothing changes until somebody uses them.
 *
 * Brands are stored uppercased and trimmed, because the tracking sheet writes
 * "COLE HAAN ", "Cole Haan" and "RAGO " for the same brands.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('pcr_owned_brands')->nullable()->after('pcr_categories');
            $table->json('pcr_managed_brands')->nullable()->after('pcr_brand_categories');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['pcr_owned_brands', 'pcr_managed_brands']);
        });
    }
};
