<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('upload_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->text('onedrive_link');
            $table->string('image_size')->default('medium'); // thumbnail|small|medium|large
            $table->string('status')->default('pending'); // pending|processing|completed|failed
            $table->unsignedInteger('total_files')->default(0);
            $table->unsignedInteger('matched_files')->default(0);
            $table->unsignedInteger('uploaded_files')->default(0);
            $table->unsignedInteger('failed_files')->default(0);
            $table->unsignedInteger('skipped_files')->default(0);
            $table->text('error_message')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('upload_sessions');
    }
};
