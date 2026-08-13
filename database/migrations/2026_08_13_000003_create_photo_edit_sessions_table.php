<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('photo_edit_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('store_id')->nullable();

            $table->string('name');
            $table->text('onedrive_link');

            // Same two modes the bulk uploader matches by, so a folder laid out
            // for one feature works unchanged in the other.
            $table->string('matching_mode', 20)->default('sku_barcode');

            // The chosen Photoroom operations, as the normalised option array
            // PhotoroomService::buildFields() expects.
            $table->json('edits');

            $table->string('status', 20)->default('pending');
            $table->string('scan_status', 20)->default('pending');

            $table->unsignedInteger('total_files')->default(0);
            $table->unsignedInteger('scanned_files')->default(0);
            $table->unsignedInteger('edited_files')->default(0);
            $table->unsignedInteger('failed_files')->default(0);
            $table->unsignedInteger('pushed_files')->default(0);

            $table->text('error_message')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('photo_edit_sessions');
    }
};
