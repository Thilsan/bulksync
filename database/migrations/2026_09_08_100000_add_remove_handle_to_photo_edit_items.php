<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Photos where the raised trolley handle should come off.
     *
     * A suitcase shot with the handle extended is half handle: on the sample it
     * ran from 10% to 47% down the frame, so framing the product to the
     * catalogue standard sized the case against a chrome pole. Cropping it away
     * leaves the case to fill the canvas as every other product does.
     *
     * Per photo rather than per SKU, because a single case is often shot both
     * ways — handle up for the hero, handle down for the detail — and the two
     * want different answers.
     */
    public function up(): void
    {
        Schema::table('photo_edit_items', function (Blueprint $table) {
            $table->boolean('remove_handle')->default(false)->after('keep_background');
        });
    }

    public function down(): void
    {
        Schema::table('photo_edit_items', function (Blueprint $table) {
            $table->dropColumn('remove_handle');
        });
    }
};
