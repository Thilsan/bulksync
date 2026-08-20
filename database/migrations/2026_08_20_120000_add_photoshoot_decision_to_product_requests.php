<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether anybody has decided this request needs a photoshoot.
 *
 * photoshoot_required alone cannot say it: false means both "no shoot needed" and
 * "nobody has looked yet", and the import had to guess one of them for every row.
 * Guessing "yes" put all 156 requests in the Photoshoot Room, which is how a
 * queue stops meaning anything.
 *
 * yes | no, and null until somebody answers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_requests', function (Blueprint $table) {
            $table->string('photoshoot_decision', 3)->nullable()->after('photoshoot_status');
            $table->timestamp('photoshoot_decided_at')->nullable()->after('photoshoot_decision');
        });
    }

    public function down(): void
    {
        Schema::table('product_requests', function (Blueprint $table) {
            $table->dropColumn(['photoshoot_decision', 'photoshoot_decided_at']);
        });
    }
};
