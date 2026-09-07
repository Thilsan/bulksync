<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A second photograph of the pendant, for the SKUs that have one.
     *
     * A necklace listing is mostly chain: the thing a customer is buying is the
     * pendant, and at catalogue framing it occupies a fiftieth of the picture.
     * This asks for a close-up of it alongside the full shot — same SKU, second
     * image, pushed and downloaded like any other.
     *
     * Per SKU rather than per run, because it costs a second credit and only
     * some necklaces have anything worth showing close up. A plain chain or a
     * uniform strand of pearls has no pendant, and the edit declines to make
     * one rather than producing a photograph of nothing.
     */
    public function up(): void
    {
        Schema::table('photo_edit_groups', function (Blueprint $table) {
            $table->boolean('pendant_closeup')->default(false)->after('lifestyle_source_item_id');
        });
    }

    public function down(): void
    {
        Schema::table('photo_edit_groups', function (Blueprint $table) {
            $table->dropColumn('pendant_closeup');
        });
    }
};
