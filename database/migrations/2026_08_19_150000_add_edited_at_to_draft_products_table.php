<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marks a draft the team has corrected by hand.
 *
 * Rebuilding from the sheet throws away and re-reads everything, which is what
 * "rebuild" should mean — otherwise a change to how products are built leaves
 * the old ones sitting alongside the new, and the same SKU appears twice. But a
 * draft somebody has fixed up is not the builder's to discard, so it is stamped
 * here and kept.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_request_draft_products', function (Blueprint $table) {
            $table->timestamp('edited_at')->nullable()->after('push_error');
        });
    }

    public function down(): void
    {
        Schema::table('product_request_draft_products', function (Blueprint $table) {
            $table->dropColumn('edited_at');
        });
    }
};
