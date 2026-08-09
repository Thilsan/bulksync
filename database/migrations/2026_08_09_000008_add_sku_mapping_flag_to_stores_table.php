<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Only some websites have their SKUs mapped in Cegid by Supply Chain — Blue
 * Salon does, the others don't. Requests for a website that doesn't go through
 * mapping skip the "Waiting for Mapping" stage entirely rather than parking
 * there waiting for a team that has nothing to do.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->boolean('requires_sku_mapping')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn('requires_sku_mapping');
        });
    }
};
