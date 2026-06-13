<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('upload_items', function (Blueprint $table) {
            // Store OneDrive drive + item IDs so we can re-fetch the file
            // at processing time (pre-signed download URLs expire in minutes)
            $table->string('onedrive_drive_id')->nullable()->after('onedrive_download_url');
            $table->string('onedrive_item_id')->nullable()->after('onedrive_drive_id');

            // Indexes for fast status queries on large sets (30k rows)
            $table->index(['upload_session_id', 'status']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::table('upload_items', function (Blueprint $table) {
            $table->dropIndex(['upload_session_id', 'status']);
            $table->dropIndex(['status']);
            $table->dropColumn(['onedrive_drive_id', 'onedrive_item_id']);
        });
    }
};
