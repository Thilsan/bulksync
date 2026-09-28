<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('barcode_image_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name')->nullable();
            $table->string('site_url');
            $table->string('status')->default('pending');
            $table->unsignedInteger('total_barcodes')->default(0);
            $table->unsignedInteger('processed')->default(0);
            $table->unsignedInteger('found_count')->default(0);
            $table->unsignedInteger('missing_count')->default(0);
            $table->unsignedInteger('images_downloaded')->default(0);

            // Held only until the job reads them, then cleared — a list of
            // twenty thousand barcodes has no reason to sit in the row for ever.
            $table->longText('raw_barcodes')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
        });

        Schema::create('barcode_image_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('barcode_image_session_id')->constrained()->cascadeOnDelete();
            $table->string('barcode');
            $table->string('status')->default('pending'); // pending|found|not_found|failed
            $table->string('product_url', 2048)->nullable();
            $table->string('product_title')->nullable();
            $table->unsignedInteger('image_count')->default(0);
            $table->text('message')->nullable();
            $table->timestamps();

            $table->index(['barcode_image_session_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('barcode_image_items');
        Schema::dropIfExists('barcode_image_sessions');
    }
};
