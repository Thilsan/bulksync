<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which extra camera angles a SKU wants generated from one of its photographs.
 *
 * Photoroom's Edit With AI takes a prompt naming a view — "show the product
 * from the side" — and their own documentation says it "generates new images
 * rather than rotating existing ones". So an angle is a drawing of the product
 * as the model imagines that side to be, not a photograph of it, and the
 * further from the shot view the more of it is invented.
 *
 * Stored per SKU rather than per run because it is a judgement about the
 * product: a plain shoe can carry a generated side view, a branded one with a
 * logo on the heel cannot, and that is not a decision anyone can make for a
 * whole folder at once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('photo_edit_groups', function (Blueprint $table) {
            $table->json('angles')->nullable()->after('lifestyle_source_item_id');
            $table->unsignedBigInteger('angle_source_item_id')->nullable()->after('angles');
        });
    }

    public function down(): void
    {
        Schema::table('photo_edit_groups', function (Blueprint $table) {
            $table->dropColumn(['angles', 'angle_source_item_id']);
        });
    }
};
