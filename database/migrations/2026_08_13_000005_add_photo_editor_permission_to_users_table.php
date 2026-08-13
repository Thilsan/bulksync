<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Off by default: every edit spends Photoroom credit, so access is
            // granted deliberately rather than inherited by everyone at once.
            $table->boolean('perm_photo_editor')->default(false)->after('perm_product_request');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('perm_photo_editor');
        });
    }
};
