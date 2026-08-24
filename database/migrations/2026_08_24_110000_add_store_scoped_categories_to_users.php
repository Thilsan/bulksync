<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ownership of a category on one website only.
 *
 * Watches on Blue Salon and Watches on PG are two different jobs done by two
 * different people, but "Categories Handled" could only say "Watches" — so
 * whoever was given it took both.
 *
 * Stored as "<store id>|<category>", and consulted before the plain category
 * list. Empty for everybody by default, so nothing changes until it is used.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('pcr_store_categories')->nullable()->after('pcr_owned_brands');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('pcr_store_categories');
        });
    }
};
