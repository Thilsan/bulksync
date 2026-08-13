<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The identifier read from the FILENAME, kept alongside the one read from
     * the folder name so matching can try both.
     *
     * A folder per SKU and a flat folder of SKU-named files are both normal
     * ways to hand over photos, but the scan could only honour one at a time:
     * the folder name always won, so images named after their SKU inside a
     * folder named anything else ("Lancome Aug") all resolved to that folder
     * and came back No Match.
     */
    public function up(): void
    {
        Schema::table('upload_items', function (Blueprint $table) {
            $table->string('filename_sku')->nullable()->after('sku_detected');
        });
    }

    public function down(): void
    {
        Schema::table('upload_items', function (Blueprint $table) {
            $table->dropColumn('filename_sku');
        });
    }
};
