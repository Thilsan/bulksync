<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Each product category is handled by one person. Recording that here — rather
 * than in code — means the requester never has to know who covers what, and a
 * handover is a change of tick boxes instead of a deploy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('pcr_categories')->nullable()->after('pcr_role');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('pcr_categories');
        });
    }
};
