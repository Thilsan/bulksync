<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('perm_bulk_upload')->default(true)->after('is_active');
            $table->boolean('perm_sku_checker')->default(true)->after('perm_bulk_upload');
            $table->boolean('perm_image_audit')->default(true)->after('perm_sku_checker');
            $table->boolean('perm_store_sync')->default(true)->after('perm_image_audit');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['perm_bulk_upload', 'perm_sku_checker', 'perm_image_audit', 'perm_store_sync']);
        });
    }
};
