<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which service edited this photograph: 'photoroom' or 'openai'.
 *
 * Photoroom's allowance is counted from these rows, and a photo OpenAI edited
 * spent no Photoroom credit. Without this column the allowance report would
 * charge it to Photoroom anyway. Null on rows written before it existed, which
 * were all Photoroom.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('photo_edit_items', function (Blueprint $table) {
            $table->string('edit_engine', 20)->nullable()->after('photoroom_requests');
        });
    }

    public function down(): void
    {
        Schema::table('photo_edit_items', function (Blueprint $table) {
            $table->dropColumn('edit_engine');
        });
    }
};
