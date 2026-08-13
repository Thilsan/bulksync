<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('photo_edit_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('photo_edit_session_id')->constrained()->cascadeOnDelete();

            $table->string('filename');
            $table->string('sku_detected')->nullable();

            // Where the file came from. The download URL is a pre-auth link that
            // expires, so the drive/item pair is what makes a retry possible.
            $table->string('onedrive_drive_id')->nullable();
            $table->string('onedrive_item_id')->nullable();
            $table->text('onedrive_download_url')->nullable();

            /*
             * Three files per item, and deliberately not four: a small "before"
             * thumbnail for the comparison, a small "after" thumbnail so a grid
             * of 300 loads without pulling 300 full-size images, and the
             * full-size edit itself. The full-size copy is deleted the moment it
             * reaches Shopify — it is the only one big enough to matter, and
             * once Shopify has it, ours is a second copy of the same bytes.
             */
            $table->string('original_thumb_path')->nullable();
            $table->string('edited_thumb_path')->nullable();
            $table->string('edited_path')->nullable();

            $table->unsignedInteger('original_size_kb')->default(0);
            $table->unsignedInteger('edited_size_kb')->default(0);

            // pending → editing → edited → pushing → pushed
            //                   ↘ failed / skipped
            $table->string('status', 20)->default('pending');

            // Which edits the person ticked for pushing. Everything that edits
            // cleanly starts selected, so "push all" is one click.
            $table->boolean('selected')->default(true);

            $table->string('product_id')->nullable();
            $table->string('product_title')->nullable();
            $table->string('variant_id')->nullable();
            $table->string('variant_sku')->nullable();
            $table->string('shopify_image_id')->nullable();

            $table->text('error_message')->nullable();

            $table->timestamps();

            $table->index(['photo_edit_session_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('photo_edit_items');
    }
};
