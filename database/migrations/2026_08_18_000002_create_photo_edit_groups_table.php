<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per SKU folder in a run.
 *
 * A session used to carry a single set of edits for everything it found, which
 * only holds while a folder is one kind of thing. A run that mixes dresses,
 * watches and caps needs different treatment per SKU — a watch face wants no
 * padding and a dress wants plenty — so the settings move down here and the
 * session's own edits become the starting point each group is created with.
 *
 * Lifestyle imagery is counted per group too: how many on-model shots this SKU
 * should get, and which of its photos the model should be dressed from.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('photo_edit_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('photo_edit_session_id')->constrained()->cascadeOnDelete();

            // Matches photo_edit_items.sku_detected — the normalised folder name.
            $table->string('sku');

            $table->json('edits');

            /*
             * On-model images are generated, not photographed, so each one is
             * its own Photoroom call. Capped low deliberately: this multiplies
             * the bill in a way plain cutouts do not.
             */
            $table->unsignedTinyInteger('lifestyle_count')->default(0);

            // Which photo the model is dressed from. Null until one is picked.
            $table->unsignedBigInteger('lifestyle_source_item_id')->nullable();

            $table->timestamps();

            $table->unique(['photo_edit_session_id', 'sku']);
        });

        Schema::table('photo_edit_items', function (Blueprint $table) {
            /*
             * 'cutout' is a real photo edited; 'lifestyle' is an image Photoroom
             * invented from one. They live in the same table because review,
             * selection and pushing to Shopify are identical for both — but they
             * must never be confused when counting what a run cost or when
             * deciding what is safe to call a product photo.
             */
            $table->string('kind', 12)->default('cutout')->after('filename');

            // For a lifestyle row, the photo the model was dressed from.
            $table->unsignedBigInteger('source_item_id')->nullable()->after('kind');

            $table->index(['photo_edit_session_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::table('photo_edit_items', function (Blueprint $table) {
            $table->dropIndex(['photo_edit_session_id', 'kind']);
            $table->dropColumn(['kind', 'source_item_id']);
        });

        Schema::dropIfExists('photo_edit_groups');
    }
};
