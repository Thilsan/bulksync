<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every category has a brand manager as well as the person who runs the work.
 *
 * They are kept informed rather than given tasks — copied on their categories'
 * requests without holding a role — so this is deliberately separate from
 * pcr_categories, which decides who the work is assigned to. More than one
 * person can follow the same category; only one can own it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('pcr_brand_categories')->nullable()->after('pcr_categories');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('pcr_brand_categories');
        });
    }
};
