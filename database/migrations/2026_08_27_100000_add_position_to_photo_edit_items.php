<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The order photos appear in, within their SKU.
     *
     * Filename order was doing this job by accident, and only until somebody
     * wanted the front view first on a shoot named back/front/side. Zero means
     * "no opinion", which sorts before everything and falls back to filename —
     * so every run made before this column existed keeps the order it had.
     */
    public function up(): void
    {
        Schema::table('photo_edit_items', function (Blueprint $table) {
            $table->unsignedInteger('position')->default(0)->after('sku_detected');

            // Every read of this is "the photos of one SKU, in order".
            $table->index(['photo_edit_session_id', 'sku_detected', 'position'], 'photo_edit_items_sku_order_index');
        });
    }

    public function down(): void
    {
        Schema::table('photo_edit_items', function (Blueprint $table) {
            $table->dropIndex('photo_edit_items_sku_order_index');
            $table->dropColumn('position');
        });
    }
};
