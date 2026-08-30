<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Photos that go to Shopify untouched.
     *
     * A folder is rarely all one thing: six shots need a cutout and four are
     * already right and only need uploading. Sending those four through
     * Photoroom anyway costs four credits to change nothing, so they are
     * marked here and skip the API entirely.
     */
    public function up(): void
    {
        Schema::table('photo_edit_items', function (Blueprint $table) {
            $table->boolean('skip_edit')->default(false)->after('position');
        });
    }

    public function down(): void
    {
        Schema::table('photo_edit_items', function (Blueprint $table) {
            $table->dropColumn('skip_edit');
        });
    }
};
