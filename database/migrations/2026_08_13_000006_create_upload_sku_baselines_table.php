<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per (session, SKU) recording whether that SKU ALREADY had its
     * photo on Shopify when the batch first touched it.
     *
     * The question used to be asked once per file, live against Shopify, in
     * the middle of the upload loop. With eight parallel workers the first
     * file of a folder assigns the variant image, so files 2 and 3 saw a photo
     * that had not been there when the batch began and dropped themselves as
     * "Already Has Image". Freezing the answer per SKU removes that window.
     *
     * Rows are bounded by unique SKUs per session and cascade away with the
     * session, so the table cannot outgrow the sessions it describes.
     */
    public function up(): void
    {
        Schema::create('upload_sku_baselines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('upload_session_id')->constrained()->cascadeOnDelete();

            // 'variant' for SKU/barcode matching, 'product' for style codes
            // (a style code match has no variant to hang the answer on).
            $table->string('scope', 16);
            $table->string('scope_id', 64);

            // null while the probing worker is still asking Shopify — siblings
            // wait on this rather than asking again and getting a later answer.
            $table->boolean('has_existing_image')->nullable();

            // Claimed by exactly one file per variant: the one that becomes the
            // variant's main image.
            $table->boolean('variant_image_claimed')->default(false);

            $table->timestamps();

            $table->unique(
                ['upload_session_id', 'scope', 'scope_id'],
                'upload_sku_baselines_scope_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('upload_sku_baselines');
    }
};
