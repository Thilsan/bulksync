<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Following a category on one website only, for the brand side.
 *
 * "Brand Manager / Brand Coordinator" could only say "Leather Goods", so anyone
 * given it was emailed about Leather Goods on every website we run — Samsonite
 * requests landing on somebody who only handles Blue Salon.
 *
 * Stored as "<store id>|<category>", the same shape as pcr_store_categories, and
 * consulted before the plain category list. Empty for everybody by default, so
 * nothing changes until it is used.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('pcr_brand_store_categories')->nullable()->after('pcr_store_categories');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('pcr_brand_store_categories');
        });
    }
};
