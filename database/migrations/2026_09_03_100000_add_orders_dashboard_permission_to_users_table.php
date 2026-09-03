<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The orders dashboard shows company-wide revenue across every storefront, so
 * it is off by default and handed out deliberately — unlike the tool
 * permissions above it, which only ever expose the holder's own work.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('perm_orders_dashboard')->default(false)->after('perm_photo_editor');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('perm_orders_dashboard');
        });
    }
};
