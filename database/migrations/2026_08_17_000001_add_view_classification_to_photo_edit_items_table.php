<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('photo_edit_items', function (Blueprint $table) {
            // Null until EditPhotoItemJob classifies the item (or forever null
            // for sessions that never asked for a generative apparel mode).
            $table->string('view_type', 20)->nullable()->after('status');
            $table->boolean('mannequin_visible')->nullable()->after('view_type');

            // What was actually sent to Photoroom for this item — lets the
            // review screen show when a photo was auto-downgraded to a plain
            // cutout because it wasn't a front/flat-lay view.
            $table->string('apparel_mode_applied', 20)->nullable()->after('mannequin_visible');
        });
    }

    public function down(): void
    {
        Schema::table('photo_edit_items', function (Blueprint $table) {
            $table->dropColumn(['view_type', 'mannequin_visible', 'apparel_mode_applied']);
        });
    }
};
