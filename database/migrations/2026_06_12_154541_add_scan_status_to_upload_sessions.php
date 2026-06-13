<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('upload_sessions', function (Blueprint $table) {
            // Separates "scanning OneDrive folder" from "uploading images"
            $table->string('scan_status')->default('pending')->after('status'); // pending|scanning|scanned|failed
            $table->unsignedBigInteger('scanned_files')->default(0)->after('total_files');
        });
    }

    public function down(): void
    {
        Schema::table('upload_sessions', function (Blueprint $table) {
            $table->dropColumn(['scan_status', 'scanned_files']);
        });
    }
};
