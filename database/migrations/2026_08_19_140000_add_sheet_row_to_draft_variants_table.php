<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Keeps the whole sheet row behind each staged variant.
 *
 * The builder maps the columns it recognises onto Shopify fields, but the
 * category tabs carry more than that and differ tab to tab. Storing the entire
 * row means nothing is silently dropped: whatever could not be mapped is still
 * on the review screen for the team to read, and a column we learn about later
 * can be mapped without re-reading SharePoint.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_request_draft_variants', function (Blueprint $table) {
            $table->json('sheet_row')->nullable()->after('image_src');
        });
    }

    public function down(): void
    {
        Schema::table('product_request_draft_variants', function (Blueprint $table) {
            $table->dropColumn('sheet_row');
        });
    }
};
