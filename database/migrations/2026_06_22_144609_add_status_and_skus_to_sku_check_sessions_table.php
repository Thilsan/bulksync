<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('sku_check_sessions', function (Blueprint $table) {
            $table->enum('status', ['pending', 'running', 'completed', 'failed'])->default('pending')->after('store_id');
            $table->unsignedInteger('scanned_skus')->default(0)->after('not_available_count');
            $table->longText('raw_skus')->nullable()->after('scanned_skus');
            $table->text('error_message')->nullable()->after('raw_skus');
        });
    }

    public function down(): void
    {
        Schema::table('sku_check_sessions', function (Blueprint $table) {
            $table->dropColumn(['status', 'scanned_skus', 'raw_skus', 'error_message']);
        });
    }
};
