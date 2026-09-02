<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Photos that want the framing but not the cutout.
     *
     * A SKU's three shots are not always the same job. Two are the product on
     * white and want cutting out; the third is a detail or a lifestyle frame
     * whose background is the point, and erasing it would throw away what the
     * photographer went there for — but it still has to sit on the same canvas
     * at the same size as its siblings or the gallery looks ragged.
     *
     * Distinct from skip_edit, which is "send it up as it is" and spends no
     * credit at all. This one still goes to Photoroom, still costs a credit,
     * and still gets the padding, dimensions and alignment its group asked
     * for — only the background removal is switched off.
     */
    public function up(): void
    {
        Schema::table('photo_edit_items', function (Blueprint $table) {
            $table->boolean('keep_background')->default(false)->after('skip_edit');
        });
    }

    public function down(): void
    {
        Schema::table('photo_edit_items', function (Blueprint $table) {
            $table->dropColumn('keep_background');
        });
    }
};
