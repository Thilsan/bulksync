<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether the brand manager has been asked for images.
 *
 * Only meaningful once a photoshoot has been ruled out: no shoot means the images
 * come from somewhere, and that is either the brand manager sending them or the
 * team already having them. Two different situations, and the difference decides
 * whether an email goes out and whether the request is still waiting on anything.
 *
 * yes | no, and null until asked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_requests', function (Blueprint $table) {
            $table->string('image_request_decision', 3)->nullable()->after('photoshoot_decided_at');
            $table->timestamp('image_requested_at')->nullable()->after('image_request_decision');
        });
    }

    public function down(): void
    {
        Schema::table('product_requests', function (Blueprint $table) {
            $table->dropColumn(['image_request_decision', 'image_requested_at']);
        });
    }
};
