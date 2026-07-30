<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('upload_sessions', function (Blueprint $table) {
            $table->string('matching_mode')->default('sku_barcode')->after('duplicate_handling');
        });
    }

    public function down(): void
    {
        Schema::table('upload_sessions', function (Blueprint $table) {
            $table->dropColumn('matching_mode');
        });
    }
};
