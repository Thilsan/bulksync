<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether copy has been written, or deliberately not written, for each SKU.
 *
 * A decision recorded on the request as a whole cannot answer the case this
 * exists for: 28 of 30 SKUs are mapped, content is generated for those 28, and
 * later the last 2 are mapped too. Those 2 need copy — but the request has
 * already "decided", so it would never be offered again.
 *
 * Tracked per SKU, the question answers itself: anything live, without a
 * description, that has not been started or skipped is what still needs copy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_request_skus', function (Blueprint $table) {
            $table->timestamp('content_started_at')->nullable()->after('has_description');
            $table->timestamp('content_skipped_at')->nullable()->after('content_started_at');
        });
    }

    public function down(): void
    {
        Schema::table('product_request_skus', function (Blueprint $table) {
            $table->dropColumn(['content_started_at', 'content_skipped_at']);
        });
    }
};
